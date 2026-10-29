<?php

namespace Tests\Unit;

use App\Services\Revival\ReportSchema;
use PHPUnit\Framework\TestCase;
use Tests\Support\MockAIProvider;

class ReportSchemaTest extends TestCase
{
    private function sources(): array
    {
        return [11 => 'README.md', 12 => 'docs/guide.md'];
    }

    private function payload(): array
    {
        return MockAIProvider::reportPayload([
            ['source_id' => 11, 'path' => 'README.md'],
            ['source_id' => 12, 'path' => 'docs/guide.md'],
        ]);
    }

    public function test_valid_payload_passes(): void
    {
        $this->assertSame([], ReportSchema::validate($this->payload(), $this->sources()));
    }

    public function test_missing_keys_fail(): void
    {
        $payload = $this->payload();
        unset($payload['findings'], $payload['summary']);

        $errors = ReportSchema::validate($payload, $this->sources());

        $this->assertContains('Missing required key: findings.', $errors);
        $this->assertContains('Missing required key: summary.', $errors);
    }

    public function test_wrong_schema_version_fails(): void
    {
        $payload = $this->payload();
        $payload['schema_version'] = '2.0';

        $this->assertNotSame([], ReportSchema::validate($payload, $this->sources()));
    }

    public function test_invalid_verdict_and_status_fail(): void
    {
        $payload = $this->payload();
        $payload['revival_paths'][0]['feasibility'] = 'GUARANTEED';
        $payload['findings'][0]['status'] = 'MAYBE';

        $errors = ReportSchema::validate($payload, $this->sources());

        $this->assertContains('revival_paths[0].feasibility is invalid.', $errors);
        $this->assertContains('findings[0].status is invalid.', $errors);
    }

    public function test_unknown_source_id_fails(): void
    {
        $payload = $this->payload();
        $payload['evidence'][0]['source_id'] = 999;

        $errors = ReportSchema::validate($payload, $this->sources());

        $this->assertContains('evidence[0] cites unknown source_id: 999.', $errors);
    }

    public function test_locator_path_mismatch_fails(): void
    {
        $payload = $this->payload();
        $payload['evidence'][0]['locator'] = 'docs/other.md:L1-L2';

        $errors = ReportSchema::validate($payload, $this->sources());

        $this->assertContains('evidence[0].locator path does not match the cited source path.', $errors);
    }

    public function test_unknown_evidence_reference_fails(): void
    {
        $payload = $this->payload();
        $payload['findings'][0]['evidence_ids'] = ['E99'];

        $errors = ReportSchema::validate($payload, $this->sources());

        $this->assertContains('findings[0] cites unknown evidence id: "E99".', $errors);
    }

    public function test_supported_finding_requires_citation_but_unverified_may_omit(): void
    {
        $payload = $this->payload();
        $payload['findings'][0]['evidence_ids'] = [];

        $errors = ReportSchema::validate($payload, $this->sources());

        $this->assertContains('findings[0] with status SUPPORTED must cite at least one evidence id.', $errors);

        $payload['findings'][0]['status'] = 'UNVERIFIED';

        $this->assertSame([], ReportSchema::validate($payload, $this->sources()));
    }

    public function test_unknown_finding_with_examined_evidence_passes(): void
    {
        // UNKNOWN = examined evidence cannot answer: citation required,
        // explanation in limitations.
        $payload = $this->payload();
        $payload['findings'][0]['status'] = 'UNKNOWN';
        $payload['findings'][0]['limitations'] = 'E1 shows the file exists but not whether the API runs.';

        $this->assertSame([], ReportSchema::validate($payload, $this->sources()));
    }

    public function test_unknown_finding_without_evidence_fails(): void
    {
        // The observed live failure (Run #10, 28 Oct 2026): an uncited
        // UNKNOWN finding must be rejected, not silently accepted.
        // Gaps with no evidence at all belong in unknowns[], not findings[].
        $payload = $this->payload();
        $payload['findings'][0]['status'] = 'UNKNOWN';
        $payload['findings'][0]['evidence_ids'] = [];

        $errors = ReportSchema::validate($payload, $this->sources());

        $this->assertContains('findings[0] with status UNKNOWN must cite at least one evidence id.', $errors);
    }

    public function test_missing_evidence_is_represented_as_unknowns_without_findings(): void
    {
        // Genuinely missing evidence: no findings, gaps recorded in
        // unknowns[] (which requires no citations).
        $payload = $this->payload();
        $payload['findings'] = [];
        $payload['evidence'] = [];
        $payload['source_manifest_ids'] = [];
        $payload['revival_paths'][0]['supporting_evidence_ids'] = [];
        $payload['revival_paths'][0]['feasibility'] = 'INSUFFICIENT_EVIDENCE';
        $payload['experiments'] = [];

        $this->assertSame([], ReportSchema::validate($payload, $this->sources()));
    }

    public function test_unknown_task_reference_fails(): void
    {
        $payload = $this->payload();
        $payload['milestones'][0]['task_ids'] = ['T99'];

        $errors = ReportSchema::validate($payload, $this->sources());

        $this->assertContains('milestones[0] cites unknown task id: "T99".', $errors);
    }

    public function test_empty_findings_and_unknowns_fail(): void
    {
        $payload = $this->payload();
        $payload['findings'] = [];
        $payload['unknowns'] = [];

        $errors = ReportSchema::validate($payload, $this->sources());

        $this->assertContains('Report must contain at least one finding or one unknown.', $errors);
    }

    public function test_experiments_without_unknowns_fail(): void
    {
        $payload = $this->payload();
        $payload['experiments'] = [];
        $payload['unknowns'] = [];

        $errors = ReportSchema::validate($payload, $this->sources());

        $this->assertContains('Report without experiments must record unknowns.', $errors);
    }

    public function test_insufficient_evidence_payload_passes(): void
    {
        $payload = $this->payload();
        $payload['findings'] = [];
        $payload['revival_paths'][0]['feasibility'] = 'INSUFFICIENT_EVIDENCE';
        $payload['experiments'] = [];

        // unknowns[] is non-empty in the fixture.
        $this->assertSame([], ReportSchema::validate($payload, $this->sources()));
    }

    public function test_json_schema_is_non_recursive(): void
    {
        $schema = json_encode(ReportSchema::jsonSchema());

        $this->assertStringNotContainsString('$ref', $schema);
        $this->assertStringNotContainsString('$recursive', $schema);
    }

    public function test_json_schema_objects_deny_additional_properties(): void
    {
        // The API rejects strict schemas whose objects omit additionalProperties.
        $this->assertObjectsAreClosed(ReportSchema::jsonSchema());
    }

    public function test_json_schema_objects_require_all_properties(): void
    {
        // Strict mode rejects objects whose required list omits a property.
        $this->assertObjectsAreClosed(ReportSchema::jsonSchema(), true);
    }

    private function assertObjectsAreClosed(mixed $node, bool $checkRequired = false): void
    {
        if (! is_array($node)) {
            return;
        }

        if (($node['type'] ?? null) === 'object') {
            $this->assertFalse(
                $node['additionalProperties'] ?? null,
                'Object node must set additionalProperties: false.'
            );

            if ($checkRequired && isset($node['properties'])) {
                $missing = array_diff(array_keys($node['properties']), $node['required'] ?? []);

                $this->assertSame([], array_values($missing), 'Required must list every property.');
            }
        }

        foreach ($node as $child) {
            $this->assertObjectsAreClosed($child, $checkRequired);
        }
    }
}
