<?php

namespace Tests\Support;

use App\Services\Binary\Adapters\AnalysisFailedException;
use App\Services\Binary\Sandbox\SpoolClient;

/**
 * In-process spool peer for adapter/job tests. It implements the
 * coordinator side of the protocol (probe/run shapes) with canned
 * runner answers — the REAL runner ↔ tool boundary is covered by
 * docker/analysis/tests/test_runner.py plus the stub-runner
 * SpoolClientTest, not by this fake.
 */
class SpoolFake extends SpoolClient
{
    /** @var array<string, array<string, mixed>|null> tool => probe answer (null = silent) */
    public array $probes = [];

    /** @var array<string, array{evidence: array<string, mixed), tool_output?: ?string, stored_files?: array<string, array{path: string, size: int, tmp: string}}, fail?: string}> */
    public array $runs = [];

    /** @var list<array{tool: string, profile: string, params: array<string, mixed>, input: string, bytes: int}> */
    public array $seen = [];

    /** @var list<string> profiles that fail with runner_failed */
    public array $failProfiles = [];

    public function __construct()
    {
        $spool = sys_get_temp_dir().'/rev2-fake-spool-'.uniqid();
        $results = sys_get_temp_dir().'/rev2-fake-results-'.uniqid();
        @mkdir($spool, 0755, true);
        @mkdir($results, 0755, true);
        parent::__construct($spool, $results);
    }

    public function __destruct()
    {
        @rmdir($this->spoolDir());
        @rmdir($this->resultsDir());
    }

    public function probe(string $tool, int $timeoutSeconds = 30): ?array
    {
        if (! array_key_exists($tool, $this->probes)) {
            return null;
        }

        return $this->probes[$tool];
    }

    public function run(
        string $tool,
        string $profile,
        array $params,
        string $inputName,
        string $bytes,
        array $limits,
        callable $heartbeat,
    ): array {
        $this->seen[] = ['tool' => $tool, 'profile' => $profile, 'params' => $params, 'input' => $inputName, 'bytes' => strlen($bytes)];

        if (! $heartbeat()) {
            throw new AnalysisFailedException('cancelled_race', 'The run was cancelled.');
        }
        if (in_array($profile, $this->failProfiles, true)) {
            throw new AnalysisFailedException('runner_failed', 'Canned profile failure.');
        }
        $canned = $this->runs[$tool] ?? null;
        if ($canned === null) {
            throw new AnalysisFailedException('environmental', 'No canned runner answer for '.$tool.'.');
        }
        if (isset($canned['fail'])) {
            throw new AnalysisFailedException($canned['fail'], 'Canned runner failure.');
        }

        return [
            'status' => ['schema' => 1, 'state' => 'done', 'exit_code' => 0],
            'evidence' => $canned['evidence'],
            'tool_output' => $canned['tool_output'] ?? null,
            'claim_dir' => $this->resultsDir().'/fake',
            'stored_files' => $canned['stored_files'] ?? [],
        ];
    }

    /** @return array{tool: string, tool_version: string, runner_arch: string, image_digest: string, tool_image: string} */
    public static function answer(string $tool, string $version, array $extra = []): array
    {
        return array_merge([
            'schema' => 1,
            'tool' => $tool,
            'tool_version' => $version,
            'runner_arch' => php_uname('m'),
            'image_digest' => 'build-local-test',
            'tool_image' => 'rev2square/'.$tool.':test',
        ], $extra);
    }
}
