<?php

namespace App\Services\Binary\Adapters;

use App\Jobs\ExtractArtifactJob;
use App\Models\BinaryAnalysis;
use App\Models\ProjectArtifact;
use App\Services\Binary\FormatDetector;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Filesystem-extraction adapter (isolated runner): CramFS, SquashFS,
 * and UBI/UBIFS images are unpacked offline — no mounts, no block or
 * MTD devices, no root — and contained executables are promoted to
 * child artifacts in the SAME project (same authorization boundary)
 * for follow-up CPU analysis.
 *
 * Profile selection is explicit per detected flavor with one
 * documented fallback (7zz, then unsquashfs for SquashFS variants).
 * mtd-utils is deliberately not used: its UBI tooling needs MTD
 * devices the sandbox never exposes; ubireader extracts offline.
 */
final class ExtractAdapter extends SandboxedBackend
{
    /** @var list<string> */
    public const PROFILES = ['7zz-list', '7zz-extract', 'unsquashfs-list', 'unsquashfs-extract', 'ubireader-extract'];

    public const MAX_PROMOTIONS = 3;

    /** @var list<string> */
    private const EXECUTABLE_FORMATS = [
        ProjectArtifact::FORMAT_ELF,
        ProjectArtifact::FORMAT_PE,
        ProjectArtifact::FORMAT_MACHO,
        ProjectArtifact::FORMAT_DEX,
    ];

    public function id(): string
    {
        return 'extract';
    }

    public function name(): string
    {
        return 'Filesystem extraction (isolated)';
    }

    public function tool(): string
    {
        return 'extract';
    }

    public function formats(): array
    {
        return [ProjectArtifact::FORMAT_FILESYSTEM];
    }

    public function targets(): array
    {
        return $this->probe()['available'] ? ['extract'] : [];
    }

    public function candidateTargets(): array
    {
        return [];
    }

    public function capabilities(string $format, ?string $target): array
    {
        if ($format !== ProjectArtifact::FORMAT_FILESYSTEM) {
            return array_fill_keys(Capability::all(), Capability::UNSUPPORTED);
        }
        if ($target !== 'extract') {
            // CPU targets apply to promoted children, never to the
            // image itself — analyzing the raw image as code would
            // decode filesystem structures as instructions.
            return array_fill_keys(Capability::all(), Capability::UNSUPPORTED);
        }

        return [
            Capability::DETECTION => Capability::SUPPORTED,
            Capability::METADATA => Capability::SUPPORTED,
            Capability::STRINGS => Capability::SUPPORTED,
            Capability::SEGMENTS => Capability::UNSUPPORTED,
            Capability::DISASSEMBLY => Capability::UNSUPPORTED,
            Capability::FUNCTIONS => Capability::UNSUPPORTED,
            Capability::XREFS => Capability::UNSUPPORTED,
            Capability::CALLGRAPH => Capability::UNSUPPORTED,
            Capability::DECOMPILATION => Capability::UNSUPPORTED,
            Capability::EXTRACTION => Capability::SUPPORTED,
            Capability::AI_EXPLAIN => Capability::SUPPORTED,
            Capability::COMPARISON => Capability::PARTIAL,
        ];
    }

    public function analyze(BinaryAnalysis $analysis, string $bytes, array $snapshot, array $config, callable $heartbeat): array
    {
        $started = microtime(true);
        if (! $heartbeat()) {
            throw new AnalysisFailedException('cancelled_race', 'The run was cancelled before the isolated backend started.');
        }

        $flavor = (string) ($analysis->artifact->tech_attributes['filesystem_flavor'] ?? '');
        $profiles = self::profilesFor($flavor);
        $attempts = [];
        $result = null;
        $usedProfile = null;
        foreach ($profiles as $profile) {
            try {
                $result = $this->runSandbox($profile, [], 'input.img', $bytes, $heartbeat);
                $usedProfile = $profile;
                break;
            } catch (AnalysisFailedException $e) {
                $attempts[] = $profile.': '.$e->errorCode;
                if (! in_array($e->errorCode, ['runner_failed', 'environmental', 'evidence_rejected'], true)) {
                    throw $e;
                }
            }
        }
        if ($result === null || $usedProfile === null) {
            throw new AnalysisFailedException('extraction_failed', 'No qualified extractor opened this image ('.implode('; ', $attempts).'). The image may use an unsupported variant or compression.');
        }

        $tree = $this->normalizeTree($result['evidence']);
        $warnings = [];
        if ($tree['truncated']) {
            $warnings[] = 'extraction_capped';
        }
        if (count($attempts) > 0) {
            $warnings[] = 'extraction_fallback_used';
        }
        if ($tree['files'] === []) {
            $warnings[] = 'no_files_extracted';
        }

        try {
            $promoted = $this->promote($analysis, $result['stored_files'] ?? [], $warnings);
        } finally {
            foreach ($result['stored_files'] ?? [] as $stored) {
                @unlink((string) ($stored['tmp'] ?? ''));
            }
        }

        return $this->envelope($analysis, $config, $started, 'Offline filesystem extraction in the isolated runner; contained executables promoted to child artifacts for CPU analysis.', [
            'tool' => [
                'name' => 'Filesystem extraction',
                'version' => $this->version(),
                'profile' => $usedProfile,
                'flavor' => $flavor,
            ],
            'extraction' => [
                'profile' => $usedProfile,
                'files' => $tree['files'],
                'inline' => $tree['inline'],
                'truncated' => $tree['truncated'],
                'promoted' => $promoted,
            ],
            'warnings' => $warnings,
        ]);
    }

    /** @return list<string> ordered extraction attempts for one flavor */
    public static function profilesFor(string $flavor): array
    {
        return match ($flavor) {
            'ubi', 'ubifs' => ['ubireader-extract'],
            'squashfs' => ['7zz-extract', 'unsquashfs-extract'],
            default => ['7zz-extract'],
        };
    }

    /**
     * Documented extraction argv contract (runner-side paths).
     * Enforcement lives in the runner; this builder exists so the PHP
     * side and the conformance tests share one shape.
     *
     * @param  array{job_dir: string, input: string, output: string, profile: string}  $params
     */
    public function buildCommand(array $params): ?array
    {
        foreach (['job_dir', 'input', 'output'] as $key) {
            if (! isset($params[$key]) || ! is_string($params[$key]) || $params[$key] === '') {
                return null;
            }
        }
        $profile = $params['profile'] ?? '';
        $input = $params['input'];
        $output = $params['output'];

        return match ($profile) {
            '7zz-list' => ['/opt/7zz/7zz', 'l', '-slt', '-ba', $input],
            '7zz-extract' => ['/opt/7zz/7zz', 'x', '-y', '-o'.$output, $input],
            'unsquashfs-list' => ['/usr/bin/unsquashfs', '-ll', '-d', $output, $input],
            'unsquashfs-extract' => ['/usr/bin/unsquashfs', '-d', $output, '-f', $input],
            'ubireader-extract' => ['/usr/local/bin/ubireader_extract_files', '-o', $output, $input],
            default => null,
        };
    }

    /**
     * Promote stored candidates to child artifacts (same project,
     * same authorization). Executable magic first, then the largest
     * remaining file; existing same-sha rows are linked, never
     * duplicated.
     *
     * @param  array<string, array{path: string, size: int, tmp: string}>  $stored
     * @param  list<string>  $warnings
     * @return list<array{path: string, artifact_id: int}>
     */
    private function promote(BinaryAnalysis $analysis, array $stored, array &$warnings): array
    {
        $parent = $analysis->artifact;
        $ranked = [];
        foreach ($stored as $sha => $file) {
            $tmp = (string) ($file['tmp'] ?? '');
            if ($tmp === '' || ! is_file($tmp)) {
                continue;
            }
            $head = @file_get_contents($tmp, false, null, 0, 262144);
            if (! is_string($head) || $head === '') {
                continue;
            }
            $detected = FormatDetector::detect($head, basename((string) ($file['path'] ?? '')));
            $ranked[] = [
                'sha' => $sha,
                'file' => $file,
                'format' => $detected['format'],
                'executable' => in_array($detected['format'], self::EXECUTABLE_FORMATS, true),
            ];
        }
        usort($ranked, fn ($a, $b) => [$b['executable'], $b['file']['size']] <=> [$a['executable'], $a['file']['size']]);

        $promoted = [];
        foreach (array_slice($ranked, 0, self::MAX_PROMOTIONS) as $candidate) {
            if (! $candidate['executable'] && $promoted !== []) {
                // Only the single largest non-executable is kept as a
                // fallback subject; the rest stay manifest-only.
                continue;
            }
            $file = $candidate['file'];
            $existing = ProjectArtifact::where('project_id', $parent->project_id)
                ->where('sha256', $candidate['sha'])
                ->first();
            if ($existing !== null) {
                $promoted[] = ['path' => (string) $file['path'], 'artifact_id' => $existing->id];
                $warnings[] = 'member_already_present';

                continue;
            }
            $content = @file_get_contents((string) $file['tmp']);
            if (! is_string($content) || hash('sha256', $content) !== $candidate['sha']) {
                continue;
            }
            $child = ProjectArtifact::create([
                'project_id' => $parent->project_id,
                'uploader_id' => $parent->uploader_id,
                'original_name' => basename((string) $file['path']),
                'byte_size' => strlen($content),
                'sha256' => $candidate['sha'],
                'storage_key' => Str::random(40),
                'format' => $candidate['format'],
                'arch' => null,
                'state' => ProjectArtifact::STATE_QUEUED,
                'tech_attributes' => [
                    'extracted_from' => [
                        'artifact_id' => $parent->id,
                        'member_path' => (string) $file['path'],
                        'analysis_id' => $analysis->id,
                        'extracted_by' => $analysis->requested_by,
                    ],
                ],
            ]);
            Storage::disk('artifacts')->put($child->rawPath(), $content);
            ExtractArtifactJob::dispatch($child->id);
            $promoted[] = ['path' => (string) $file['path'], 'artifact_id' => $child->id];
        }

        return $promoted;
    }
}
