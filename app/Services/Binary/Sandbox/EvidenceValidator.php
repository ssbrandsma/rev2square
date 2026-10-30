<?php

namespace App\Services\Binary\Sandbox;

use App\Services\Binary\Adapters\AnalysisFailedException;

/**
 * Single choke point for deterministic-analysis evidence.
 *
 * Every adapter — in-process or sandboxed — returns the
 * 'binary-analysis/1' envelope; this validator re-checks it before
 * anything is stored or rendered: versioned schema, strict types,
 * count/byte bounds from configuration, address sanity, safe paths,
 * no raw HTML, bounded redacted diagnostics. Unknown top-level keys
 * are stripped (forward-compatible runners stay ingestible) while
 * oversized or hostile content fails the whole run closed.
 */
final class EvidenceValidator
{
    /** @var list<string> */
    private const TOP_LEVEL = [
        'schema', 'adapter', 'adapter_version', 'tool', 'artifact',
        'target', 'config', 'analyzed_at', 'elapsed_ms', 'method_note',
        'segments', 'functions', 'xrefs', 'calls', 'disassembly',
        'decompilation', 'reconstruction', 'extraction', 'vectors',
        'io_notes', 'warnings', 'limits',
    ];

    /** @var list<string> */
    private const HTML_MARKERS = [
        '<script', '<iframe', '<object', '<embed', '<svg',
        'javascript:',
    ];

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed> normalized evidence
     *
     * @throws AnalysisFailedException
     */
    public static function validate(string $adapterId, mixed $evidence): array
    {
        if (! is_array($evidence)) {
            throw new AnalysisFailedException('evidence_rejected', 'The backend returned unreadable evidence. Its output was discarded.');
        }
        if (($evidence['schema'] ?? null) !== 'binary-analysis/1') {
            throw new AnalysisFailedException('evidence_rejected', 'The backend returned evidence with an unknown schema. Its output was discarded.');
        }
        if (($evidence['adapter'] ?? null) !== $adapterId) {
            throw new AnalysisFailedException('evidence_rejected', 'The backend returned evidence for a different adapter. Its output was discarded.');
        }

        $maxFunctions = (int) config('artifacts.binary_max_functions', 2000);
        $maxXrefs = (int) config('artifacts.binary_max_xrefs', 10000);
        $maxInstructions = (int) config('artifacts.binary_max_instructions', 20000);

        $out = [];
        $stripped = false;
        foreach (self::TOP_LEVEL as $key) {
            if (array_key_exists($key, $evidence)) {
                $out[$key] = $evidence[$key];
            }
        }
        if (count($out) !== count($evidence)) {
            $stripped = true;
        }

        $out['functions'] = self::functions($out['functions'] ?? [], $maxFunctions);
        $out['xrefs'] = self::xrefs($out['xrefs'] ?? [], $maxXrefs);
        $out['calls'] = self::calls($out['calls'] ?? []);
        $out['disassembly'] = self::disassembly($out['disassembly'] ?? [], $maxInstructions);
        if (array_key_exists('decompilation', $out)) {
            $out['decompilation'] = self::decompilation($out['decompilation']);
        }
        if (array_key_exists('reconstruction', $out)) {
            $out['reconstruction'] = self::reconstruction($out['reconstruction']);
        }
        if (array_key_exists('extraction', $out)) {
            $out['extraction'] = self::extraction($out['extraction']);
        }
        $out['segments'] = self::segments($out['segments'] ?? []);
        $out['warnings'] = self::warnings($out['warnings'] ?? [], $stripped);
        $out['tool'] = self::redactedMap($out['tool'] ?? []);
        $out['artifact'] = self::artifact($out['artifact'] ?? []);
        $out['method_note'] = self::cleanText((string) ($out['method_note'] ?? ''), 500);

        self::rejectHtml($out);

        return $out;
    }

    /** @return list<array{id: string, address: int, file_offset: int|null, name: string|null, source: string, size: int|null, size_tentative: bool}> */
    private static function functions(mixed $value, int $max): array
    {
        if (! is_array($value)) {
            throw self::reject('functions');
        }
        if (count($value) > $max) {
            throw self::reject('functions');
        }
        $out = [];
        foreach ($value as $fn) {
            if (! is_array($fn)) {
                throw self::reject('functions');
            }
            $id = $fn['id'] ?? null;
            $source = $fn['source'] ?? null;
            if (! is_string($id) || preg_match('#^f[0-9a-f]+$#', $id) !== 1) {
                throw self::reject('functions');
            }
            if (! in_array($source, ['entry', 'symbol', 'sys_target', 'call_target', 'ghidra', 'imported'], true)) {
                throw self::reject('functions');
            }
            $name = $fn['name'] ?? null;
            $out[] = [
                'id' => $id,
                'address' => self::address($fn['address'] ?? null),
                'file_offset' => array_key_exists('file_offset', $fn) && $fn['file_offset'] !== null
                    ? (is_int($fn['file_offset']) && $fn['file_offset'] >= 0 ? $fn['file_offset'] : throw self::reject('functions'))
                    : null,
                'name' => $name === null ? null : self::cleanText((string) $name, 200),
                'source' => $source,
                'size' => isset($fn['size']) && is_int($fn['size']) && $fn['size'] >= 0 ? $fn['size'] : null,
                'size_tentative' => (bool) ($fn['size_tentative'] ?? true),
            ];
        }

        return $out;
    }

    /** @return list<array{from: int, to: int, kind: string}> */
    private static function xrefs(mixed $value, int $max): array
    {
        if (! is_array($value)) {
            throw self::reject('xrefs');
        }
        if (count($value) > $max) {
            throw self::reject('xrefs');
        }
        $out = [];
        foreach ($value as $xref) {
            if (! is_array($xref)) {
                throw self::reject('xrefs');
            }
            $kind = (string) ($xref['kind'] ?? '');
            if (! in_array($kind, ['call', 'jump', 'branch', 'data', 'ref'], true)) {
                throw self::reject('xrefs');
            }
            $out[] = ['from' => self::address($xref['from'] ?? null), 'to' => self::address($xref['to'] ?? null), 'kind' => $kind];
        }

        return $out;
    }

    /** @return array<string, array{callers: list<string>, callees: list<string>}> */
    private static function calls(mixed $value): array
    {
        if (! is_array($value)) {
            throw self::reject('calls');
        }
        if (count($value) > 2000) {
            throw self::reject('calls');
        }
        $out = [];
        foreach ($value as $id => $call) {
            if (! is_string($id) || preg_match('#^f[0-9a-f]+$#', $id) !== 1 || ! is_array($call)) {
                throw self::reject('calls');
            }
            foreach (['callers', 'callees'] as $side) {
                $list = $call[$side] ?? null;
                if (! is_array($list) || count($list) > 500) {
                    throw self::reject('calls');
                }
                foreach ($list as $fid) {
                    if (! is_string($fid) || preg_match('#^f[0-9a-f]+$#', $fid) !== 1) {
                        throw self::reject('calls');
                    }
                }
            }
            $out[$id] = ['callers' => array_values($call['callers']), 'callees' => array_values($call['callees'])];
        }

        return $out;
    }

    /** @return array{windows: list<array{name: string, kind: string, base_address: int|null, file_offset: int|null, lines: list<array{offset: int|null, address: int, bytes: string, text: string}>, truncated: bool}>, total_instructions: int, total_data_bytes: int, truncated: bool} */
    private static function disassembly(mixed $value, int $maxInstructions): array
    {
        if (! is_array($value)) {
            throw self::reject('disassembly');
        }
        $windows = $value['windows'] ?? [];
        if (! is_array($windows) || count($windows) > 256) {
            throw self::reject('disassembly');
        }
        $total = 0;
        $out = [];
        foreach ($windows as $window) {
            if (! is_array($window)) {
                throw self::reject('disassembly');
            }
            $lines = $window['lines'] ?? [];
            if (! is_array($lines)) {
                throw self::reject('disassembly');
            }
            $clean = [];
            foreach ($lines as $line) {
                if (! is_array($line)) {
                    throw self::reject('disassembly');
                }
                $total++;
                if ($total > $maxInstructions) {
                    throw self::reject('disassembly');
                }
                $bytes = (string) ($line['bytes'] ?? '');
                if (strlen($bytes) > 128 || preg_match('#^[0-9a-fA-F]*$#', $bytes) !== 1) {
                    throw self::reject('disassembly');
                }
                $clean[] = [
                    'offset' => array_key_exists('offset', $line) && $line['offset'] !== null ? self::address($line['offset']) : null,
                    'address' => self::address($line['address'] ?? null),
                    'bytes' => strtolower($bytes),
                    'text' => self::cleanText((string) ($line['text'] ?? ''), 500),
                ];
            }
            // Established shape keeps BOTH the view's `segment` label
            // and the objdump-compatible `name` (they agree when only
            // one is present).
            $label = (string) ($window['segment'] ?? $window['name'] ?? '');
            $out[] = [
                'segment' => self::cleanText($label, 200),
                'name' => self::cleanText((string) ($window['name'] ?? $label), 200),
                'kind' => self::cleanText((string) ($window['kind'] ?? ''), 100),
                'base_address' => array_key_exists('base_address', $window) && $window['base_address'] !== null ? self::address($window['base_address']) : null,
                'file_offset' => isset($window['file_offset']) && is_int($window['file_offset']) && $window['file_offset'] >= 0 ? $window['file_offset'] : null,
                'lines' => $clean,
                'truncated' => (bool) ($window['truncated'] ?? false),
            ];
        }

        return [
            'windows' => $out,
            'total_instructions' => $total,
            'total_data_bytes' => isset($value['total_data_bytes']) && is_int($value['total_data_bytes']) ? $value['total_data_bytes'] : 0,
            'truncated' => (bool) ($value['truncated'] ?? false),
        ];
    }

    /** @return array{functions: list<array{name: string, address: int, c?: string, error?: string}>, truncated: bool} */
    private static function decompilation(mixed $value): array
    {
        if (! is_array($value)) {
            throw self::reject('decompilation');
        }
        $funcs = $value['functions'] ?? [];
        if (! is_array($funcs) || count($funcs) > 500) {
            throw self::reject('decompilation');
        }
        $chars = 0;
        $out = [];
        foreach ($funcs as $fn) {
            if (! is_array($fn)) {
                throw self::reject('decompilation');
            }
            $entry = [
                'name' => self::cleanText((string) ($fn['name'] ?? ''), 200),
                'address' => self::address($fn['address'] ?? null),
            ];
            if (array_key_exists('c', $fn)) {
                $c = self::cleanText((string) $fn['c'], 100000);
                $chars += strlen($c);
                if ($chars > 256 * 1024) {
                    throw self::reject('decompilation');
                }
                $entry['c'] = $c;
            } else {
                $entry['error'] = self::cleanText((string) ($fn['error'] ?? 'unknown'), 200);
            }
            $out[] = $entry;
        }

        return ['functions' => $out, 'truncated' => (bool) ($value['truncated'] ?? false)];
    }

    /** @return array{files: list<array{path: string, size: int, sha256: string}>, inline: array<string, string>, classes: list<array{name: string, file: string}>, truncated: bool} */
    private static function reconstruction(mixed $value): array
    {
        if (! is_array($value)) {
            throw self::reject('reconstruction');
        }
        $classes = $value['classes'] ?? [];
        if (! is_array($classes) || count($classes) > 500) {
            throw self::reject('reconstruction');
        }
        $clean = [];
        foreach ($classes as $class) {
            if (! is_array($class)) {
                throw self::reject('reconstruction');
            }
            $clean[] = [
                'name' => self::cleanText((string) ($class['name'] ?? ''), 300),
                'file' => self::safePath($class['file'] ?? null),
            ];
        }

        return [
            'files' => self::manifest($value['files'] ?? null, 'reconstruction'),
            'inline' => self::inline($value['inline'] ?? null, 'reconstruction'),
            'classes' => $clean,
            'truncated' => (bool) ($value['truncated'] ?? false),
        ];
    }

    /** @return array{profile: string, files: list<array{path: string, size: int, sha256: string}>, inline: array<string, string>, truncated: bool, promoted: list<array{path: string, artifact_id: int}>} */
    private static function extraction(mixed $value): array
    {
        if (! is_array($value)) {
            throw self::reject('extraction');
        }
        $promoted = $value['promoted'] ?? [];
        if (! is_array($promoted) || count($promoted) > 8) {
            throw self::reject('extraction');
        }
        $clean = [];
        foreach ($promoted as $row) {
            if (! is_array($row) || ! isset($row['artifact_id']) || ! is_int($row['artifact_id'])) {
                throw self::reject('extraction');
            }
            $clean[] = ['path' => self::safePath($row['path'] ?? null), 'artifact_id' => $row['artifact_id']];
        }

        return [
            'profile' => self::cleanText((string) ($value['profile'] ?? ''), 100),
            'files' => self::manifest($value['files'] ?? null, 'extraction'),
            'inline' => self::inline($value['inline'] ?? null, 'extraction'),
            'truncated' => (bool) ($value['truncated'] ?? false),
            'promoted' => $clean,
        ];
    }

    /** @return list<array{path: string, size: int, sha256: string}> */
    private static function manifest(mixed $value, string $section): array
    {
        if (! is_array($value) || count($value) > 2000) {
            throw self::reject($section);
        }
        $out = [];
        foreach ($value as $row) {
            if (! is_array($row)) {
                throw self::reject($section);
            }
            $size = $row['size'] ?? null;
            $sha = $row['sha256'] ?? null;
            if (! is_int($size) || $size < 0 || ! is_string($sha) || preg_match('#^[0-9a-f]{64}$#', $sha) !== 1) {
                throw self::reject($section);
            }
            $out[] = ['path' => self::safePath($row['path'] ?? null), 'size' => $size, 'sha256' => $sha];
        }

        return $out;
    }

    /** @return array<string, string> */
    private static function inline(mixed $value, string $section): array
    {
        if (! is_array($value)) {
            throw self::reject($section);
        }
        $total = 0;
        $out = [];
        foreach ($value as $path => $content) {
            if (! is_string($content)) {
                throw self::reject($section);
            }
            $clean = self::cleanText($content, 32768);
            $total += strlen($clean);
            if ($total > 256 * 1024) {
                throw self::reject($section);
            }
            $out[self::safePath($path)] = $clean;
        }

        return $out;
    }

    /** @return list<array{name: string, kind: string, file_offset: int|null, file_size: int, base_address: int|null, length: int}> */
    private static function segments(mixed $value): array
    {
        if (! is_array($value) || count($value) > 256) {
            throw self::reject('segments');
        }
        $out = [];
        foreach ($value as $seg) {
            if (! is_array($seg)) {
                throw self::reject('segments');
            }
            $out[] = [
                'name' => self::cleanText((string) ($seg['name'] ?? ''), 200),
                'kind' => self::cleanText((string) ($seg['kind'] ?? ''), 100),
                'file_offset' => isset($seg['file_offset']) && is_int($seg['file_offset']) && $seg['file_offset'] >= 0 ? $seg['file_offset'] : null,
                'file_size' => isset($seg['file_size']) && is_int($seg['file_size']) && $seg['file_size'] >= 0 ? $seg['file_size'] : 0,
                'base_address' => array_key_exists('base_address', $seg) && $seg['base_address'] !== null ? self::address($seg['base_address']) : null,
                'length' => isset($seg['length']) && is_int($seg['length']) && $seg['length'] >= 0 ? $seg['length'] : 0,
            ];
        }

        return $out;
    }

    /** @return list<string> */
    private static function warnings(mixed $value, bool $stripped): array
    {
        if (! is_array($value)) {
            throw self::reject('warnings');
        }
        $out = [];
        foreach (array_slice($value, 0, 100) as $warning) {
            $out[] = self::cleanText((string) $warning, 300);
        }
        if ($stripped) {
            $out[] = 'unknown_evidence_keys_stripped';
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function redactedMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $key => $entry) {
            if (preg_match('#key|token|secret|password|credential|cookie|auth|private#i', (string) $key) === 1) {
                continue;
            }
            $out[self::cleanText((string) $key, 100)] = is_scalar($entry) ? self::cleanText((string) $entry, 500) : null;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function artifact(mixed $value): array
    {
        if (! is_array($value)) {
            throw self::reject('artifact');
        }
        $sha = $value['sha256'] ?? null;

        return [
            'id' => $value['id'] ?? null,
            'sha256' => is_string($sha) && preg_match('#^[0-9a-f]{64}$#', $sha) === 1 ? $sha : null,
            'format' => self::cleanText((string) ($value['format'] ?? ''), 50),
            'member' => self::member($value['member'] ?? null),
        ];
    }

    /** @return array{name: string|null, size: int|null, sha256: string|null, format: string|null, arch: string|null}|null */
    private static function member(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return ['name' => self::cleanText($value, 300), 'size' => null, 'sha256' => null, 'format' => null, 'arch' => null];
        }
        if (! is_array($value)) {
            throw self::reject('artifact');
        }
        $sha = $value['sha256'] ?? null;

        return [
            'name' => isset($value['name']) ? self::cleanText((string) $value['name'], 300) : null,
            'size' => isset($value['size']) && is_int($value['size']) && $value['size'] >= 0 ? $value['size'] : null,
            'sha256' => is_string($sha) && preg_match('#^[0-9a-f]{64}$#', $sha) === 1 ? $sha : null,
            'format' => isset($value['format']) ? self::cleanText((string) $value['format'], 50) : null,
            'arch' => isset($value['arch']) ? self::cleanText((string) $value['arch'], 50) : null,
        ];
    }

    private static function address(mixed $value): int
    {
        if (! is_int($value) || $value < 0) {
            throw self::reject('address');
        }

        return $value;
    }

    private static function safePath(mixed $value): string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 255) {
            throw self::reject('path');
        }
        if (str_starts_with($value, '/') || str_contains($value, '\\') || str_contains($value, "\0")) {
            throw self::reject('path');
        }
        foreach (explode('/', $value) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw self::reject('path');
            }
        }

        return $value;
    }

    private static function cleanText(string $value, int $max): string
    {
        // Strip control characters (keep tab/LF/CR), then truncate.
        $clean = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        return strlen($clean) > $max ? substr($clean, 0, $max) : $clean;
    }

    /** @param array<string, mixed> $evidence */
    private static function rejectHtml(array $evidence): void
    {
        $walk = function (mixed $node) use (&$walk): void {
            if (is_string($node)) {
                $lower = strtolower($node);
                foreach (self::HTML_MARKERS as $marker) {
                    if (str_contains($lower, $marker)) {
                        throw self::reject('html');
                    }
                }

                return;
            }
            if (is_array($node)) {
                foreach ($node as $entry) {
                    $walk($entry);
                }
            }
        };
        $walk($evidence);
    }

    private static function reject(string $section): AnalysisFailedException
    {
        return new AnalysisFailedException('evidence_rejected', 'The backend returned invalid '.$section.' evidence. Its output was discarded.');
    }
}
