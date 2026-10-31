<?php

namespace Tests\Unit\Binary\Sandbox;

use App\Jobs\RefreshWorkerCapabilities;
use App\Models\WorkerCapability;
use App\Services\Binary\Adapters\ObjdumpAdapter;
use App\Services\Binary\Adapters\ToolVersion;
use App\Services\Binary\Sandbox\SpoolClient;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\SpoolFake;
use Tests\TestCase;

/**
 * Tool-version persistence: the coordinator normalizes raw runner
 * banners (notably the multiline objdump GPL notice) to a concise
 * bounded identifier before touching the 64-char columns, keeps the
 * full banner in snapshot details, and never lets one backend's
 * capture failure strand the rest of the refresh.
 *
 * Tiers: the ToolVersion unit tests are static (no DB); the
 * capture/refresh tests run under the repo's MySQL phpunit gate via
 * RefreshDatabase; one MySQL-only test proves strict mode is really
 * enforcing (it skips honestly on SQLite instead of passing
 * vacuously).
 */
class WorkerCapabilityVersionTest extends TestCase
{
    use RefreshDatabase;

    /** Production objdump 2.40 banner (Binutils for Debian). */
    public const OBJDUMP_BANNER = "GNU objdump (GNU Binutils for Debian) 2.40\n"
        ."Copyright (C) 2024 Free Software Foundation, Inc.\n"
        .'This program is free software; you may redistribute it under the terms of'."\n"
        .'the GNU General Public License version 3 or (at your option) any later version.'."\n"
        .'This program has absolutely no warranty.';

    public function test_identifier_condenses_objdump_banner(): void
    {
        $id = ToolVersion::identifier(self::OBJDUMP_BANNER);

        $this->assertSame('GNU objdump (GNU Binutils for Debian) 2.40', $id);
        $this->assertStringContainsString('GNU objdump', $id);
        $this->assertStringContainsString('2.40', $id);
        $this->assertStringNotContainsString("\n", $id);
        $this->assertLessThanOrEqual(64, mb_strlen($id));
        // Idempotent: the persistence-boundary clamp is a no-op here.
        $this->assertSame($id, ToolVersion::identifier($id));
    }

    public function test_identifier_passes_clean_versions_through(): void
    {
        foreach (['1.5.6', '1.5.1', '2.40', '12.2', 'stub-1.0', '9.99-live', '11.2.0.10050', '1.0.0'] as $version) {
            $this->assertSame($version, ToolVersion::identifier($version), $version);
        }
    }

    public function test_identifier_bounds_and_sanitizes(): void
    {
        $long = 'tool-'.str_repeat('x', 200);
        $this->assertSame(mb_substr($long, 0, 64), ToolVersion::identifier($long));
        $this->assertSame(64, mb_strlen((string) ToolVersion::identifier($long)));

        $this->assertSame('9.99-live', ToolVersion::identifier("\n9.99-live\nsecond line"));
        $this->assertSame('padded', ToolVersion::identifier('  padded  '));
        $this->assertSame('a b c', ToolVersion::identifier("a\tb  c"));
        $this->assertSame('2.40', ToolVersion::identifier("2.40\x00\x07"));
        $this->assertSame('2.40', ToolVersion::identifier("2.4\xE2\x80\xAE0"));

        $invalid = ToolVersion::identifier("2.40\xFF\xFE");
        $this->assertTrue(mb_check_encoding((string) $invalid, 'UTF-8'));
        $this->assertStringStartsWith('2.40', (string) $invalid);

        $this->assertNull(ToolVersion::identifier(''));
        $this->assertNull(ToolVersion::identifier("  \n\t "));
        $this->assertNull(ToolVersion::identifier(null));
        $this->assertNull(ToolVersion::identifier(123));
    }

    public function test_full_preserves_bounded_banner(): void
    {
        $this->assertSame(self::OBJDUMP_BANNER, ToolVersion::full(self::OBJDUMP_BANNER));
        $this->assertStringContainsString("\n", (string) ToolVersion::full(self::OBJDUMP_BANNER));
        $this->assertSame(2000, mb_strlen((string) ToolVersion::full(str_repeat("line\n", 1000))));
        $this->assertSame("a\nb", ToolVersion::full("a\r\nb"));
        $this->assertSame('2.40', ToolVersion::full("2.40\x00"));
        $this->assertNull(ToolVersion::full(''));
        $this->assertNull(ToolVersion::full(null));
    }

    public function test_capture_persists_banner_without_overflow(): void
    {
        $spool = new SpoolFake;
        $spool->probes['objdump'] = SpoolFake::answer('objdump', self::OBJDUMP_BANNER, [
            'architectures' => ['i386'],
        ]);
        app()->instance(SpoolClient::class, $spool);

        $row = WorkerCapability::capture(new ObjdumpAdapter, 'coordinator-test');

        $this->assertNotNull($row);
        $this->assertSame('GNU objdump (GNU Binutils for Debian) 2.40', $row->version);
        $this->assertSame(self::OBJDUMP_BANNER, $row->details['tool_version_full']);
        $this->assertDatabaseHas('worker_capabilities', [
            'adapter' => 'objdump', 'available' => true,
            'version' => 'GNU objdump (GNU Binutils for Debian) 2.40',
        ]);
    }

    public function test_capture_clamps_overlong_version_and_worker(): void
    {
        $spool = new SpoolFake;
        $spool->probes['objdump'] = SpoolFake::answer('objdump', 'tool-'.str_repeat('x', 300));
        app()->instance(SpoolClient::class, $spool);

        $row = WorkerCapability::capture(new ObjdumpAdapter, 'worker-'.str_repeat('y', 300));

        $this->assertNotNull($row);
        $this->assertSame(64, mb_strlen((string) $row->version));
        $this->assertSame(128, mb_strlen((string) $row->worker));
    }

    public function test_refresh_continues_after_backend_failure(): void
    {
        Log::spy();
        WorkerCapability::updateOrCreate(['adapter' => 'objdump'], [
            'available' => false, 'version' => 'old-snapshot', 'targets' => [],
            'formats' => [], 'details' => ['reason' => 'previous'],
            'captured_at' => now()->subDay(), 'worker' => 'test',
        ]);
        $spool = new class extends SpoolFake
        {
            public function probe(string $tool, int $timeoutSeconds = 30, ?bool &$claimed = null): ?array
            {
                if ($tool === 'objdump') {
                    throw new \RuntimeException('simulated probe crash');
                }

                return parent::probe($tool, $timeoutSeconds, $claimed);
            }
        };
        foreach (['ghidra', 'jadx', 'ilspy', 'extract'] as $tool) {
            $extra = $tool === 'jadx' ? ['fixture' => ['name' => 'dex-smoke.dex', 'ok' => true]] : [];
            $spool->probes[$tool] = SpoolFake::answer($tool, '9.9-test', $extra);
        }
        app()->instance(SpoolClient::class, $spool);

        $result = (new RefreshWorkerCapabilities)->handle();

        $this->assertSame(['objdump'], $result['failed']);
        $this->assertSame([], $result['pending']);
        // Later backends were still checked and stored.
        $this->assertDatabaseHas('worker_capabilities', ['adapter' => 'ghidra', 'version' => '9.9-test']);
        $this->assertDatabaseHas('worker_capabilities', ['adapter' => 'jadx', 'version' => '9.9-test']);
        $this->assertDatabaseHas('worker_capabilities', ['adapter' => 'extract', 'version' => '9.9-test']);
        // The failed backend keeps its previous snapshot: no false
        // ready, and no false broken verdict shadowing real state.
        $this->assertDatabaseHas('worker_capabilities', [
            'adapter' => 'objdump', 'available' => false, 'version' => 'old-snapshot',
        ]);
        Log::shouldHaveReceived('error')->once()->with(
            'Capability capture failed; previous snapshot kept.',
            \Mockery::on(fn ($context) => ($context['adapter'] ?? null) === 'objdump')
        );
    }

    public function test_mysql_strict_mode_rejects_raw_banner(): void
    {
        // Proves the enforcement this fix relies on is active: a raw
        // 200-char version must fail against the 64-char column.
        // SQLite does not enforce varchar widths, so it skips there.
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MySQL/MariaDB strict-mode proof only (driver: '.DB::getDriverName().').');
        }

        $this->expectException(QueryException::class);
        DB::table('worker_capabilities')->insert([
            'adapter' => 'strict-probe',
            'available' => true,
            'version' => str_repeat('v', 200),
        ]);
    }
}
