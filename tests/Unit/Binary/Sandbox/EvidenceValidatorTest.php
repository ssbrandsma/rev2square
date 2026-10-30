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
