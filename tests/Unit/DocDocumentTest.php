<?php

namespace Tests\Unit;

use App\Models\DocPage;
use App\Models\Project;
use App\Models\ProjectMedia;
use App\Services\Docs\DocDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocDocumentTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Project
    {
        return Project::factory()->create();
    }

    public function test_rejects_non_array_and_bad_version(): void
    {
        $project = $this->project();

        $this->assertFalse(DocDocument::validate('nope', $project)['ok']);
        $this->assertFalse(DocDocument::validate(['version' => 99, 'blocks' => []], $project)['ok']);
        $this->assertFalse(DocDocument::validate(['version' => 1], $project)['ok']);
    }

    public function test_rejects_too_many_blocks_and_unknown_types(): void
    {
        $project = $this->project();

        $many = ['version' => 1, 'blocks' => array_fill(0, 101, ['type' => 'paragraph', 'text' => 'x'])];
        $this->assertFalse(DocDocument::validate($many, $project)['ok']);

        $unknown = ['version' => 1, 'blocks' => [['type' => 'table', 'rows' => []]]];
        $this->assertFalse(DocDocument::validate($unknown, $project)['ok']);

        $badHeading = ['version' => 1, 'blocks' => [['type' => 'heading', 'level' => 1, 'text' => 'x']]];
        $this->assertFalse(DocDocument::validate($badHeading, $project)['ok']);
    }

    public function test_accepts_base_blocks_and_steps(): void
    {
        $project = $this->project();
        $media = ProjectMedia::factory()->create(['project_id' => $project->id, 'kind' => ProjectMedia::KIND_IMAGE]);

        $input = ['version' => 1, 'blocks' => [
            ['type' => 'heading', 'level' => 2, 'text' => 'Teardown'],
            ['type' => 'paragraph', 'text' => 'Open the case.'],
            ['type' => 'list', 'ordered' => true, 'items' => ['one', 'two']],
            ['type' => 'quote', 'text' => 'Careful.'],
            ['type' => 'code', 'text' => '0x8000'],
            ['type' => 'image', 'media_id' => $media->id, 'layout' => 'wide'],
            ['type' => 'step', 'title' => 'Remove screws', 'text' => 'Use a P2 driver.', 'media_ids' => [$media->id]],
        ]];

        $result = DocDocument::validate($input, $project);

        $this->assertTrue($result['ok']);
        $this->assertCount(7, $result['document']['blocks']);
        $this->assertSame([$media->id], DocDocument::mediaIds($result['document']));
    }

    public function test_step_media_is_bounded_and_must_be_numeric(): void
    {
        $project = $this->project();

        $tooMany = ['version' => 1, 'blocks' => [
            ['type' => 'step', 'text' => 'x', 'media_ids' => [1, 2, 3, 4, 5]],
        ]];
        $this->assertFalse(DocDocument::validate($tooMany, $project)['ok']);

        $badId = ['version' => 1, 'blocks' => [
            ['type' => 'step', 'text' => 'x', 'media_ids' => ['nope']],
        ]];
        $this->assertFalse(DocDocument::validate($badId, $project)['ok']);
    }

    public function test_rejects_foreign_and_non_image_media(): void
    {
        $project = $this->project();
        $other = Project::factory()->create();
        $foreign = ProjectMedia::factory()->create(['project_id' => $other->id, 'kind' => ProjectMedia::KIND_IMAGE]);
        $file = ProjectMedia::factory()->create(['project_id' => $project->id, 'kind' => ProjectMedia::KIND_FILE]);

        $input = ['version' => 1, 'blocks' => [['type' => 'image', 'media_id' => $foreign->id]]];
        $this->assertFalse(DocDocument::validate($input, $project)['ok']);

        $input = ['version' => 1, 'blocks' => [['type' => 'image', 'media_id' => $file->id]]];
        $this->assertFalse(DocDocument::validate($input, $project)['ok']);

        $input = ['version' => 1, 'blocks' => [['type' => 'step', 'text' => 'x', 'media_ids' => [$foreign->id]]]];
        $this->assertFalse(DocDocument::validate($input, $project)['ok']);
    }

    public function test_markers_must_be_normalized_and_bounded(): void
    {
        $project = $this->project();
        $media = ProjectMedia::factory()->create(['project_id' => $project->id, 'kind' => ProjectMedia::KIND_IMAGE]);

        $ok = ['version' => 1, 'blocks' => [['type' => 'image', 'media_id' => $media->id, 'markers' => [
            ['x' => 0.25, 'y' => 0.75, 'label' => 'U1', 'note' => 'Main chip'],
            ['x' => 0.0, 'y' => 1.0],
        ]]]];
        $result = DocDocument::validate($ok, $project);
        $this->assertTrue($result['ok']);
        $this->assertSame(0.25, $result['document']['blocks'][0]['markers'][0]['x']);

        $outOfRange = ['version' => 1, 'blocks' => [['type' => 'image', 'media_id' => $media->id, 'markers' => [
            ['x' => 1.5, 'y' => 0.5],
        ]]]];
        $this->assertFalse(DocDocument::validate($outOfRange, $project)['ok']);

        $tooMany = ['version' => 1, 'blocks' => [['type' => 'image', 'media_id' => $media->id,
            'markers' => array_fill(0, 21, ['x' => 0.5, 'y' => 0.5])]]];
        $this->assertFalse(DocDocument::validate($tooMany, $project)['ok']);
    }

    public function test_doc_links_stay_in_project_and_never_self(): void
    {
        $project = $this->project();
        $other = Project::factory()->create();
        $target = DocPage::factory()->create(['project_id' => $project->id]);
        $foreign = DocPage::factory()->create(['project_id' => $other->id]);

        $ok = ['version' => 1, 'blocks' => [['type' => 'doc_link', 'doc_id' => $target->id, 'label' => 'See also']]];
        $this->assertTrue(DocDocument::validate($ok, $project)['ok']);
        $this->assertSame([$target->id], DocDocument::docIds($ok));

        $missing = ['version' => 1, 'blocks' => [['type' => 'doc_link', 'doc_id' => 999999]]];
        $this->assertFalse(DocDocument::validate($missing, $project)['ok']);

        $crossProject = ['version' => 1, 'blocks' => [['type' => 'doc_link', 'doc_id' => $foreign->id]]];
        $this->assertFalse(DocDocument::validate($crossProject, $project)['ok']);

        $self = ['version' => 1, 'blocks' => [['type' => 'doc_link', 'doc_id' => $target->id]]];
        $this->assertFalse(DocDocument::validate($self, $project, $target->id)['ok']);
    }

    public function test_anchors_are_stable_and_ordered(): void
    {
        $anchors = DocDocument::anchors(['version' => 1, 'blocks' => [
            ['type' => 'heading', 'level' => 2, 'text' => 'Power rails'],
            ['type' => 'step', 'title' => 'Probe', 'text' => 'x', 'media_ids' => []],
            ['type' => 'image', 'media_id' => 1, 'markers' => [['x' => 0.1, 'y' => 0.2, 'label' => 'TP1']]],
            ['type' => 'heading', 'level' => 2, 'text' => 'Power rails'],
        ]]);

        $this->assertSame(
            ['section-1-power-rails', 'step-1', 'img-1', 'img-1-marker-1', 'section-2-power-rails'],
            array_column($anchors, 'id')
        );
        $this->assertSame('TP1', $anchors[3]['label']);
    }

    public function test_plain_text_covers_steps_and_markers(): void
    {
        $text = DocDocument::toPlainText(['version' => 1, 'blocks' => [
            ['type' => 'paragraph', 'text' => 'Intro.'],
            ['type' => 'step', 'title' => 'Open', 'text' => 'Lift the lid.', 'media_ids' => []],
            ['type' => 'image', 'media_id' => 1, 'markers' => [['x' => 0.1, 'y' => 0.2, 'label' => 'J3', 'note' => 'Header']]],
        ]]);

        $this->assertStringContainsString('Intro.', $text);
        $this->assertStringContainsString('Open', $text);
        $this->assertStringContainsString('Lift the lid.', $text);
        $this->assertStringContainsString('J3 Header', $text);
    }
}
