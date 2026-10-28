<?php

namespace App\Services\Revival;

/**
 * Deterministic architectural signals computed locally from evidence.
 *
 * These heuristics never call a model: they extract entry-point
 * candidates, import edges, configuration files, and external-content
 * markers so the investigator grounds its diagram in measured facts.
 * Pure static helpers over plain arrays; framework-free for unit tests.
 */
final class ArchitecturalSignals
{
    public const MAX_ENTRY_POINTS = 20;

    public const MAX_IMPORTS_PER_FILE = 30;

    public const MAX_IMPORT_FILES = 300;

    public const MAX_CONFIGS = 60;

    public const MAX_EXTERNAL_NOTES = 20;

    public const MAX_SCAN_BYTES = 200_000;

    /**
     * @param  list<array{path: string, content?: string, language?: ?string}>  $included
     * @return array{entry_points: list<array{path: string, reason: string}>, imports: array<string, list<string>>, configs: list<array{path: string, kind: string}>, external_notes: list<array{path: string, note: string}>, stats: array{files: int, bytes: int, languages: array<string, int>}}
     */
    public static function fromEvidence(array $included): array
    {
        $entryPoints = [];
        $imports = [];
        $configs = [];
        $externalNotes = [];
        $languages = [];
        $bytes = 0;
        $importFiles = 0;

        foreach ($included as $item) {
            $path = (string) ($item['path'] ?? '');
            if ($path === '') {
                continue;
            }

            $content = (string) ($item['content'] ?? '');
            $language = $item['language'] ?? self::guessLanguage($path);
            $bytes += strlen($content);

            if (is_string($language) && $language !== '') {
                $languages[$language] = ($languages[$language] ?? 0) + 1;
            }

            if (count($entryPoints) < self::MAX_ENTRY_POINTS) {
                $reason = self::entryPointReason($path, $content);
                if ($reason !== null) {
                    $entryPoints[] = ['path' => $path, 'reason' => $reason];
                }
            }

            if (count($configs) < self::MAX_CONFIGS) {
                $kind = self::configKind($path);
                if ($kind !== null) {
                    $configs[] = ['path' => $path, 'kind' => $kind];
                }
            }

            if (count($externalNotes) < self::MAX_EXTERNAL_NOTES) {
                $note = self::externalNote($path, $content);
                if ($note !== null) {
                    $externalNotes[] = ['path' => $path, 'note' => $note];
                }
            }

            if ($importFiles < self::MAX_IMPORT_FILES) {
                $edges = self::extractImports($path, $content);
                if ($edges !== []) {
                    $imports[$path] = $edges;
                    $importFiles++;
                }
            }
        }

        arsort($languages);

        return [
            'entry_points' => $entryPoints,
            'imports' => $imports,
            'configs' => $configs,
            'external_notes' => $externalNotes,
            'stats' => ['files' => count($included), 'bytes' => $bytes, 'languages' => $languages],
        ];
    }

    private static function entryPointReason(string $path, string $content): ?string
    {
        $base = strtolower(basename($path));

        if (preg_match('/^(readme|index|main|app|server|cli|program)(\.[a-z0-9]+)?$/', $base)) {
            return 'conventional entry-point filename';
        }

        if ($base === 'package.json' && ($main = self::packageMain($content)) !== null) {
            return "package.json main/bin entry: {$main}";
        }

        if ($base === 'go.mod' && preg_match('/^\s*module\s+(\S+)/m', $content, $m)) {
            return "Go module root: {$m[1]}";
        }

        if ($base === 'cargo.toml' && str_contains($content, '[[bin]]')) {
            return 'Cargo binary target declared';
        }

        if (($base === 'makefile' || str_ends_with($base, '.mk')) && preg_match('/^\s*all\s*:/m', $content)) {
            return 'default make target declared';
        }

        return null;
    }

    private static function packageMain(string $content): ?string
    {
        try {
            $decoded = json_decode($content, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        if (isset($decoded['main']) && is_string($decoded['main'])) {
            return $decoded['main'];
        }

        if (isset($decoded['bin']) && is_array($decoded['bin'])) {
            $first = reset($decoded['bin']);

            return is_string($first) ? $first : null;
        }

        return null;
    }

    private static function configKind(string $path): ?string
    {
        $base = strtolower(basename($path));

        if (str_starts_with($base, 'dockerfile') || $base === 'docker-compose.yml' || $base === 'docker-compose.yaml' || $base === 'compose.yaml' || $base === 'compose.yml') {
            return 'container';
        }

        if ($base === 'makefile' || $base === 'cmakelists.txt' || str_ends_with($base, '.mk')
            || in_array($base, ['package.json', 'composer.json', 'cargo.toml', 'go.mod', 'go.sum', 'pyproject.toml'], true)
            || str_starts_with($base, 'requirements') && str_ends_with($base, '.txt')) {
            return 'manifest';
        }

        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        if (in_array($ext, ['json', 'yaml', 'yml', 'toml', 'ini', 'cfg', 'conf', 'env'], true)) {
            return 'config';
        }

        if (in_array($base, ['.env.example', '.env.sample', 'setup.cfg', 'pytest.ini', 'tox.ini'], true)) {
            return 'config';
        }

        return null;
    }

    private static function externalNote(string $path, string $content): ?string
    {
        if (strtolower(basename($path)) === '.gitmodules') {
            return 'git submodules declared; submodule contents were not imported';
        }

        if (str_starts_with($content, 'version https://git-lfs')) {
            return 'Git LFS pointer file; file contents were not imported';
        }

        return null;
    }

    /** @return list<string> */
    private static function extractImports(string $path, string $content): array
    {
        if ($content === '') {
            return [];
        }

        $sample = strlen($content) > self::MAX_SCAN_BYTES ? substr($content, 0, self::MAX_SCAN_BYTES) : $content;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $edges = [];

        $collect = function (array $matches) use (&$edges): void {
            foreach ($matches as $match) {
                $value = trim((string) $match);
                if ($value === '' || str_contains($value, "\n")) {
                    continue;
                }
                $edges[$value] = true;
                if (count($edges) >= self::MAX_IMPORTS_PER_FILE) {
                    break;
                }
            }
        };

        switch ($ext) {
            case 'go':
                if (preg_match_all('/^\s*(?:[\w.]+\s+)?"([^"]+)"/m', $sample, $m)) {
                    $collect($m[1]);
                }
                break;
            case 'py':
                if (preg_match_all('/^\s*from\s+([\w.]+)\s+import\s+/m', $sample, $m)) {
                    $collect($m[1]);
                }
                if (preg_match_all('/^\s*import\s+([\w.,\s]+)$/m', $sample, $m)) {
                    foreach ($m[1] as $line) {
                        $collect(preg_split('/\s*,\s*/', trim($line)) ?: []);
                    }
                }
                break;
            case 'js':
            case 'mjs':
            case 'cjs':
            case 'jsx':
            case 'ts':
            case 'tsx':
            case 'vue':
                if (preg_match_all('/import\s+(?:[^;\'"]*?\s+from\s+)?[\'"]([^\'"]+)[\'"]/m', $sample, $m)) {
                    $collect($m[1]);
                }
                if (preg_match_all('/require\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $sample, $m)) {
                    $collect($m[1]);
                }
                break;
            case 'php':
                if (preg_match_all('/^\s*use\s+([\w\\\\]+);/m', $sample, $m)) {
                    $collect($m[1]);
                }
                break;
            case 'c':
            case 'h':
            case 'cc':
            case 'cpp':
            case 'hpp':
                if (preg_match_all('/^#\s*include\s+[<"]([^>"]+)[>"]/m', $sample, $m)) {
                    $collect($m[1]);
                }
                break;
            case 'rs':
                if (preg_match_all('/^\s*use\s+([\w:]+)(?:::|\s*;)/m', $sample, $m)) {
                    $collect($m[1]);
                }
                break;
        }

        return array_keys($edges);
    }

    private static function guessLanguage(string $path): ?string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'php' => 'php',
            'py' => 'python',
            'js', 'mjs', 'cjs', 'jsx' => 'javascript',
            'ts', 'tsx' => 'typescript',
            'go' => 'go',
            'rs' => 'rust',
            'c', 'h' => 'c',
            'cc', 'cpp', 'hpp' => 'cpp',
            'md', 'mdx' => 'markdown',
            default => null,
        };
    }
}
