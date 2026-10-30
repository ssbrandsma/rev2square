<?php

namespace Tests\Unit\Binary\Sandbox;

use App\Models\WorkerCapability;
use App\Services\Binary\Adapters\JadxAdapter;
use App\Services\Binary\Adapters\ObjdumpAdapter;
use App\Services\Binary\Sandbox\SpoolClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SpoolFake;
use Tests\TestCase;

/**
 * Probe routing: HTTP readers and unmounted console contexts use
 * coordinator snapshots and never submit spool jobs; only the
 * coordinator (console AND spool mounts) probes runners live.
 * runningInConsole() alone is NOT a coordinator identity check.
 */
class ProbeRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function seedSnapshot(string $version, bool $available = true): void
    {
        WorkerCapability::updateOrCreate(['adapter' => 'objdump'], [
            'available' => $available, 'version' => $version,
            'targets' => ['x86'], 'formats' => ['raw'],
            'details' => ['reason' => 'test'],
            'captured_at' => now(), 'worker' => 'test',
        ]);
    }

    /** Simulate an HTTP request context (no console SAPI). */
    private function simulateHttp(): void
    {
        $this->assertTrue(app()->runningInConsole());
        $prop = new \ReflectionProperty(app(), 'isRunningInConsole');
        $prop->setAccessible(true);
        $prop->setValue(app(), false);
        $this->assertFalse(app()->runningInConsole());
    }

    private function probeBudget(object $adapter): int
    {
        $method = new \ReflectionMethod($adapter, 'probeBudgetSeconds');
        $method->setAccessible(true);

        return $method->invoke($adapter);
    }

    public function test_console_without_spool_mounts_reads_snapshot_and_submits_nothing(): void
    {
        $bare = new class(sys_get_temp_dir().'/rev2-no-spool-'.uniqid(), sys_get_temp_dir().'/rev2-no-results-'.uniqid()) extends SpoolClient
        {
            public int $probeCalls = 0;

            public function probe(string $tool, int $timeoutSeconds = 30): ?array
            {
                $this->probeCalls++;

                return parent::probe($tool, $timeoutSeconds);
            }
        };
        app()->instance(SpoolClient::class, $bare);

        // Console, but no mounts: snapshot path, no job submitted.
        $probe = (new ObjdumpAdapter)->probe();
        $this->assertFalse($probe['available']);
        $this->assertStringContainsString('No worker capability snapshot', $probe['reason']);
        $this->assertSame('docs/analysis-spool-deployment.md', $probe['setup_doc']);
        $this->assertNull((new ObjdumpAdapter)->version());
        $this->assertSame(0, $bare->probeCalls);

        // A refreshed snapshot serves console readers without mounts.
        $this->seedSnapshot('2.40-snap');
        $probe = (new ObjdumpAdapter)->probe();
        $this->assertTrue($probe['available']);
        $this->assertStringContainsString('Worker-verified', $probe['reason']);
        $this->assertSame('2.40-snap', (new ObjdumpAdapter)->version());
        $this->assertSame(0, $bare->probeCalls);
    }

    public function test_http_with_spool_mounts_reads_snapshot_and_submits_nothing(): void
    {
        $live = new class extends SpoolFake
        {
            public int $probeCalls = 0;

            public function probe(string $tool, int $timeoutSeconds = 30): ?array
            {
                $this->probeCalls++;

                return parent::probe($tool, $timeoutSeconds);
            }
        };
        // Mounts present (fake dirs are real) with a live answer that
        // DIFFERS from the snapshot: routing is proven by which wins.
        $live->probes['objdump'] = SpoolFake::answer('objdump', '9.99-live');
        app()->instance(SpoolClient::class, $live);
        $this->seedSnapshot('2.40-snap');

        $this->simulateHttp();

        $probe = (new ObjdumpAdapter)->probe();
        $this->assertTrue($probe['available']);
        $this->assertStringContainsString('Worker-verified', $probe['reason']);
        $this->assertSame('2.40-snap', (new ObjdumpAdapter)->version());
        $this->assertSame(0, $live->probeCalls);
    }

    public function test_console_with_spool_mounts_probes_runner_live(): void
    {
        $live = new class extends SpoolFake
        {
            public int $probeCalls = 0;

            public function probe(string $tool, int $timeoutSeconds = 30): ?array
            {
                $this->probeCalls++;

                return parent::probe($tool, $timeoutSeconds);
            }
        };
        $live->probes['objdump'] = SpoolFake::answer('objdump', '9.99-live');
        app()->instance(SpoolClient::class, $live);
        $this->seedSnapshot('2.40-snap');

        // Coordinator: console AND mounts — the live answer wins over
        // the (different) snapshot, with exactly one spool submission
        // memoized across probe() and version().
        $adapter = new ObjdumpAdapter;
        $probe = $adapter->probe();
        $this->assertTrue($probe['available']);
        $this->assertStringContainsString('9.99-live', $probe['reason']);
        $this->assertSame('9.99-live', $adapter->version());
        $this->assertSame(1, $live->probeCalls);

        $adapter->probe();
        $this->assertSame(1, $live->probeCalls);
    }

    public function test_has_spool_mounts_detects_coordinator_by_dirs(): void
    {
        $method = new \ReflectionMethod(ObjdumpAdapter::class, 'hasSpoolMounts');
        $method->setAccessible(true);

        app()->instance(SpoolClient::class, new SpoolClient('/definitely/missing/spool', '/definitely/missing/results'));
        $this->assertFalse($method->invoke(new ObjdumpAdapter));

        app()->instance(SpoolClient::class, new SpoolFake);
        $this->assertTrue($method->invoke(new ObjdumpAdapter));
    }

    public function test_probe_budgets_cover_fixture_qualification(): void
    {
        // Steady-state probes are fast version pings: the shared
        // default stays small so dead runners fail fast.
        $base = $this->probeBudget(new ObjdumpAdapter);
        $this->assertSame(10, $base);

        // JADX pays a bounded one-time fixture decompile on first
        // probe (runner.py FIXTURE_WALL_SECONDS per attempt, cached
        // per boot afterwards); its budget carries margin over the
        // shared default so healthy runners qualify first try.
        $jadx = $this->probeBudget(new JadxAdapter);
        $this->assertSame(30, $jadx);
        $this->assertGreaterThan($base, $jadx);
    }
}
