<?php

namespace Tests\Unit;

use App\Models\Project;
use App\Services\Media\StoryDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoryDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_overlong_text_names_block_count_limit_and_recovery(): void
    {
        $project = Project::factory()->create();

        $result = StoryDocument::validate(['version' => 1, 'blocks' => [
            ['type' => 'paragraph', 'text' => str_repeat('a', 5001)],
        ]], $project);

        $this->assertFalse($result['ok']);
        $this->assertSame(
            'Block 1 — Paragraph contains 5,001 characters. The limit is 5,000 per block. Split this text into multiple paragraphs.',
            $result['error']
        );
    }

    public function test_other_failures_stay_specific(): void
    {
        $project = Project::factory()->create();

        $unknown = StoryDocument::validate(['version' => 1, 'blocks' => [['type' => 'step', 'text' => 'x']]], $project);
        $this->assertFalse($unknown['ok']);
        $this->assertStringContainsString("Block 1 has an unsupported block type ('step').", $unknown['error']);

        $many = StoryDocument::validate(['version' => 1, 'blocks' => array_fill(0, 101, ['type' => 'paragraph', 'text' => 'x'])], $project);
        $this->assertFalse($many['ok']);
        $this->assertStringContainsString('101 blocks', $many['error']);
    }
}
