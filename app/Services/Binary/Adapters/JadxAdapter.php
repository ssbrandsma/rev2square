<?php

namespace App\Services\Binary\Adapters;

use App\Models\BinaryAnalysis;
use App\Models\ProjectArtifact;
use App\Services\Ops\DeployHints;

/**
 * JADX adapter (isolated runner): Dalvik/DEX, APK, and Java class
 * reconstruction. Output is labelled Java-like reconstruction, never
 * original source. Resources ARE decoded (manifest, resource table)
 * because they are first-class APK evidence; caps bound the tree.
 */
final class JadxAdapter extends SandboxedBackend
{
    public function id(): string
    {
        return 'jadx';
    }

    public function name(): string
    {
        return 'JADX (isolated)';
    }

    public function tool(): string
    {
        return 'jadx';
    }

    protected function qualifyProbeAnswer(array $answer): ?array
    {
        // A version ping cannot prove jadx decompiles: only a fixture
        // decompile with the known marker qualifies the backend.
        // Runners predating the fixture report no fixture key and stay
        // not-operational until rebuilt.
        if (($answer['fixture']['ok'] ?? null) === true) {
            return null;
        }

        return [
            'available' => false,
            'reason' => 'The JADX runner answered but its DEX smoke fixture did not decompile (stale runner image or broken install): decompilation available in backend, not operational yet. '.DeployHints::rebuildRunnerHint('jadx-runner'),
            'setup_doc' => 'docs/analysis-spool-deployment.md',
        ];
    }

    public function formats(): array
    {
        return [ProjectArtifact::FORMAT_DEX, ProjectArtifact::FORMAT_APK, ProjectArtifact::FORMAT_CLASS, ProjectArtifact::FORMAT_ARCHIVE];
    }

    public function targets(): array
    {
        return $this->probe()['available'] ? ['dalvik', 'jvm'] : [];
    }

    public function candidateTargets(): array
    {
        return [];
    }

    public function capabilities(string $format, ?string $target): array
    {
        if (! in_array($format, $this->formats(), true)) {
            return array_fill_keys(Capability::all(), Capability::UNSUPPORTED);
        }
        if (($format === ProjectArtifact::FORMAT_DEX || $format === ProjectArtifact::FORMAT_APK) && $target !== 'dalvik') {
            return array_fill_keys(Capability::all(), Capability::UNSUPPORTED);
        }
        if ($format === ProjectArtifact::FORMAT_CLASS && $target !== 'jvm') {
            return array_fill_keys(Capability::all(), Capability::UNSUPPORTED);
        }
        if (! in_array($target, ['dalvik', 'jvm'], true)) {
            $caps = array_fill_keys(Capability::all(), Capability::DEPENDENCY_UNAVAILABLE);
            $caps[Capability::DETECTION] = Capability::SUPPORTED;
            $caps[Capability::METADATA] = Capability::SUPPORTED;

            return $caps;
        }

        return [
            Capability::DETECTION => Capability::SUPPORTED,
            Capability::METADATA => Capability::SUPPORTED,
            Capability::STRINGS => Capability::SUPPORTED,
            Capability::SEGMENTS => Capability::UNSUPPORTED,
            Capability::DISASSEMBLY => Capability::UNSUPPORTED,
            Capability::FUNCTIONS => Capability::PARTIAL,
            Capability::XREFS => Capability::UNSUPPORTED,
            Capability::CALLGRAPH => Capability::UNSUPPORTED,
            Capability::DECOMPILATION => Capability::SUPPORTED,
            Capability::EXTRACTION => Capability::UNSUPPORTED,
            Capability::AI_EXPLAIN => Capability::SUPPORTED,
            Capability::COMPARISON => Capability::PARTIAL,
        ];
    }

    public function analyze(BinaryAnalysis $analysis, string $bytes, array $snapshot, array $config, callable $heartbeat): array
    {
        $started = microtime(true);
        $format = (string) ($config['member_format'] ?? $analysis->artifact->format);
        $ext = match ($format) {
            ProjectArtifact::FORMAT_APK => '.apk',
            ProjectArtifact::FORMAT_DEX => '.dex',
            ProjectArtifact::FORMAT_CLASS => '.class',
            default => '.zip',
        };
        if (! $heartbeat()) {
            throw new AnalysisFailedException('cancelled_race', 'The run was cancelled before the isolated backend started.');
        }

        $result = $this->runSandbox('apk', ['threads' => 2], 'input'.$ext, $bytes, $heartbeat);
        $tree = $this->normalizeTree($result['evidence']);

        $warnings = [];
        if ($tree['truncated']) {
            $warnings[] = 'reconstruction_capped';
        }

        return $this->envelope($analysis, $config, $started, 'JADX reconstruction of Dalvik/Java bytecode (Java-like output, never original source); resources decoded.', [
            'tool' => [
                'name' => 'JADX',
                'version' => $this->version(),
                'profile' => 'apk',
            ],
            'reconstruction' => [
                'files' => $tree['files'],
                'inline' => $tree['inline'],
                'classes' => self::classes($tree['files'], '.java'),
                'truncated' => $tree['truncated'],
            ],
            'warnings' => $warnings,
        ]);
    }

    /**
     * Documented jadx argv contract (runner-side paths). Enforcement
     * lives in the runner; this builder exists so the PHP side and the
     * conformance tests share one shape. Null when inputs are rejected.
     *
     * @param  array{job_dir: string, input: string, output: string, threads?: int}  $params
     */
    public function buildCommand(array $params): ?array
    {
        foreach (['job_dir', 'input', 'output'] as $key) {
            if (! isset($params[$key]) || ! is_string($params[$key]) || $params[$key] === '') {
                return null;
            }
        }
        $threads = (int) ($params['threads'] ?? 2);
        if ($threads < 1 || $threads > 4) {
            return null;
        }

        return ['/opt/jadx/bin/jadx', '--no-src-save-cache', '-j', (string) $threads, '-d', $params['output'], $params['input']];
    }

    /** @param list<array{path: string, size: int, sha256: string}> $files @return list<array{name: string, file: string}> */
    public static function classes(array $files, string $suffix): array
    {
        $out = [];
        foreach ($files as $file) {
            if (! str_ends_with($file['path'], $suffix)) {
                continue;
            }
            $base = substr($file['path'], 0, -strlen($suffix));
            // Strip jadx's sources/ root so names read as packages.
            $base = preg_replace('#^(sources|resources)/#', '', $base) ?? $base;
            $out[] = ['name' => str_replace('/', '.', $base), 'file' => $file['path']];
            if (count($out) >= 500) {
                break;
            }
        }

        return $out;
    }
}
