<?php

namespace Tests\Unit;

use App\Services\Revival\ArchitectureSchema;
use PHPUnit\Framework\TestCase;
use Tests\Support\MockAIProvider;

class ArchitectureSchemaTest extends TestCase
{
    /** @return array<int, array<string, mixed>> */
    private function index(): array
    {
        return [
            11 => [
                'path' => 'main.py',
                'content_hash' => hash('sha256', 'whole file'),
                'is_partial' => false,
                'line_ranges' => null,
                'original_lines' => 40,
                'line_count' => 40,
            ],
            12 => [
                'path' => 'docs/guide.md',
                'content_hash' => hash('sha256', 'partial excerpt'),
                'is_partial' => true,
                'line_ranges' => [['start' => 10, 'end' => 30]],
                'original_lines' => 200,
                'line_count' => 21,
            ],
            13 => [
                'path' => 'legacy/blob.bin.md',
                'content_hash' => hash('sha256', 'unknown ranges'),
                'is_partial' => true,
                'line_ranges' => null,
                'original_lines' => null,
                'line_count' => 5,
            ],
        ];
    }

    /** @return list<array{source_id: int, path: string, content_hash: string}> */
    private function sources(): array
    {
        return [
            ['source_id' => 11, 'path' => 'main.py', 'content_hash' => hash('sha256', 'whole file')],
            ['source_id' => 12, 'path' => 'docs/guide.md', 'content_hash' => hash('sha256', 'partial excerpt')],
        ];
    }

    public function test_valid_payload_is_accepted_and_normalized(): void
    {
        [$errors, $normalized] = ArchitectureSchema::validate(
            MockAIProvider::architecturePayload($this->sources()),
            $this->index()
        );

        $this->assertSame([], $errors);
        $this->assertSame('1.0', $normalized['schema_version']);
        $this->assertCount(2, $normalized['components']);
        $this->assertCount(1, $normalized['relationships']);
        $this->assertSame('entry', $normalized['components'][0]['id']);
        $this->assertSame('uses-helper', $normalized['relationships'][0]['id']);
        $this->assertSame('entry', $normalized['relationships'][0]['from']);
        $this->assertNull($normalized['components'][0]['evidence'][0]['from_line']);
        $this->assertFalse($normalized['components'][0]['evidence'][0]['is_partial']);
    }

    public function test_hash_prefix_citations_are_accepted(): void
    {
        $payload = MockAIProvider::architecturePayload($this->sources(), [
            'components' => [
                ['evidence' => [['content_hash' => substr(hash('sha256', 'whole file'), 0, 16)]]],
            ],
        ]);

        [$errors] = ArchitectureSchema::validate($payload, $this->index());

        $this->assertSame([], $errors);
    }

    public function test_wrong_schema_version_is_rejected(): void
    {
        [$errors, $normalized] = ArchitectureSchema::validate(
            MockAIProvider::architecturePayload($this->sources(), ['schema_version' => '2.0']),
            $this->index()
        );

        $this->assertNotSame([], $errors);
        $this->assertNull($normalized);
    }

    public function test_non_object_and_missing_lists_are_rejected(): void
    {
        [$errors] = ArchitectureSchema::validate('nope', $this->index());
        $this->assertNotSame([], $errors);

        [$errors] = ArchitectureSchema::validate(['schema_version' => '1.0'], $this->index());
        $this->assertNotSame([], $errors);
    }

    public function test_empty_graph_without_unknowns_is_rejected(): void
    {
        $payload = MockAIProvider::architecturePayload($this->sources());
        $payload['components'] = [];
        $payload['unknowns'] = [];

        [$errors] = ArchitectureSchema::validate($payload, $this->index());

        $this->assertNotSame([], $errors);
    }

    public function test_oversized_output_is_rejected(): void
    {
        $components = [];
        for ($i = 0; $i < 61; $i++) {
            $components[] = [
                'id' => "c{$i}",
                'type' => 'module',
                'label' => "Module {$i}",
                'explanation' => 'x',
                'certainty' => 'OBSERVED',
                'evidence' => [[
                    'source_id' => 11,
                    'locator' => 'main.py',
                    'content_hash' => hash('sha256', 'whole file'),
                ]],
            ];
        }

        [$errors] = ArchitectureSchema::validate(
            MockAIProvider::architecturePayload($this->sources(), ['components' => $components]),
            $this->index()
        );

        $this->assertNotSame([], $errors);
    }

    public function test_long_explanation_is_rejected(): void
    {
        [$errors] = ArchitectureSchema::validate(
            MockAIProvider::architecturePayload($this->sources(), [
                'components' => [['explanation' => str_repeat('x', 2001)]],
            ]),
            $this->index()
        );

        $this->assertNotSame([], $errors);
    }

    public function test_invalid_type_and_certainty_are_rejected(): void
    {
        [$errors] = ArchitectureSchema::validate(
            MockAIProvider::architecturePayload($this->sources(), [
                'components' => [['type' => 'widget', 'certainty' => 'MAYBE']],
            ]),
            $this->index()
        );

        $this->assertGreaterThanOrEqual(2, count($errors));
    }

    public function test_duplicate_component_ids_are_rejected(): void
    {
        [$errors] = ArchitectureSchema::validate(
            MockAIProvider::architecturePayload($this->sources(), [
                'components' => [1 => ['id' => 'entry']],
            ]),
            $this->index()
        );

        $this->assertNotSame([], $errors);
    }

    public function test_dangling_and_self_edges_are_rejected(): void
    {
        [$errors] = ArchitectureSchema::validate(
            MockAIProvider::architecturePayload($this->sources(), [
                'relationships' => [['to' => 'ghost']],
            ]),
            $this->index()
        );
        $this->assertNotSame([], $errors);

        [$errors] = ArchitectureSchema::validate(
            MockAIProvider::architecturePayload($this->sources(), [
                'relationships' => [['from' => 'entry', 'to' => 'entry']],
            ]),
            $this->index()
        );
        $this->assertNotSame([], $errors);
    }

    public function test_fabricated_source_ids_are_rejected(): void
    {
        [$errors] = ArchitectureSchema::validate(
            MockAIProvider::architecturePayload($this->sources(), [
                'components' => [['evidence' => [['source_id' => 999]]]],
            ]),
            $this->index()
        );

        $this->assertNotSame([], $errors);
    }

    public function test_path_mismatch_is_rejected(): void
    {
        [$errors] = ArchitectureSchema::validate(
            MockAIProvider::architecturePayload($this->sources(), [
                'components' => [['evidence' => [['locator' => 'other/file.py']]]],
            ]),
            $this->index()
        );

        $this->assertNotSame([], $errors);
    }

    public function test_hash_mismatch_is_rejected(): void
    {
        [$errors] = ArchitectureSchema::validate(
            MockAIProvider::architecturePayload($this->sources(), [
                'components' => [['evidence' => [['content_hash' => str_repeat('0', 64)]]]],
            ]),
            $this->index()
        );

        $this->assertNotSame([], $errors);
    }

    public function test_whole_file_line_ranges_are_checked(): void
    {
        [$errors, $normalized] = ArchitectureSchema::validate(
            MockAIProvider::architecturePayload($this->sources(), [
                'components' => [['evidence' => [['locator' => 'main.py:L5-L9']]]],
            ]),
            $this->index()
        );

        $this->assertSame([], $errors);
        $this->assertSame(5, $normalized['components'][0]['evidence'][0]['from_line']);
        $this->assertSame(9, $normalized['components'][0]['evidence'][0]['to_line']);

        [$errors] = ArchitectureSchema::validate(
            MockAIProvider::architecturePayload($this->sources(), [
                'components' => [['evidence' => [['locator' => 'main.py:L1-L400']]]],
            ]),
            $this->index()
        );

        $this->assertNotSame([], $errors);
    }

    public function test_partial_ranges_must_match_known_excerpts(): void
    {
        $hash = hash('sha256', 'partial excerpt');

        $payload = MockAIProvider::architecturePayload($this->sources());
        $payload['components'][1]['evidence'] = [[
            'source_id' => 12,
            'locator' => 'docs/guide.md:L12-L20',
            'content_hash' => $hash,
        ]];

        [$errors] = ArchitectureSchema::validate($payload, $this->index());
        $this->assertSame([], $errors);

        $payload['components'][1]['evidence'][0]['locator'] = 'docs/guide.md:L50-L60';
        [$errors] = ArchitectureSchema::validate($payload, $this->index());
        $this->assertNotSame([], $errors);
    }

    public function test_partial_excerpt_with_unknown_ranges_forbids_line_numbers(): void
    {
        $payload = MockAIProvider::architecturePayload($this->sources());
        $payload['components'][1]['evidence'] = [[
            'source_id' => 13,
            'locator' => 'legacy/blob.bin.md:L1-L3',
            'content_hash' => hash('sha256', 'unknown ranges'),
        ]];

        [$errors] = ArchitectureSchema::validate($payload, $this->index());
        $this->assertNotSame([], $errors);

        $payload['components'][1]['evidence'][0]['locator'] = 'legacy/blob.bin.md';
        [$errors] = ArchitectureSchema::validate($payload, $this->index());
        $this->assertSame([], $errors);
    }

    public function test_component_without_evidence_is_rejected(): void
    {
        $payload = MockAIProvider::architecturePayload($this->sources());
        $payload['components'][0]['evidence'] = [];

        [$errors] = ArchitectureSchema::validate($payload, $this->index());

        $this->assertNotSame([], $errors);
    }
}
