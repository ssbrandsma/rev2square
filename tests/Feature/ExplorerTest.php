<?php

namespace Tests\Feature;

use App\Enums\ProjectPhase;
use App\Livewire\Projects\Explorer;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExplorerTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_filters_projects_by_title(): void
    {
        Project::factory()->create(['title' => 'Alpha Camera Revival']);
        Project::factory()->create(['title' => 'Beta Radio Revival']);

        Livewire::test(Explorer::class)
            ->set('search', 'Alpha')
            ->assertSee('Alpha Camera Revival')
            ->assertDontSee('Beta Radio Revival');
    }

    public function test_phase_filter_limits_results(): void
    {
        Project::factory()->create([
            'title' => 'Phase Alpha Project',
            'phase' => ProjectPhase::ResearchFeasibility,
        ]);
        Project::factory()->create([
            'title' => 'Phase Beta Project',
            'phase' => ProjectPhase::Development,
        ]);

        Livewire::test(Explorer::class)
            ->set('phase', ProjectPhase::Development->value)
            ->assertSee('Phase Beta Project')
            ->assertDontSee('Phase Alpha Project');
    }

    public function test_drafts_never_appear_in_explorer(): void
    {
        Project::factory()->draft()->create(['title' => 'Hidden Draft Explorer']);

        Livewire::test(Explorer::class)
            ->assertDontSee('Hidden Draft Explorer');
    }
}
