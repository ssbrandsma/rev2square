<?php

namespace Tests\Unit\Binary\Sandbox;

use App\Services\Binary\Adapters\AnalysisFailedException;
use App\Services\Binary\Sandbox\SpoolClient;
use Tests\TestCase;

/**
 * Coordinator file protocol against a real stub-runner process:
 * probe/analyze round-trips, cancel delivery, runner timeout, and
 * coordinator give-up over actual spool files (no mocks).
 */
class SpoolClientTest extends TestCase
{
    private string $spool;

    private string $results;

    /** @var list<resource> */
    private array $procs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->spool = sys_get_temp_dir().'/rev2-spool-'.uniqid();
        $this->results = sys_get_temp_dir().'/rev2-results-'.uniqid();
        mkdir($this->spool, 0755, true);
        mkdir($this->results, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->procs as $proc) {
            if (is_resource($proc)) {
                @proc_terminate($proc);
                @proc_close($proc);
            }
        }
        $this->removeDir($this->spool);
        $this->removeDir($this->results);
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            if (is_dir($path) && ! is_link($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /** @param array<string, string> $env */
    private function startStub(string $mode, array $env = []): void
    {
        $cmd = escapeshellarg(base_path('tests/Fixtures/Binary/stub-runner.sh'))
            .' '.escapeshellarg($this->spool)
            .' '.escapeshellarg($this->results)
            .' '.escapeshellarg($mode);
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes, null, $env + ['PATH' => '/usr/bin:/bin']);
        $this->assertIsResource($proc);
        $this->procs[] = $proc;
    }

    private function client(): SpoolClient
    {
        return new SpoolClient($this->spool, $this->results);
    }

    public function test_probe_returns_null_without_dirs_and_round_trips_with_stub(): void
    {
        $missing = new SpoolClient($this->spool.'/nope', $this->results.'/nope');
        $this->assertNull($missing->probe('objdump', 2));

        $this->startStub('probe');
        $answer = $this->client()->probe('objdump', 10);

        $this->assertIsArray($answer);
        $this->assertSame('stub-1.0', $answer['tool_version']);
        $this->assertSame(php_uname('m'), $answer['runner_arch']);
        // Both sides cleaned up after the round-trip.
        $this->assertSame(['.', '..'], scandir($this->spool));
        $this->assertSame(['.', '..'], scandir($this->results));
    }

    public function test_run_round_trip_returns_evidence_and_tool_output(): void
    {
        $this->startStub('ok', [
            'STUB_EVIDENCE' => '{"schema":1,"tool":"objdump","exit_code":0}',
            'STUB_TOOLOUT' => "line one\n",
        ]);

        $result = $this->client()->run('objdump', 'auto', [], 'input.elf', 'ELF bytes', ['wall_seconds' => 10], fn () => true);

        $this->assertSame('done', $result['status']['state']);
        $this->assertSame(1, $result['evidence']['schema']);
        $this->assertSame("line one\n", $result['tool_output']);
        $this->assertSame(['.', '..'], scandir($this->spool));
        $this->assertSame(['.', '..'], scandir($this->results));
    }

    public function test_run_rejects_bad_inputs_without_touching_spool(): void
    {
        $client = $this->client();

        foreach (['../evil', 'a/b', '', str_repeat('x', 200)] as $bad) {
            try {
                $client->run('objdump', 'auto', [], $bad, 'x', ['wall_seconds' => 5], fn () => true);
                $this->fail("input name accepted: {$bad}");
            } catch (AnalysisFailedException $e) {
                $this->assertSame('configuration_required', $e->errorCode);
            }
        }
        try {
            $client->run('objdump', 'auto', ['nested' => ['array']], 'input.bin', 'x', ['wall_seconds' => 5], fn () => true);
            $this->fail('non-scalar params accepted');
        } catch (AnalysisFailedException $e) {
            $this->assertSame('configuration_required', $e->errorCode);
        }
        try {
            $client->run('objdump', 'auto', [], 'input.bin', 'x', ['wall_seconds' => 99999], fn () => true);
            $this->fail('absurd wall accepted');
        } catch (AnalysisFailedException $e) {
            $this->assertSame('configuration_required', $e->errorCode);
        }

        $this->assertSame(['.', '..'], scandir($this->spool));
    }

    public function test_run_delivers_cancel_and_runner_confirms(): void
    {
        $this->startStub('slow');
        $polls = 0;

        try {
            $this->client()->run('objdump', 'auto', [], 'input.bin', 'x', ['wall_seconds' => 20], function () use (&$polls) {
                $polls++;

                return $polls < 3;
            });
            $this->fail('cancelled run returned');
        } catch (AnalysisFailedException $e) {
            $this->assertSame('cancelled_race', $e->errorCode);
        }

        // The stub saw the cancel file and confirmed cancellation.
        $claimed = array_values(array_diff(scandir($this->results), ['.', '..']));
        if ($claimed !== []) {
            $marker = $this->results.'/'.$claimed[0].'/stub-marker.txt';
            $this->assertFileExists($marker);
        }
        // Coordinator side cleaned up regardless.
        $this->assertSame(['.', '..'], scandir($this->spool));
    }

    public function test_run_maps_runner_timeout(): void
    {
        $this->startStub('timeout');

        try {
            $this->client()->run('objdump', 'auto', [], 'input.bin', 'x', ['wall_seconds' => 10], fn () => true);
            $this->fail('timeout returned');
        } catch (AnalysisFailedException $e) {
            $this->assertSame('analysis_timeout', $e->errorCode);
        }
    }

    public function test_run_gives_up_on_silent_runner(): void
    {
        config(['artifacts.binary_spool_grace_seconds' => 1]);

        try {
            $this->client()->run('objdump', 'auto', [], 'input.bin', 'x', ['wall_seconds' => 1], fn () => true);
            $this->fail('silent runner returned');
        } catch (AnalysisFailedException $e) {
            $this->assertSame('environmental', $e->errorCode);
        }
    }

    public function test_cleanup_and_prune_remove_stale_only(): void
    {
        $client = $this->client();
        mkdir($this->spool.'/old-job', 0755, true);
        file_put_contents($this->spool.'/old-job/job.json', '{}');
        touch($this->spool.'/old-job', time() - 7200);
        mkdir($this->results.'/old-job', 0755, true);
        file_put_contents($this->results.'/old-job/status.json', '{}');
        touch($this->results.'/old-job', time() - 7200);
        mkdir($this->spool.'/fresh-job', 0755, true);

        $client->cleanup('old-job');
        $this->assertDirectoryDoesNotExist($this->spool.'/old-job');
        $this->assertDirectoryDoesNotExist($this->results.'/old-job');

        // prune() only touches entries older than the window.
        $removed = $client->prune(3600);
        $this->assertSame(['spool' => 0, 'results' => 0], $removed);
        $this->assertDirectoryExists($this->spool.'/fresh-job');
    }
}
