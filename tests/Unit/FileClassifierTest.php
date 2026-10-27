<?php

namespace Tests\Unit;

use App\Services\Revival\FileClassifier;
use PHPUnit\Framework\TestCase;

class FileClassifierTest extends TestCase
{
    private function config(array $overrides = []): array
    {
        return array_merge([
            'max_depth' => 6,
            'max_download_file_bytes' => 1000000,
            'max_extraction_bytes' => 100000,
            'exclude_dirs' => [],
        ], $overrides);
    }

    public function test_supports_re_harmony_source_formats(): void
    {
        // Previously skipped as "extension not allowlisted".
        foreach ([
            'tools/libconcord/src/libconcord.cpp' => 'C++',
            'tools/libconcord/src/libconcord.h' => 'C/C++',
            'tools/libconcord/src/remote.cpp' => 'C++',
            'tools/libconcord/src/protocol.h' => 'C/C++',
            'config_work/flash_dump.c' => 'C',
            'app/ui/index.html' => 'HTML',
            'app/ui/learn.js' => 'JavaScript',
            'tools/libconcord/libconcord-re-harmony.patch' => 'Patch',
            'app/api.py' => 'Python',
            'app/ui/app.js' => 'JavaScript',
        ] as $path => $language) {
            $result = FileClassifier::classify($path, 5000, $this->config());
            $this->assertTrue($result['eligible'], $path);
            $this->assertSame($language, $result['language'], $path);
            $this->assertNull($result['reason'], $path);
        }
    }

    public function test_supports_embedded_and_build_formats(): void
    {
        foreach ([
            'firmware/main.rs' => ['Rust', 'source'],
            'cmd/tool/main.go' => ['Go', 'source'],
            'app/Main.java' => ['Java', 'source'],
            'app/app.kt' => ['Kotlin', 'source'],
            'ui/app.ts' => ['TypeScript', 'source'],
            'ui/app.tsx' => ['TypeScript', 'source'],
            'ui/app.css' => ['CSS', 'source'],
            'scripts/flash.sh' => ['Shell', 'script'],
            'CMakeLists.txt' => ['CMake', 'build'],
            'src/fw.cmx' => [null, 'unknown'],
            'linker/stm32.ld' => ['Linker script', 'build'],
            'board/rev2.dts' => ['DeviceTree', 'config'],
            'platformio.ini' => ['INI', 'config'],
            'firmware/sketch.ino' => ['Arduino', 'source'],
            'rtl/uart.v' => ['Verilog', 'source'],
            'rtl/uart.vhd' => ['VHDL', 'source'],
            'proto/remote.proto' => ['Protocol Buffers', 'source'],
            'Dockerfile' => ['Docker', 'build'],
            'Makefile' => ['Make', 'build'],
            'deploy/Dockerfile.cuda' => ['Docker', 'build'],
            'Kconfig' => ['Kconfig', 'config'],
            'SECURITY.md' => [null, 'doc'],
            '.github/workflows/build.yml' => ['YAML', 'config'],
        ] as $path => [$language, $kind]) {
            $result = FileClassifier::classify($path, 1000, $this->config());
            $this->assertTrue($result['eligible'], $path);
            $this->assertSame($language, $result['language'], $path);
            $this->assertSame($kind, $result['kind'], $path);
        }
    }

    public function test_recognizes_extensionless_files(): void
    {
        $this->assertSame('readme', FileClassifier::classify('README', 10, $this->config())['kind']);
        $this->assertSame('license', FileClassifier::classify('LICENSE-MIT', 10, $this->config())['kind']);
        $this->assertSame('build', FileClassifier::classify('Makefile.linux', 10, $this->config())['kind']);

        // Unknown extensionless files stay eligible for content probing.
        $result = FileClassifier::classify('tools/flash-device', 2000, $this->config());
        $this->assertTrue($result['eligible']);
        $this->assertSame('unknown', $result['kind']);
    }

    public function test_excludes_binaries_media_archives_and_firmware(): void
    {
        foreach ([
            'fw/app.bin', 'fw/app.hex', 'fw/app.elf', 'fw/app.uf2', 'fw/app.dfu',
            'docs/photo.png', 'docs/demo.mp4', 'assets/font.woff2', 'docs/spec.pdf',
            'vendor.tar.gz', 'release.zip', 'app.exe', 'lib/driver.so', 'model.onnx',
        ] as $path) {
            $result = FileClassifier::classify($path, 100, $this->config());
            $this->assertFalse($result['eligible'], $path);
            $this->assertSame('unsupported format', $result['reason'], $path);
        }
    }

    public function test_excludes_secrets_and_generated_artifacts(): void
    {
        foreach (['.env', 'prod.env', 'deploy/server.pem', 'deploy/server.key', 'id_rsa', 'secrets.yaml', '.npmrc'] as $path) {
            $result = FileClassifier::classify($path, 100, $this->config());
            $this->assertFalse($result['eligible'], $path);
            $this->assertSame('possible secret — excluded from evidence', $result['reason'], $path);
        }

        // Templates are safe and useful.
        $this->assertTrue(FileClassifier::classify('.env.example', 100, $this->config())['eligible']);

        foreach (['static/app.min.js', 'static/app.bundle.js', 'gen/api_pb2.py', 'gen/api.pb.go', 'debug.log'] as $path) {
            $result = FileClassifier::classify($path, 100, $this->config());
            $this->assertFalse($result['eligible'], $path);
            $this->assertSame('generated artifact', $result['reason'], $path);
        }
    }

    public function test_lockfiles_stay_eligible_but_marked(): void
    {
        foreach (['package-lock.json', 'composer.lock', 'Cargo.lock', 'go.sum', 'poetry.lock'] as $path) {
            $result = FileClassifier::classify($path, 5000, $this->config());
            $this->assertTrue($result['eligible'], $path);
            $this->assertSame('lockfile', $result['kind'], $path);
        }
    }

    public function test_excludes_vendored_dirs_depth_and_huge_blobs(): void
    {
        $result = FileClassifier::classify('node_modules/pkg/index.js', 100, $this->config());
        $this->assertFalse($result['eligible']);
        $this->assertSame('excluded directory', $result['reason']);

        $result = FileClassifier::classify('third_party/hal/hal.c', 100, $this->config());
        $this->assertSame('excluded directory', $result['reason']);

        $result = FileClassifier::classify('a/b/c/d/e/f/g.c', 100, $this->config());
        $this->assertSame('exceeds depth limit', $result['reason']);

        $result = FileClassifier::classify('data/big.csv', 2000000, $this->config());
        $this->assertFalse($result['eligible']);
        $this->assertSame('file too large for extraction', $result['reason']);

        // Unknown formats over the extraction cap cannot be probed cheaply.
        $result = FileClassifier::classify('data/blob.xyz', 500000, $this->config());
        $this->assertSame('unsupported format', $result['reason']);
    }

    public function test_subsystem_and_overview_helpers(): void
    {
        $this->assertSame('(root)', FileClassifier::subsystem('README.md'));
        $this->assertSame('tools', FileClassifier::subsystem('tools/libconcord/src/remote.cpp'));

        $this->assertTrue(FileClassifier::isOverview('README.md', 'readme'));
        $this->assertTrue(FileClassifier::isOverview('docs/ARCHITECTURE.md', 'doc'));
        $this->assertTrue(FileClassifier::isOverview('research/notes.md', 'doc'));
        $this->assertFalse(FileClassifier::isOverview('app/main.py', 'source'));
    }
}
