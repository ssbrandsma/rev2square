<?php

namespace Tests\Feature;

use App\Models\AnalysisRun;
use App\Models\EvidenceSource;
use App\Models\Repository;
use App\Services\Revival\GitHubRepositoryImporter;
use App\Services\Revival\RevivalAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\MockAIProvider;
use Tests\TestCase;

/**
 * Real-world regression scenario modeled on RE-HARMONY
 * (https://github.com/Unkn0wn-dx/RE-HARMONY): a mixed Python / C / C++ /
 * JS hardware-revival repository whose first production import kept only
 * 19 of 175 files because firmware, protocol and UI sources were skipped.
 */
class ReHarmonyRegressionTest extends TestCase
{
    use RefreshDatabase;

    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    /** @var array<string, string> */
    private array $files = [];

    private function pyModule(string $module, int $targetBytes): string
    {
        $lines = [
            '"""'.$module.' module."""',
            'import usb.core',
            'import usb.util',
            'import logging',
            '',
            'logger = logging.getLogger(__name__)',
            '',
            'VENDOR_ID = 0x1234',
            '',
            '',
            'def open_device(vendor_id, product_id):',
            '    """Open the USB HID handle for the Harmony hub."""',
            '    handle = usb.core.find(idVendor=vendor_id, idProduct=product_id)',
            '    return handle',
            '',
            '',
        ];

        $i = 0;

        while (strlen(implode("\n", $lines)) < $targetBytes) {
            $i++;
            $lines[] = "def handle_packet_{$i}(handle, payload):";
            $lines[] = '    """Process one IR protocol packet."""';
            $lines[] = '    checksum = sum(payload) & 0xFFFF';
            $lines[] = '    handle.write(checksum.to_bytes(2, "little") + bytes(payload))';
            $lines[] = "    logger.debug(\"packet {$i} written\")";
            $lines[] = '    return checksum';
            $lines[] = '';
            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }

    private function cFile(string $module, int $targetBytes, bool $header = false): string
    {
        $lines = [
            '#include <stdint.h>',
            '#include <libusb.h>',
            '#include "protocol.h"',
            '',
            '#define VENDOR_ID 0x1234',
            '#define ENDPOINT_IN 0x81',
            '',
        ];

        if ($header) {
            $lines[] = '#pragma once';
            $lines[] = '';
            $lines[] = 'int remote_open(uint16_t vendor_id, uint16_t product_id);';
            $lines[] = 'int remote_send(int handle, const uint8_t *payload, size_t length);';
            $lines[] = 'void remote_close(int handle);';
            $lines[] = '';
        }

        $i = 0;

        while (strlen(implode("\n", $lines)) < $targetBytes) {
            $i++;
            $lines[] = "int {$module}_transfer_{$i}(int handle, const uint8_t *payload)";
            $lines[] = '{';
            $lines[] = '    int transferred = 0;';
            $lines[] = '    int rc = libusb_bulk_transfer(handle, ENDPOINT_IN, (uint8_t *) payload, 64, &transferred, 1000);';
            $lines[] = '    return rc == 0 ? transferred : rc;';
            $lines[] = '}';
            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }

    /** @return array<string, string> path => content */
    private function reHarmonyFiles(): array
    {
        if ($this->files !== []) {
            return $this->files;
        }

        $this->files = [
            'README.md' => "# RE-HARMONY\n\nRevive the Harmony hub locally.\n\nSee tools/libconcord/src/remote.cpp and app/main.py.\n",
            'LICENSE' => "MIT License\n",
            'docs/protocol.md' => "# IR protocol\n\nPacket layout and checksums.\n",
            'docs/setup.md' => "# Setup\n\nInstall dependencies.\n",
            'requirements.txt' => "pyusb==1.2.1\nflask==3.0.0\n",
            'Dockerfile' => "FROM python:3.12\nCOPY . /app\n",
            'docker-compose.yml' => "services:\n  app:\n    build: .\n",
            'CMakeLists.txt' => "cmake_minimum_required(VERSION 3.20)\nproject(concord)\n",
            'Makefile' => "all:\n\tgcc -o concord src/*.c\n",
            'platformio.ini' => "[env:esp32]\nplatform = espressif32\n",
            // Device communication + protocol implementation (previously skipped).
            'tools/libconcord/src/libconcord.cpp' => $this->cFile('libconcord', 8000),
            'tools/libconcord/src/libconcord.h' => $this->cFile('libconcord', 3000, true),
            'tools/libconcord/src/remote.cpp' => $this->cFile('remote', 6000),
            'tools/libconcord/src/protocol.h' => $this->cFile('protocol', 4000, true),
            'tools/libconcord/libconcord-re-harmony.patch' => "--- a/remote.c\n+++ b/remote.c\n@@ -1 +1 @@\n-old\n+new\n",
            'tools/flash-device' => "#!/bin/sh\n# Extensionless flasher script.\ndfu-util -D firmware.bin\n",
            'config_work/flash_dump.c' => $this->cFile('flash_dump', 4000),
            'config_work/device_db.py' => $this->pyModule('device_db', 18000),
            'config_work/migrate.py' => $this->pyModule('migrate', 9000),
            'config_work/validate.py' => $this->pyModule('validate', 7000),
            // Oversized sources (previously all-or-nothing skips).
            'app/main.py' => $this->pyModule('main', 120000),
            'app/api.py' => $this->pyModule('api', 110000),
            'app/remote.py' => $this->pyModule('remote', 20000),
            'app/session.py' => $this->pyModule('session', 15000),
            'app/library.py' => $this->pyModule('library', 12000),
            'app/ir_manual.py' => $this->pyModule('ir_manual', 90000),
            'app/key_template.py' => $this->pyModule('key_template', 85000),
            'app/learn_ir.py' => $this->pyModule('learn_ir', 80000),
            'app/config.yaml' => "hub:\n  host: 127.0.0.1\n",
            'config_work/add_device.py' => $this->pyModule('add_device', 105000),
            'app/ui/index.html' => "<html>\n<head><title>RE-HARMONY</title></head>\n<body>\n<form id=\"learn\">\n<input id=\"device\" />\n</form>\n</body>\n</html>\n",
            'app/ui/learn.js' => "import { api } from './api.js';\n\nexport function learn(device) {\n  return api.post('/learn', { device });\n}\n",
            'app/ui/app.js' => $this->jsApp(130000),
            'tests/test_remote.py' => "def test_open_device():\n    assert True\n",
            'tests/test_ir.py' => "def test_decode_ir():\n    assert True\n",
            // Never evidence, always inventoried.
            'firmware/dump.bin' => "binary\x00data",
            'assets/logo.png' => 'PNGDATA',
            '.env' => "HUB_TOKEN=secret\n",
            'id_rsa' => "-----BEGIN RSA PRIVATE KEY-----\n",
            'node_modules/pkg/index.js' => 'vendored',
            'vendor/hal/hal.c' => 'vendored',
            'static/app.min.js' => 'minified',
            'huge/dataset.csv' => 'too big for the download cap',
            'data/blob.xyz' => str_repeat('z', 500000),
        ];

        return $this->files;
    }

    private function jsApp(int $targetBytes): string
    {
        $lines = ["import { api } from './api.js';", ''];

        $i = 0;

        while (strlen(implode("\n", $lines)) < $targetBytes) {
            $i++;
            $lines[] = "export function handleEvent{$i}(event) {";
            $lines[] = '  const payload = JSON.stringify(event);';
            $lines[] = "  return api.post('/event', { payload });";
            $lines[] = '}';
            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }

    /** @param  array<string, string>  $files */
    private function fakeGitHub(array $files, array $sizes = []): void
    {
        Http::fake(function (Request $request) use ($files, $sizes) {
            $url = strtok($request->url(), '?');

            if (preg_match('#/repos/[^/]+/[^/]+$#', $url)) {
                return Http::response(['private' => false, 'default_branch' => 'main']);
            }

            if (str_contains($url, '/git/ref/heads/')) {
                return Http::response(['object' => ['sha' => self::SHA]]);
            }

            if (str_contains($url, '/git/trees/')) {
                $tree = [];

                foreach ($files as $path => $content) {
                    $tree[] = [
                        'path' => $path,
                        'type' => 'blob',
                        'size' => $sizes[$path] ?? strlen($content),
                        'sha' => sha1($path),
                    ];
                }

                return Http::response(['sha' => self::SHA, 'truncated' => false, 'tree' => $tree]);
            }

            if (str_contains($url, '/contents/')) {
                $path = substr($url, strpos($url, '/contents/') + strlen('/contents/'));
                $path = implode('/', array_map(rawurldecode(...), explode('/', $path)));
                $content = $files[$path] ?? '';

                return Http::response([
                    'encoding' => 'base64',
                    'content' => base64_encode($content),
                    'html_url' => 'https://github.com/Unkn0wn-dx/RE-HARMONY/blob/'.self::SHA.'/'.$path,
                    'size' => strlen($content),
                ]);
            }

            return Http::response(null, 404);
        });
    }

    private function repository(): Repository
    {
        return Repository::factory()->create([
            'owner' => 'Unkn0wn-dx',
            'name' => 'RE-HARMONY',
            'canonical_url' => 'https://github.com/Unkn0wn-dx/RE-HARMONY',
        ]);
    }

    public function test_re_harmony_import_captures_protocol_firmware_and_ui_evidence(): void
    {
        config([
            'revival.github.max_files' => 100,
            'revival.github.max_download_bytes' => 4000000,
            'revival.github.max_evidence_bytes' => 2000000,
            'revival.github.token_budget' => 500000,
        ]);

        $files = $this->reHarmonyFiles();
        $this->fakeGitHub($files, ['huge/dataset.csv' => 1500000]);
        $repository = $this->repository();

        $result = app(GitHubRepositoryImporter::class)->import($repository);

        $this->assertFalse($result['up_to_date']);
        $this->assertSame(count($files), $result['inventory']['blobs']);

        $stored = $repository->currentEvidence()->pluck('path')->all();

        // Previously skipped C/C++ protocol core is now evidence.
        foreach ([
            'tools/libconcord/src/libconcord.cpp',
            'tools/libconcord/src/libconcord.h',
            'tools/libconcord/src/remote.cpp',
            'tools/libconcord/src/protocol.h',
            'tools/libconcord/libconcord-re-harmony.patch',
            'config_work/flash_dump.c',
            'app/ui/index.html',
            'app/ui/learn.js',
            'tools/flash-device',
        ] as $path) {
            $this->assertContains($path, $stored, "expected evidence: {$path}");
        }

        // Oversized sources contribute partial excerpts with provenance.
        foreach ([
            'app/main.py' => 'def ',
            'app/api.py' => 'def ',
            'config_work/add_device.py' => 'def ',
            'app/ui/app.js' => 'function ',
        ] as $path => $symbol) {
            $row = $repository->currentEvidence()->where('path', $path)->firstOrFail();
            $this->assertTrue($row->isPartial(), $path);
            $this->assertNotEmpty($row->line_ranges, $path);
            $this->assertGreaterThan(strlen((string) $row->content), (int) $row->original_bytes, $path);
            $this->assertSame(sha1($path), $row->blob_sha, $path);
            $this->assertStringContainsString($symbol, (string) $row->content, $path);
        }

        // Subsystem coverage spans the repository.
        $coverage = $repository->last_import_summary['coverage'];
        $this->assertGreaterThanOrEqual(5, count($coverage));
        $this->assertArrayHasKey('tools', $coverage);
        $this->assertArrayHasKey('app', $coverage);
        $this->assertArrayHasKey('config_work', $coverage);

        // README references boost the protocol implementation.
        $remote = $repository->currentInventory()->where('path', 'tools/libconcord/src/remote.cpp')->firstOrFail();
        $session = $repository->currentInventory()->where('path', 'app/session.py')->firstOrFail();
        $this->assertGreaterThan($session->relevance_score, $remote->relevance_score);
        $this->assertContains('referenced from README/docs', $remote->score_reasons);

        // Dangerous or useless blobs stay inventoried but excluded.
        $reasons = collect($result['skipped'])->mapWithKeys(fn ($s) => [$s['path'] => $s['reason']]);
        $this->assertSame('unsupported format', $reasons['firmware/dump.bin']);
        $this->assertSame('unsupported format', $reasons['assets/logo.png']);
        $this->assertSame('possible secret — excluded from evidence', $reasons['.env']);
        $this->assertSame('possible secret — excluded from evidence', $reasons['id_rsa']);
        $this->assertSame('excluded directory', $reasons['node_modules/pkg/index.js']);
        $this->assertSame('excluded directory', $reasons['vendor/hal/hal.c']);
        $this->assertSame('generated artifact', $reasons['static/app.min.js']);
        $this->assertSame('file too large for extraction', $reasons['huge/dataset.csv']);
        $this->assertSame('unsupported format', $reasons['data/blob.xyz']);

        $this->assertSame(count($files), $repository->currentInventory()->count());
    }

    public function test_budget_exhaustion_supports_second_retrieval_pass(): void
    {
        config(['revival.github.max_files' => 5]);

        $this->fakeGitHub($this->reHarmonyFiles());
        $repository = $this->repository();
        $importer = app(GitHubRepositoryImporter::class);

        $result = $importer->import($repository);

        $this->assertCount(5, $result['manifest']);

        $missing = $repository->last_import_summary['missing_relevant'];
        $this->assertNotEmpty($missing);

        $target = $missing[0]['path'];
        $second = $importer->fetchAdditionalPaths($repository->fresh(), [$target, 'no/such/file.c']);

        $this->assertSame([$target], $second['stored']);
        $this->assertSame('not in inventory', $second['skipped'][0]['reason']);
        $this->assertSame(6, $repository->fresh()->currentEvidence()->count());

        // Re-fetching is idempotent.
        $repeat = $importer->fetchAdditionalPaths($repository->fresh(), [$target]);
        $this->assertSame([], $repeat['stored']);
        $this->assertSame('already imported', $repeat['skipped'][0]['reason']);
    }

    public function test_import_is_deterministic_across_repositories(): void
    {
        config([
            'revival.github.max_files' => 100,
            'revival.github.max_download_bytes' => 4000000,
            'revival.github.max_evidence_bytes' => 2000000,
            'revival.github.token_budget' => 500000,
        ]);

        $this->fakeGitHub($this->reHarmonyFiles());
        $importer = app(GitHubRepositoryImporter::class);

        $first = $importer->import($this->repository());
        $second = $importer->import($this->repository());

        $this->assertSame(
            array_column($first['manifest'], 'path'),
            array_column($second['manifest'], 'path')
        );
        $this->assertSame(
            array_column($first['manifest'], 'relevance_score'),
            array_column($second['manifest'], 'relevance_score')
        );
        $this->assertSame($first['skipped'], $second['skipped']);
    }

    public function test_reimport_is_idempotent_including_inventory(): void
    {
        $this->fakeGitHub($this->reHarmonyFiles());
        $repository = $this->repository();
        $importer = app(GitHubRepositoryImporter::class);

        $importer->import($repository);
        $inventoryCount = $repository->inventoryFiles()->count();
        $evidenceCount = $repository->evidenceSources()->count();

        $result = $importer->import($repository->fresh());

        $this->assertTrue($result['up_to_date']);
        $this->assertSame($inventoryCount, $repository->inventoryFiles()->count());
        $this->assertSame($evidenceCount, $repository->evidenceSources()->count());
    }

    public function test_analysis_receives_inventory_context_and_partial_indicators(): void
    {
        config([
            'revival.github.max_files' => 100,
            'revival.github.max_download_bytes' => 4000000,
            'revival.github.max_evidence_bytes' => 2000000,
            'revival.github.token_budget' => 500000,
        ]);

        $this->fakeGitHub($this->reHarmonyFiles());
        $repository = $this->repository();
        app(GitHubRepositoryImporter::class)->import($repository);
        $repository->refresh();

        $refs = $repository->currentEvidence()->get()
            ->map(fn ($row) => ['source_id' => $row->id, 'path' => $row->path])
            ->all();
        MockAIProvider::fake([MockAIProvider::reportPayload($refs)]);

        $run = AnalysisRun::factory()->create([
            'project_id' => $repository->project_id,
            'user_id' => $repository->project->owner_id,
            'repository_id' => $repository->id,
            'provider' => 'mock',
        ]);
        app(RevivalAnalysisService::class)->analyze($run->fresh());

        $this->assertCount(1, MockAIProvider::$requests);
        $user = MockAIProvider::$requests[0]->user;

        $this->assertStringContainsString('REPOSITORY CONTEXT', $user);
        $this->assertStringContainsString('Discovered', $user);
        $this->assertStringContainsString('PARTIAL excerpt', $user);
        $this->assertStringContainsString('app/main.py', $user);
        $this->assertStringContainsString('were NOT inspected', $user);
    }

    public function test_legacy_evidence_without_inventory_still_analyzes(): void
    {
        $repository = $this->repository();
        $repository->forceFill([
            'import_status' => 'ready',
            'last_commit_sha' => str_repeat('a', 40),
            'last_import_summary' => ['files' => 1, 'skipped' => [], 'warnings' => []],
        ])->save();

        $source = EvidenceSource::factory()->create([
            'project_id' => $repository->project_id,
            'repository_id' => $repository->id,
            'path' => 'README.md',
            'commit_sha' => $repository->last_commit_sha,
            'content' => 'Legacy import.',
        ]);

        MockAIProvider::fake([MockAIProvider::reportPayload([
            ['source_id' => $source->id, 'path' => 'README.md'],
        ])]);

        $run = AnalysisRun::factory()->create([
            'project_id' => $repository->project_id,
            'user_id' => $repository->project->owner_id,
            'repository_id' => $repository->id,
            'provider' => 'mock',
        ]);
        app(RevivalAnalysisService::class)->analyze($run->fresh());

        $this->assertCount(1, MockAIProvider::$requests);
        $this->assertStringNotContainsString(
            'REPOSITORY CONTEXT',
            MockAIProvider::$requests[0]->user
        );
    }
}
