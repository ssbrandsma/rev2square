<?php

namespace Database\Factories;

use App\Enums\DocCategory;
use App\Enums\PublicationStatus;
use App\Models\DocPage;
use App\Models\DocRevision;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class DocPageFactory extends Factory
{
    protected $model = DocPage::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'category' => DocCategory::Teardown,
            'title' => fake()->sentence(4),
            'summary' => fake()->sentence(10),
            'publication_status' => PublicationStatus::Draft,
            'sort_order' => 0,
            'current_rev' => 1,
            'body' => ['version' => 1, 'blocks' => [
                ['type' => 'paragraph', 'text' => fake()->paragraph()],
            ]],
            'search_text' => '',
        ];
    }

    public function published(): static
    {
        return $this->state(['publication_status' => PublicationStatus::Published]);
    }

    public function configure(): static
    {
        // Production pages always open with a rev 1 snapshot; the
        // factory mirrors that so pinned-revision behavior matches.
        return $this->afterCreating(function (DocPage $page): void {
            if (! DocRevision::where('doc_page_id', $page->id)->where('rev', 1)->exists()) {
                DocRevision::create([
                    'doc_page_id' => $page->id,
                    'rev' => 1,
                    'title' => $page->title,
                    'summary' => $page->summary,
                    'category' => $page->category->value,
                    'publication_status' => $page->publication_status->value,
                    'body' => $page->body,
                    'author_id' => null,
                    'created_at' => now(),
                ]);
            }
        });
    }
}
