<?php

namespace Tests\Unit\Binary\Sandbox;

use App\Jobs\RefreshWorkerCapabilities;
use App\Models\WorkerCapability;
use App\Services\Binary\Adapters\AdapterRegistry;
use App\Services\Binary\Adapters\ExtractAdapter;
use App\Services\Binary\Adapters\GhidraAdapter;
use App\Services\Binary\Adapters\IlspyAdapter;
use App\Services\Binary\Adapters\JadxAdapter;
use App\Services\Binary\Adapters\ObjdumpAdapter;
use App\Services\Binary\Adapters\SandboxedBackend;
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

    private function runnerBound(string $name): int
    {
        $runner = file_get_contents(base_path('docker/analysis/runner.py'));
        $this->assertIsString($runner);
        if (preg_match('/^'.$name.' = (\d+)$/m', $runner, $m) !== 1) {
            $this->fail("runner.py {$name} changed shape; re-derive the budget math");
        }

        return (int) $m[1];
    }

    public function test_console_without_spool_mounts_reads_snapshot_and_submits_nothing(): void
    {
        $bare = new class(sys_get_temp_dir().'/rev2-no-spool-'.uniqid(), sys_get_temp_dir().'/rev2-no-results-'.uniqid()) extends SpoolClient
        {
            public int $probeCalls = 0;

            public function probe(string $tool, int $timeoutSeconds = 30, ?bool &$claimed = null): ?array
            {
                $this->probeCalls++;

                return parent::probe($tool, $timeoutSeconds, $claimed);
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

            public function probe(string $tool, int $timeoutSeconds = 30, ?bool &$claimed = null): ?array
            {
                $this->probeCalls++;

                return parent::probe($tool, $timeoutSeconds, $claimed);
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

            public function probe(string $tool, int $timeoutSeconds = 30, ?bool &$claimed = null): ?array
            {
                $this->probeCalls++;

                return parent::probe($tool, $timeoutSeconds, $claimed);
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

    public function test_probe_budgets_cover_complete_runner_probes(): void
    {
        // Every coordinator deadline must exceed the COMPLETE bounded
        // runner probe read live from runner.py, with margin for claim
        // latency (~1s runner poll), collection, status write, and
        // coordinator granularity. If the runner bounds change, this
        // fails until the coordinator deadlines are re-derived.
        $version = $this->runnerBound('VERSION_TIMEOUT_SECONDS');
        $fixture = $this->runnerBound('FIXTURE_WALL_SECONDS') * $this->runnerBound('FIXTURE_ATTEMPTS');

        // The shared default is the version-ping deadline: small enough
        // to fail fast, large enough to cover the version bound.
        $this->assertSame(45, (int) config('artifacts.binary_spool_probe_seconds'));

        // Version-ping tools: one version bound each.
        foreach ([new GhidraAdapter, new IlspyAdapter, new ExtractAdapter] as $adapter) {
            $budget = $adapter->probeBudgetSeconds();
            $this->assertGreaterThan($version + 1, $budget, $adapter->id());
            $this->assertGreaterThanOrEqual(10, $budget - ($version + 1), $adapter->id());
            $this->assertSame(45, $budget, $adapter->id());
        }

        // Objdump additionally runs architecture discovery (same bound).
        $objdump = (new ObjdumpAdapter)->probeBudgetSeconds();
        $this->assertGreaterThan(2 * $version + 1, $objdump);
        $this->assertGreaterThanOrEqual(10, $objdump - (2 * $version + 1));
        $this->assertSame(75, $objdump);

        // JADX pays a bounded one-time fixture decompile on first probe.
        $jadx = (new JadxAdapter)->probeBudgetSeconds();
        $this->assertGreaterThan($version + $fixture + 1, $jadx);
        $this->assertGreaterThanOrEqual(20, $jadx - ($version + $fixture + 1));
        $this->assertSame(150, $jadx);
    }

    public function test_pending_probe_is_not_stored_as_broken(): void
    {
        $spool = new SpoolFake;
        // Silent but claimed: the runner accepted the probe and is
        // still running first-start qualification past the budget.
        $spool->probes['jadx'] = null;
        $spool->claimed['jadx'] = true;
        app()->instance(SpoolClient::class, $spool);

        $probe = (new JadxAdapter)->probe();
        $this->assertFalse($probe['available']);
        $this->assertTrue($probe['pending'] ?? false);
        $this->assertStringContainsString('still running', $probe['reason']);
        $this->assertStringContainsString('not recorded as a broken backend', $probe['reason']);
        $this->assertNull((new JadxAdapter)->version());

        // Capture skips the store: no row, previous snapshot kept.
        $this->assertNull(WorkerCapability::capture(new JadxAdapter, 'test'));
        $this->assertDatabaseMissing('worker_capabilities', ['adapter' => 'jadx']);

        // A previous good snapshot survives a pending refresh.
        WorkerCapability::updateOrCreate(['adapter' => 'jadx'], [
            'available' => true, 'version' => '1.5.1', 'targets' => ['dalvik'],
            'formats' => ['apk'], 'details' => ['reason' => 'test'],
            'captured_at' => now(), 'worker' => 'test',
        ]);
        $this->assertNull(WorkerCapability::capture(new JadxAdapter, 'test'));
        $this->assertDatabaseHas('worker_capabilities', ['adapter' => 'jadx', 'available' => true, 'version' => '1.5.1']);
    }

    public function test_terminal_fixture_failure_is_stored_unavailable(): void
    {
        $spool = new SpoolFake;
        // The runner ANSWERED: decompile demonstrably broken. That is
        // a terminal verdict, honestly stored as unavailable.
        $spool->probes['jadx'] = SpoolFake::answer('jadx', '1.5.1', [
            'fixture' => ['name' => 'dex-smoke.dex', 'ok' => false],
        ]);
        app()->instance(SpoolClient::class, $spool);

        $probe = (new JadxAdapter)->probe();
        $this->assertFalse($probe['available']);
        $this->assertFalse($probe['pending'] ?? false);

        $row = WorkerCapability::capture(new JadxAdapter, 'test');
        $this->assertNotNull($row);
        $this->assertDatabaseHas('worker_capabilities', ['adapter' => 'jadx', 'available' => false]);
    }

    public function test_refresh_job_timeout_exceeds_probe_budget_sum(): void
    {
        // Sequential captures burn up to the full budget sum against
        // dead runners; the timeout derives from the live budgets so
        // the worker never kills a refresh mid-flight.
        $budgets = 0;
        foreach (AdapterRegistry::all() as $adapter) {
            if ($adapter instanceof SandboxedBackend) {
                $budgets += $adapter->probeBudgetSeconds();
            }
        }

        $job = new RefreshWorkerCapabilities;
        $this->assertSame(max(120, $budgets + 60), $job->timeout);
        // Above all: never the 60s worker default, which would SIGKILL
        // a refresh against slow runners.
        $this->assertGreaterThan(60, $job->timeout);
    }
}
