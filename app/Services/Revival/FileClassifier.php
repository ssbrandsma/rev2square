<?php

namespace App\Services\Revival;

/**
 * Deterministic file classification for repository inventory.
 *
 * Pure function of (path, size, config): extension/language detection,
 * filename recognition for extensionless files, and import-eligibility
 * policy. Unknown text candidates stay eligible for probing (verified by
 * safe text detection after download); binaries, media, archives, firmware
 * images, secrets and generated artifacts are inventoried but excluded
 * from AI evidence.
 *
 * @return array{extension: ?string, language: ?string, kind: string, eligible: bool, reason: ?string}
 */
class FileClassifier
{
    /** extension => [language, kind] */
    private const EXTENSIONS = [
        // Sources.
        'py' => ['Python', 'source'], 'pyw' => ['Python', 'source'], 'pyi' => ['Python', 'source'],
        'c' => ['C', 'source'], 'h' => ['C/C++', 'source'],
        'cpp' => ['C++', 'source'], 'cc' => ['C++', 'source'], 'cxx' => ['C++', 'source'],
        'hpp' => ['C++', 'source'], 'hh' => ['C++', 'source'], 'hxx' => ['C++', 'source'],
        'ino' => ['Arduino', 'source'], 'pde' => ['Arduino', 'source'],
        'rs' => ['Rust', 'source'],
        'go' => ['Go', 'source'],
        'java' => ['Java', 'source'], 'kt' => ['Kotlin', 'source'], 'kts' => ['Kotlin', 'source'],
        'js' => ['JavaScript', 'source'], 'mjs' => ['JavaScript', 'source'], 'cjs' => ['JavaScript', 'source'],
        'jsx' => ['JavaScript', 'source'],
        'ts' => ['TypeScript', 'source'], 'mts' => ['TypeScript', 'source'], 'cts' => ['TypeScript', 'source'],
        'tsx' => ['TypeScript', 'source'],
        'vue' => ['Vue', 'source'], 'svelte' => ['Svelte', 'source'],
        'php' => ['PHP', 'source'],
        'rb' => ['Ruby', 'source'], 'lua' => ['Lua', 'source'], 'pl' => ['Perl', 'source'],
        'swift' => ['Swift', 'source'], 'cs' => ['C#', 'source'], 'dart' => ['Dart', 'source'],
        'scala' => ['Scala', 'source'], 'hs' => ['Haskell', 'source'], 'ml' => ['OCaml', 'source'],
        'zig' => ['Zig', 'source'], 'nim' => ['Nim', 'source'], 'r' => ['R', 'source'],
        'jl' => ['Julia', 'source'], 'ex' => ['Elixir', 'source'], 'exs' => ['Elixir', 'source'],
        'erl' => ['Erlang', 'source'], 'hrl' => ['Erlang', 'source'],
        'v' => ['Verilog', 'source'], 'sv' => ['SystemVerilog', 'source'],
        'vhd' => ['VHDL', 'source'], 'vhdl' => ['VHDL', 'source'],
        'asm' => ['Assembly', 'source'], 's' => ['Assembly', 'source'],
        'proto' => ['Protocol Buffers', 'source'], 'thrift' => ['Thrift', 'source'],
        'sql' => ['SQL', 'source'], 'graphql' => ['GraphQL', 'source'], 'gql' => ['GraphQL', 'source'],
        'm' => ['Objective-C', 'source'], 'mm' => ['Objective-C++', 'source'],
        // Web.
        'html' => ['HTML', 'source'], 'htm' => ['HTML', 'source'], 'xhtml' => ['HTML', 'source'],
        'css' => ['CSS', 'source'], 'scss' => ['SCSS', 'source'], 'sass' => ['Sass', 'source'], 'less' => ['Less', 'source'],
        // Shell / scripts.
        'sh' => ['Shell', 'script'], 'bash' => ['Shell', 'script'], 'zsh' => ['Shell', 'script'],
        'fish' => ['Shell', 'script'], 'ksh' => ['Shell', 'script'], 'dash' => ['Shell', 'script'],
        'ps1' => ['PowerShell', 'script'], 'psm1' => ['PowerShell', 'script'],
        'bat' => ['Batch', 'script'], 'cmd' => ['Batch', 'script'],
        'awk' => ['Awk', 'script'], 'sed' => ['sed', 'script'], 'tcl' => ['Tcl', 'script'],
        // Build.
        'cmake' => ['CMake', 'build'], 'mk' => ['Make', 'build'], 'mak' => ['Make', 'build'],
        'gradle' => ['Gradle', 'build'], 'sbt' => ['SBT', 'build'],
        'bazel' => ['Bazel', 'build'], 'bzl' => ['Bazel', 'build'],
        'ninja' => ['Ninja', 'build'], 'gyp' => ['GYP', 'build'], 'gypi' => ['GYP', 'build'],
        'csproj' => ['MSBuild', 'build'], 'vcxproj' => ['MSBuild', 'build'], 'sln' => ['MSBuild', 'build'],
        'pbxproj' => ['Xcode', 'build'], 'xcodeproj' => ['Xcode', 'build'],
        'ld' => ['Linker script', 'build'], 'lds' => ['Linker script', 'build'], 'icf' => ['Linker script', 'build'],
        // Config / data (text).
        'json' => ['JSON', 'config'], 'json5' => ['JSON', 'config'], 'jsonc' => ['JSON', 'config'],
        'yml' => ['YAML', 'config'], 'yaml' => ['YAML', 'config'],
        'toml' => ['TOML', 'config'], 'xml' => ['XML', 'config'], 'xsd' => ['XML', 'config'],
        'ini' => ['INI', 'config'], 'cfg' => ['Config', 'config'], 'conf' => ['Config', 'config'],
        'config' => ['Config', 'config'], 'properties' => ['Properties', 'config'],
        'env' => ['Dotenv', 'config'], 'editorconfig' => ['EditorConfig', 'config'],
        'plist' => ['plist', 'config'], 'service' => ['systemd', 'config'], 'socket' => ['systemd', 'config'],
        'timer' => ['systemd', 'config'], 'desktop' => ['Desktop entry', 'config'],
        'dts' => ['DeviceTree', 'config'], 'dtsi' => ['DeviceTree', 'config'], 'overlay' => ['DeviceTree', 'config'],
        'kconfig' => ['Kconfig', 'config'],
        'csv' => ['CSV', 'data'], 'tsv' => ['TSV', 'data'], 'geojson' => ['GeoJSON', 'data'],
        // Docs.
        'md' => ['Markdown', 'doc'], 'markdown' => ['Markdown', 'doc'],
        'rst' => ['reStructuredText', 'doc'], 'adoc' => ['AsciiDoc', 'doc'], 'asciidoc' => ['AsciiDoc', 'doc'],
        'txt' => ['Text', 'doc'], 'textile' => ['Textile', 'doc'], 'org' => ['Org', 'doc'], 'wiki' => ['Wiki', 'doc'],
        'tex' => ['LaTeX', 'doc'],
        // Patches.
        'patch' => ['Patch', 'patch'], 'diff' => ['Patch', 'patch'],
        // Binary / media / archives / firmware: inventoried, never evidence.
        'png' => [null, 'media'], 'jpg' => [null, 'media'], 'jpeg' => [null, 'media'],
        'gif' => [null, 'media'], 'webp' => [null, 'media'], 'bmp' => [null, 'media'],
        'tiff' => [null, 'media'], 'ico' => [null, 'media'], 'svg' => [null, 'media'],
        'mp3' => [null, 'media'], 'wav' => [null, 'media'], 'ogg' => [null, 'media'],
        'mp4' => [null, 'media'], 'avi' => [null, 'media'], 'mov' => [null, 'media'], 'webm' => [null, 'media'],
        'pdf' => [null, 'media'],
        'ttf' => [null, 'media'], 'otf' => [null, 'media'], 'woff' => [null, 'media'], 'woff2' => [null, 'media'],
        'zip' => [null, 'archive'], 'tar' => [null, 'archive'], 'gz' => [null, 'archive'],
        'tgz' => [null, 'archive'], 'bz2' => [null, 'archive'], 'xz' => [null, 'archive'],
        '7z' => [null, 'archive'], 'rar' => [null, 'archive'], 'jar' => [null, 'archive'],
        'war' => [null, 'archive'], 'whl' => [null, 'archive'], 'gem' => [null, 'archive'],
        'hex' => [null, 'firmware'], 'bin' => [null, 'firmware'], 'elf' => [null, 'firmware'],
        'axf' => [null, 'firmware'], 'dfu' => [null, 'firmware'], 'uf2' => [null, 'firmware'],
        'fw' => [null, 'firmware'], 'img' => [null, 'firmware'], 'iso' => [null, 'firmware'],
        'o' => [null, 'binary'], 'a' => [null, 'binary'], 'so' => [null, 'binary'],
        'dll' => [null, 'binary'], 'exe' => [null, 'binary'], 'dylib' => [null, 'binary'],
        'pyc' => [null, 'binary'], 'pyo' => [null, 'binary'], 'class' => [null, 'binary'],
        'sqlite' => [null, 'binary'], 'db' => [null, 'binary'], 'parquet' => [null, 'binary'],
        'npy' => [null, 'binary'], 'npz' => [null, 'binary'], 'onnx' => [null, 'binary'],
        'pb' => [null, 'binary'], 'tflite' => [null, 'binary'], 'pt' => [null, 'binary'],
    ];

    /** Basename (lowercased, without extension) => [language, kind]. */
    private const BASENAMES = [
        'readme' => [null, 'readme'],
        'license' => [null, 'license'], 'licence' => [null, 'license'], 'copying' => [null, 'license'],
        'notice' => [null, 'license'], 'authors' => [null, 'doc'], 'changelog' => [null, 'doc'],
        'changes' => [null, 'doc'], 'history' => [null, 'doc'], 'news' => [null, 'doc'],
        'contributing' => [null, 'doc'], 'code_of_conduct' => [null, 'doc'], 'security' => [null, 'doc'],
        'support' => [null, 'doc'], 'architecture' => [null, 'doc'],
        'makefile' => ['Make', 'build'], 'gnumakefile' => ['Make', 'build'],
        'dockerfile' => ['Docker', 'build'], 'containerfile' => ['Docker', 'build'],
        'cmakelists' => ['CMake', 'build'], 'meson.build' => ['Meson', 'build'],
        'kconfig' => ['Kconfig', 'config'],
        'vagrantfile' => ['Ruby', 'build'], 'jenkinsfile' => ['Groovy', 'build'],
        'gemfile' => ['Ruby', 'build'], 'rakefile' => ['Ruby', 'build'],
        'go.mod' => ['Go', 'build'], 'go.sum' => ['Go', 'lockfile'], 'go.work' => ['Go', 'build'],
        'cargo.toml' => ['TOML', 'build'], 'cargo.lock' => ['TOML', 'lockfile'],
        'package.json' => ['JSON', 'build'], 'package-lock.json' => ['JSON', 'lockfile'],
        'pnpm-lock.yaml' => ['YAML', 'lockfile'], 'yarn.lock' => [null, 'lockfile'],
        'composer.json' => ['JSON', 'build'], 'composer.lock' => ['JSON', 'lockfile'],
        'pyproject.toml' => ['TOML', 'build'], 'setup.py' => ['Python', 'build'],
        'setup.cfg' => ['INI', 'build'], 'poetry.lock' => ['TOML', 'lockfile'],
        'pipfile' => ['TOML', 'build'], 'pipfile.lock' => ['JSON', 'lockfile'],
        'requirements' => [null, 'build'], 'constraints' => [null, 'build'],
        'pom.xml' => ['XML', 'build'], 'build.gradle' => ['Gradle', 'build'],
        'settings.gradle' => ['Gradle', 'build'], 'gradle.lockfile' => [null, 'lockfile'],
        'gemfile.lock' => [null, 'lockfile'],
        '.gitignore' => [null, 'config'], '.gitattributes' => [null, 'config'],
        '.gitmodules' => [null, 'config'], '.editorconfig' => [null, 'config'],
        '.dockerignore' => [null, 'config'],
    ];

    private const DEFAULT_EXCLUDE_DIRS = [
        'node_modules', 'vendor', 'third_party', 'thirdparty', 'externals', 'external',
        '.git', '__pycache__', '.venv', 'venv', '.tox', 'target', 'dist', 'build', 'out',
        '.gradle', '.idea', '.vscode', 'pods', '.svn', '.hg', '_build', 'deps',
        '.dart_tool', '.next', '.nuxt', 'coverage', 'site-packages',
    ];

    public static function classify(string $path, int $size = 0, array $config = []): array
    {
        $basename = strtolower(basename($path));
        $stem = strtolower(pathinfo($basename, PATHINFO_FILENAME));
        $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
        $extension = $extension === '' ? null : $extension;

        if (self::looksLikeSecret($basename, $extension)) {
            return self::result($extension, null, 'secret', false, 'possible secret — excluded from evidence');
        }

        $excludeDirs = array_unique(array_merge(
            self::DEFAULT_EXCLUDE_DIRS,
            $config['exclude_dirs'] ?? []
        ));

        foreach (explode('/', $path) as $segment) {
            if (in_array($segment, $excludeDirs, true)) {
                [$language, $kind] = self::kindFor($basename, $stem, $extension);

                return self::result($extension, $language, $kind, false, 'excluded directory');
            }
        }

        if (count(explode('/', $path)) > ($config['max_depth'] ?? 6)) {
            [$language, $kind] = self::kindFor($basename, $stem, $extension);

            return self::result($extension, $language, $kind, false, 'exceeds depth limit');
        }

        [$language, $kind] = self::kindFor($basename, $stem, $extension);

        if (in_array($kind, ['media', 'archive', 'firmware', 'binary'], true)) {
            return self::result($extension, $language, $kind, false, 'unsupported format');
        }

        if (self::looksGenerated($basename)) {
            return self::result($extension, $language, 'generated', false, 'generated artifact');
        }

        $downloadCap = $config['max_download_file_bytes'] ?? 1000000;

        if ($size > $downloadCap) {
            return self::result($extension, $language, $kind, false, 'file too large for extraction');
        }

        // Unknown formats stay eligible for probing only when cheap to verify.
        if ($kind === 'unknown' && $size > ($config['max_extraction_bytes'] ?? $config['max_file_bytes'] ?? 100000)) {
            return self::result($extension, $language, $kind, false, 'unsupported format');
        }

        return self::result($extension, $language, $kind, true, null);
    }

    /** @return array{0: ?string, 1: string} */
    private static function kindFor(string $basename, string $stem, ?string $extension): array
    {
        // Exact basename match first (handles extensionless + dotted names).
        if (isset(self::BASENAMES[$basename])) {
            return self::BASENAMES[$basename];
        }

        // Suffixed variants: LICENSE-MIT, README_2024, Dockerfile.cuda, Makefile.linux.
        $root = preg_split('/[.\-_]/', $basename, 2)[0];

        if (isset(self::BASENAMES[$root]) && in_array(self::BASENAMES[$root][1], ['readme', 'license', 'doc', 'build'], true)) {
            return self::BASENAMES[$root];
        }

        if ($basename === 'cmakelists.txt' || $basename === 'meson.build') {
            return self::BASENAMES[$basename];
        }

        if ($extension !== null && isset(self::EXTENSIONS[$extension])) {
            return self::EXTENSIONS[$extension];
        }

        // requirements-dev.txt, constraints-ci.txt style build metadata.
        if (str_starts_with($stem, 'requirements') || str_starts_with($stem, 'constraints')) {
            return [null, 'build'];
        }

        return [null, 'unknown'];
    }

    private static function looksLikeSecret(string $basename, ?string $extension): bool
    {
        if ($basename === '.env' || str_ends_with($basename, '.env.local')) {
            return true;
        }

        // Any live dotenv file is a likely secret; only committed templates
        // (*.example / *.sample / ...) stay eligible as config.
        if ($extension === 'env' && ! str_contains($basename, 'example')
            && ! str_contains($basename, 'sample') && ! str_contains($basename, 'template')
            && ! str_contains($basename, 'dist')) {
            return true;
        }

        if (in_array($extension, ['pem', 'key', 'p12', 'pfx', 'ppk', 'priv'], true)) {
            return true;
        }

        if (in_array($basename, ['.npmrc', '.pypirc', '.git-credentials', '.netrc', '_netrc', 'credentials.json'], true)) {
            return true;
        }

        foreach (['id_rsa', 'id_dsa', 'id_ed25519', 'id_ecdsa'] as $prefix) {
            if (str_starts_with($basename, $prefix)) {
                return true;
            }
        }

        if ($basename === 'secrets.yaml' || $basename === 'secrets.yml' || $basename === 'secrets.json') {
            return true;
        }

        return false;
    }

    private static function looksGenerated(string $basename): bool
    {
        if (str_ends_with($basename, '.min.js') || str_ends_with($basename, '.min.css')
            || str_ends_with($basename, '.bundle.js') || str_ends_with($basename, '.bundle.css')
            || str_ends_with($basename, '.js.map') || str_ends_with($basename, '.css.map')
            || str_ends_with($basename, '.pb.go') || str_ends_with($basename, '_pb2.py')
            || str_ends_with($basename, '.g.dart') || str_ends_with($basename, '.freezed.dart')
            || str_ends_with($basename, '.d.ts.map')) {
            return true;
        }

        if (str_contains($basename, '.generated.')) {
            return true;
        }

        if (str_ends_with($basename, '.log')) {
            return true;
        }

        return false;
    }

    /** Top-level directory, or '(root)' for root-level files. */
    public static function subsystem(string $path): string
    {
        $pos = strpos($path, '/');

        return $pos === false ? '(root)' : substr($path, 0, $pos);
    }

    /** Files that explain what the repository is (ordered before implementation). */
    public static function isOverview(string $path, string $kind): bool
    {
        if ($kind === 'readme') {
            return true;
        }

        $basename = strtolower(basename($path));

        if (str_starts_with($basename, 'architecture')) {
            return true;
        }

        return $kind === 'doc' && str_contains(strtolower($path), 'research/');
    }

    /** @return array{extension: ?string, language: ?string, kind: string, eligible: bool, reason: ?string} */
    private static function result(?string $extension, ?string $language, string $kind, bool $eligible, ?string $reason): array
    {
        return [
            'extension' => $extension,
            'language' => $language,
            'kind' => $kind,
            'eligible' => $eligible,
            'reason' => $reason,
        ];
    }
}
