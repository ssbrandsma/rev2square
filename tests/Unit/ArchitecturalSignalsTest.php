<?php

namespace Tests\Unit;

use App\Services\Revival\ArchitecturalSignals;
use PHPUnit\Framework\TestCase;

class ArchitecturalSignalsTest extends TestCase
{
    public function test_entry_points_cover_conventions_and_manifests(): void
    {
        $signals = ArchitecturalSignals::fromEvidence([
            ['path' => 'app/main.py', 'content' => "print('hi')\n"],
            ['path' => 'package.json', 'content' => json_encode(['main' => 'dist/index.js'])],
            ['path' => 'go.mod', 'content' => "module example.com/demo\n\ngo 1.21\n"],
            ['path' => 'lib/util.py', 'content' => "x = 1\n"],
        ]);

        $paths = array_column($signals['entry_points'], 'path');

        $this->assertContains('app/main.py', $paths);
        $this->assertContains('package.json', $paths);
        $this->assertContains('go.mod', $paths);
        $this->assertNotContains('lib/util.py', $paths);
    }

    public function test_imports_are_extracted_per_language(): void
    {
        $signals = ArchitecturalSignals::fromEvidence([
            ['path' => 'a.py', 'content' => "import os, sys\nfrom lib.helper import run\n"],
            ['path' => 'b.js', 'content' => "import x from './x.js';\nconst y = require('y');\n"],
            ['path' => 'c.php', 'content' => "<?php\nuse App\\Models\\User;\n"],
            ['path' => 'd.go', 'content' => "package main\n\nimport (\n\t\"fmt\"\n\t\"example.com/lib\"\n)\n"],
            ['path' => 'e.c', 'content' => "#include <stdio.h>\n#include \"local.h\"\n"],
        ]);

        $this->assertContains('os', $signals['imports']['a.py']);
        $this->assertContains('lib.helper', $signals['imports']['a.py']);
        $this->assertContains('./x.js', $signals['imports']['b.js']);
        $this->assertContains('y', $signals['imports']['b.js']);
        $this->assertContains('App\\Models\\User', $signals['imports']['c.php']);
        $this->assertContains('example.com/lib', $signals['imports']['d.go']);
        $this->assertContains('local.h', $signals['imports']['e.c']);
    }

    public function test_configs_and_manifests_are_classified(): void
    {
        $signals = ArchitecturalSignals::fromEvidence([
            ['path' => 'Dockerfile', 'content' => "FROM php:8.2\n"],
            ['path' => 'composer.json', 'content' => '{}'],
            ['path' => 'config/settings.yaml', 'content' => "a: b\n"],
            ['path' => 'README.md', 'content' => "# hi\n"],
        ]);

        $kinds = [];
        foreach ($signals['configs'] as $config) {
            $kinds[$config['path']] = $config['kind'];
        }

        $this->assertSame('container', $kinds['Dockerfile']);
        $this->assertSame('manifest', $kinds['composer.json']);
        $this->assertSame('config', $kinds['config/settings.yaml']);
        $this->assertArrayNotHasKey('README.md', $kinds);
    }

    public function test_external_content_markers_are_flagged(): void
    {
        $signals = ArchitecturalSignals::fromEvidence([
            ['path' => '.gitmodules', 'content' => "[submodule]\n"],
            ['path' => 'models/weights.bin', 'content' => "version https://git-lfs.github.com/spec/v1\noid sha256:abc\n"],
        ]);

        $this->assertCount(2, $signals['external_notes']);
    }

    public function test_output_is_bounded_and_summarized(): void
    {
        $items = [];
        for ($i = 0; $i < 400; $i++) {
            $items[] = ['path' => "lib/mod{$i}.py", 'content' => "import os\n", 'language' => 'python'];
        }

        $signals = ArchitecturalSignals::fromEvidence($items);

        $this->assertLessThanOrEqual(ArchitecturalSignals::MAX_IMPORT_FILES, count($signals['imports']));
        $this->assertSame(400, $signals['stats']['files']);
        $this->assertSame(400, $signals['stats']['languages']['python']);
        $this->assertGreaterThan(0, $signals['stats']['bytes']);
    }
}
