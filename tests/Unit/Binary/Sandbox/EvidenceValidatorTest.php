<?php

namespace Tests\Unit\Binary\Sandbox;

use App\Services\Binary\Adapters\AnalysisFailedException;
use App\Services\Binary\Sandbox\EvidenceValidator;
use Tests\TestCase;

/** Evidence choke point: strict types, bounds, no HTML, safe paths. */
class EvidenceValidatorTest extends TestCase
{
    /** @return array<string, mixed> */
    private function envelope(array $overrides = []): array
    {
        return array_merge([
            'schema' => 'binary-analysis/1',
            'adapter' => 'ghidra',
            'adapter_version' => '12.2',
            'tool' => ['name' => 'Ghidra headless', 'language' => 'ARM:LE:32:v8'],
            'artifact' => ['id' => 1, 'sha256' => hash('sha256', 'x'), 'format' => 'raw', 'member' => null],
            'target' => 'arm32',
            'config' => ['target' => 'arm32'],
            'analyzed_at' => '2026-10-29T00:00:00+00:00',
            'elapsed_ms' => 5,
            'method_note' => 'test',
            'segments' => [],
            'functions' => [],
            'xrefs' => [],
            'calls' => [],
            'disassembly' => ['windows' => [], 'total_instructions' => 0, 'total_data_bytes' => 0, 'truncated' => false],
            'vectors' => [],
            'io_notes' => [],
            'warnings' => [],
            'limits' => ['max_instructions' => 1],
        ], $overrides);
    }

    public function test_valid_envelope_passes_and_strips_unknown_keys(): void
    {
        $out = EvidenceValidator::validate('ghidra', $this->envelope([
            'functions' => [
                ['id' => 'f8000', 'address' => 0x8000, 'file_offset' => null, 'name' => 'entry', 'source' => 'ghidra', 'size' => 16, 'size_tentative' => true],
            ],
            'xrefs' => [['from' => 0x8000, 'to' => 0x8010, 'kind' => 'call']],
            'calls' => ['f8000' => ['callers' => [], 'callees' => ['f8010']]],
            'runner_debug' => ['internal' => true],
        ]));

        $this->assertSame('f8000', $out['functions'][0]['id']);
        $this->assertSame(0x8000, $out['functions'][0]['address']);
        $this->assertArrayNotHasKey('runner_debug', $out);
        $this->assertContains('unknown_evidence_keys_stripped', $out['warnings']);
    }

    public function test_rejects_unknown_schema_and_adapter_mismatch(): void
    {
        try {
            EvidenceValidator::validate('ghidra', $this->envelope(['schema' => 'x/9']));
            $this->fail('unknown schema accepted');
        } catch (AnalysisFailedException $e) {
            $this->assertSame('evidence_rejected', $e->errorCode);
        }

        try {
            EvidenceValidator::validate('objdump', $this->envelope());
            $this->fail('adapter mismatch accepted');
        } catch (AnalysisFailedException $e) {
            $this->assertSame('evidence_rejected', $e->errorCode);
        }
    }

    public function test_reconstruction_accepts_html_in_inline_source(): void
    {
        // Real-world Java legitimately contains active-markup
        // strings (WebView bridges, SVG parsers, HTML assets):
        // bulk source content must stay inspectable, never fail
        // the run. Mirrors the production APK run that died on
        // "invalid html evidence".
        $java = 'class Bridge { String a = "<svg onload=alert(1)>";'
            .' String b = "javascript:Android.postMessage(\"hi\")";'
            .' String c = "<script src=\"x.js\"></script><iframe></iframe>";'
            .' String d = "<object data=\"x\"></object><embed src=\"y\">"; }';
        $out = EvidenceValidator::validate('jadx', $this->envelope([
            'adapter' => 'jadx',
            'reconstruction' => [
                'files' => [['path' => 'sources/com/Bridge.java', 'size' => strlen($java), 'sha256' => hash('sha256', $java)]],
                'inline' => [
                    'sources/com/Bridge.java' => $java,
                    'resources/assets/page.html' => '<html><head><script src="a.js"></script></head><body><svg onload=x()></svg></body></html>',
                ],
                'classes' => [['name' => 'com.Bridge', 'file' => 'sources/com/Bridge.java']],
                'truncated' => false,
            ],
        ]));

        $this->assertSame($java, $out['reconstruction']['inline']['sources/com/Bridge.java']);
        $this->assertStringContainsString('<svg onload=x()>', $out['reconstruction']['inline']['resources/assets/page.html']);
    }

    public function test_decompilation_c_and_extraction_inline_accept_html(): void
    {
        // C pseudocode string literals and extracted text get the
        // same content exemption as decompiled sources.
        $out = EvidenceValidator::validate('ghidra', $this->envelope([
            'adapter' => 'ghidra',
            'decompilation' => ['functions' => [
                ['name' => 'render', 'address' => 0x1000, 'c' => 'char *h = "<svg></svg>"; // javascript:void(0)'],
            ], 'truncated' => false],
            'extraction' => [
                'profile' => '7zz-extract',
                'files' => [['path' => 'doc.html', 'size' => 40, 'sha256' => hash('sha256', 'x')]],
                'inline' => ['doc.html' => '<html><object data="x"></object></html>'],
                'truncated' => false,
                'promoted' => [],
            ],
        ]));

        $this->assertStringContainsString('<svg>', $out['decompilation']['functions'][0]['c']);
        $this->assertStringContainsString('<object', $out['extraction']['inline']['doc.html']);
    }

    public function test_html_error_document_in_metadata_rejected(): void
    {
        // The narrowed scan still guards every structural/metadata
        // field: a full HTML error document there fails the run.
        $doc = '<html><head><script>alert(1)</script></head><body>Gateway Error</body></html>';
        foreach ([
            'method note' => ['method_note' => $doc],
            'function name' => ['functions' => [[
                'id' => 'f1', 'address' => 0x1000, 'file_offset' => null,
                'name' => $doc, 'source' => 'symbol', 'size' => null, 'size_tentative' => false,
            ]]],
            'warning' => ['warnings' => [$doc]],
            'segment name' => ['segments' => [[
                'name' => $doc, 'kind' => 'code', 'base_address' => 0,
                'length' => 1, 'file_offset' => null,
            ]]],
        ] as $label => $override) {
            try {
                EvidenceValidator::validate('php-native', $this->envelope($override));
                $this->fail($label.' accepted');
            } catch (AnalysisFailedException $e) {
                $this->assertSame('evidence_rejected', $e->errorCode, $label);
                $this->assertSame('The backend returned invalid html evidence. Its output was discarded.', $e->getMessage(), $label);
            }
        }
    }

    public function test_rejects_bad_addresses_and_html(): void
    {
        try {
            EvidenceValidator::validate('ghidra', $this->envelope([
                'functions' => [['id' => 'f1', 'address' => -5, 'file_offset' => null, 'name' => null, 'source' => 'ghidra', 'size' => null, 'size_tentative' => true]],
            ]));
            $this->fail('negative address accepted');
        } catch (AnalysisFailedException $e) {
            $this->assertSame('evidence_rejected', $e->errorCode);
        }

        try {
            EvidenceValidator::validate('ghidra', $this->envelope([
                'functions' => [['id' => 'nope', 'address' => 1, 'file_offset' => null, 'name' => null, 'source' => 'ghidra', 'size' => null, 'size_tentative' => true]],
            ]));
            $this->fail('bad function id accepted');
        } catch (AnalysisFailedException $e) {
            $this->assertSame('evidence_rejected', $e->errorCode);
        }

        try {
            EvidenceValidator::validate('ghidra', $this->envelope(['method_note' => 'see <script>alert(1)</script>']));
            $this->fail('html accepted');
        } catch (AnalysisFailedException $e) {
            $this->assertSame('evidence_rejected', $e->errorCode);
        }
    }

    public function test_rejects_traversal_paths_but_keeps_safe_manifest(): void
    {
        $rejected = $this->envelope(['reconstruction' => [
            'files' => [['path' => '../../etc/passwd', 'size' => 3, 'sha256' => hash('sha256', 'x')]],
            'inline' => [],
            'classes' => [],
            'truncated' => false,
        ]]);
        try {
            EvidenceValidator::validate('jadx', array_merge($rejected, ['adapter' => 'jadx']));
            $this->fail('traversal accepted');
        } catch (AnalysisFailedException $e) {
            $this->assertSame('evidence_rejected', $e->errorCode);
        }

        $ok = EvidenceValidator::validate('jadx', $this->envelope([
            'adapter' => 'jadx',
            'reconstruction' => [
                'files' => [['path' => 'sources/com/Foo.java', 'size' => 9, 'sha256' => hash('sha256', 'x')]],
                'inline' => ['sources/com/Foo.java' => 'class Foo{}'],
                'classes' => [['name' => 'com.Foo', 'file' => 'sources/com/Foo.java']],
                'truncated' => false,
            ],
        ]));
        $this->assertSame('com.Foo', $ok['reconstruction']['classes'][0]['name']);
    }

    /** @return array<string, mixed> */
    private function treeBudget(array $overrides = []): array
    {
        return array_merge([
            'files_seen' => 4327, 'manifest_retained' => 2000, 'manifest_omitted' => 2327,
            'inline_full' => 1100, 'excerpt_count' => 1, 'content_omitted' => 899,
            'binary_skipped' => 0, 'symlink_skipped' => 0, 'error_skipped' => 0,
            'excerpt_paths' => ['sources/com/Big.java'],
            'depth_pruned' => false, 'walk_capped' => false, 'byte_cap' => 524288,
        ], $overrides);
    }

    public function test_reconstruction_accepts_truncation_budget(): void
    {
        $out = EvidenceValidator::validate('jadx', $this->envelope([
            'adapter' => 'jadx',
            'reconstruction' => [
                'files' => [['path' => 'sources/com/Foo.java', 'size' => 9, 'sha256' => hash('sha256', 'x')]],
                'inline' => ['sources/com/Foo.java' => 'class Foo{}'],
                'classes' => [['name' => 'com.Foo', 'file' => 'sources/com/Foo.java']],
                'truncated' => true,
                'budget' => $this->treeBudget(),
            ],
        ]));

        $this->assertSame(4327, $out['reconstruction']['budget']['files_seen']);
        $this->assertSame(2327, $out['reconstruction']['budget']['manifest_omitted']);
        $this->assertSame(['sources/com/Big.java'], $out['reconstruction']['budget']['excerpt_paths']);
    }

    public function test_reconstruction_rejects_invalid_budget(): void
    {
        foreach ([
            'negative count' => ['files_seen' => -1],
            'non-int count' => ['manifest_omitted' => 'many'],
            'non-bool flag' => ['walk_capped' => 1],
            'bad byte cap' => ['byte_cap' => 0],
            'too many excerpts' => ['excerpt_paths' => array_fill(0, 201, 'sources/com/Foo.java')],
            'unsafe excerpt path' => ['excerpt_paths' => ['../escape.java']],
        ] as $label => $override) {
            try {
                EvidenceValidator::validate('jadx', $this->envelope([
                    'adapter' => 'jadx',
                    'reconstruction' => [
                        'files' => [], 'inline' => [], 'classes' => [],
                        'truncated' => true,
                        'budget' => $this->treeBudget($override),
                    ],
                ]));
                $this->fail($label.' accepted');
            } catch (AnalysisFailedException $e) {
                $this->assertSame('evidence_rejected', $e->errorCode, $label);
            }
        }
    }

    public function test_reconstruction_enforces_manifest_and_inline_bounds(): void
    {
        // Boundary: exactly 2000 manifest entries pass, 2001 rejected.
        $files = [];
        for ($i = 0; $i < 2000; $i++) {
            $files[] = ['path' => 'sources/com/F'.$i.'.java', 'size' => 9, 'sha256' => hash('sha256', (string) $i)];
        }
        $ok = EvidenceValidator::validate('jadx', $this->envelope([
            'adapter' => 'jadx',
            'reconstruction' => ['files' => $files, 'inline' => [], 'classes' => [], 'truncated' => true],
        ]));
        $this->assertCount(2000, $ok['reconstruction']['files']);
        $files[] = ['path' => 'sources/com/Extra.java', 'size' => 9, 'sha256' => hash('sha256', 'extra')];
        try {
            EvidenceValidator::validate('jadx', $this->envelope([
                'adapter' => 'jadx',
                'reconstruction' => ['files' => $files, 'inline' => [], 'classes' => [], 'truncated' => true],
            ]));
            $this->fail('2001-entry manifest accepted');
        } catch (AnalysisFailedException $e) {
            $this->assertSame('evidence_rejected', $e->errorCode);
        }

        // Boundary: 256 KiB inline total passes, one byte more rejected.
        $inline = [];
        for ($i = 0; $i < 8; $i++) {
            $inline['sources/com/G'.$i.'.java'] = str_repeat('b', 32768);
        }
        $ok = EvidenceValidator::validate('jadx', $this->envelope([
            'adapter' => 'jadx',
            'reconstruction' => ['files' => [], 'inline' => $inline, 'classes' => [], 'truncated' => true],
        ]));
        $this->assertCount(8, $ok['reconstruction']['inline']);
        $inline['sources/com/G8.java'] = 'x';
        try {
            EvidenceValidator::validate('jadx', $this->envelope([
                'adapter' => 'jadx',
                'reconstruction' => ['files' => [], 'inline' => $inline, 'classes' => [], 'truncated' => true],
            ]));
            $this->fail('over-total inline accepted');
        } catch (AnalysisFailedException $e) {
            $this->assertSame('evidence_rejected', $e->errorCode);
        }
    }

    public function test_reconstruction_preserves_unicode_and_escapes(): void
    {
        $text = "package com.twin;\n// \u{6D4B}\u{8BD5} \u{1F600} \"quotes\" \\ backslash / slash";
        $out = EvidenceValidator::validate('jadx', $this->envelope([
            'adapter' => 'jadx',
            'reconstruction' => [
                'files' => [['path' => 'sources/com/twin/Uni.java', 'size' => strlen($text), 'sha256' => hash('sha256', $text)]],
                'inline' => ['sources/com/twin/Uni.java' => $text],
                'classes' => [],
                'truncated' => false,
            ],
        ]));

        $this->assertSame($text, $out['reconstruction']['inline']['sources/com/twin/Uni.java']);
        $roundTrip = json_decode(json_encode($out['reconstruction']['inline']), true);
        $this->assertSame($text, $roundTrip['sources/com/twin/Uni.java']);
    }

    public function test_enforces_count_bounds(): void
    {
        $many = [];
        for ($i = 0; $i < 2005; $i++) {
            $many[] = ['id' => 'f'.dechex(0x1000 + $i), 'address' => 0x1000 + $i, 'file_offset' => null, 'name' => null, 'source' => 'ghidra', 'size' => null, 'size_tentative' => true];
        }
        try {
            EvidenceValidator::validate('ghidra', $this->envelope(['functions' => $many]));
            $this->fail('over-count functions accepted');
        } catch (AnalysisFailedException $e) {
            $this->assertSame('evidence_rejected', $e->errorCode);
        }
    }

    public function test_redacts_secret_like_tool_keys(): void
    {
        $out = EvidenceValidator::validate('ghidra', $this->envelope([
            'tool' => ['name' => 'Ghidra', 'api_token' => 'should-vanish', 'language' => 'x'],
        ]));

        $this->assertSame('Ghidra', $out['tool']['name']);
        $this->assertArrayNotHasKey('api_token', $out['tool']);
    }

    public function test_preserves_archive_member_structure(): void
    {
        $out = EvidenceValidator::validate('php-native', $this->envelope([
            'adapter' => 'php-native',
            'artifact' => [
                'id' => 2, 'sha256' => hash('sha256', 'y'), 'format' => 'archive',
                'member' => ['name' => 'fw/a.bin', 'size' => 4, 'sha256' => hash('sha256', 'z'), 'format' => 'raw', 'arch' => 'arm32'],
            ],
        ]));

        $this->assertSame('fw/a.bin', $out['artifact']['member']['name']);
        $this->assertSame('arm32', $out['artifact']['member']['arch']);
    }
}
