<?php

namespace Tests\Feature;

use App\Enums\ProjectActivity;
use App\Enums\ProjectPhase;
use App\Livewire\Projects\Explorer;
use App\Models\Device;
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

    public function test_query_parameters_restore_filter_state(): void
    {
        Project::factory()->create(['title' => 'Shqred Filter Match']);
        Project::factory()->create(['title' => 'Unrelated Other']);

        Livewire::withQueryParams(['q' => 'Shqred'])
            ->test(Explorer::class)
            ->assertSet('search', 'Shqred')
            ->assertSee('Shqred Filter Match')
            ->assertDontSee('Unrelated Other');
    }

    public function test_invalid_query_values_are_ignored(): void
    {
        Project::factory()->create(['title' => 'Valid Listed Project']);

        Livewire::withQueryParams(['phase' => 'NOPE', 'activity' => 'NOPE', 'sort' => 'NOPE'])
            ->test(Explorer::class)
            ->assertSet('phase', '')
            ->assertSet('activity', '')
            ->assertSet('sort', 'newest')
            ->assertSee('Valid Listed Project')
            ->assertSee('1 project found', false);
    }

    public function test_clear_all_resets_filters_and_shows_count(): void
    {
        Project::factory()->create(['title' => 'Counted Alpha Project']);
        Project::factory()->create(['title' => 'Counted Beta Project']);

        Livewire::test(Explorer::class)
            ->set('search', 'Alpha')
            ->assertSee('1 project found', false)
            ->assertSee('Clear all filters', false)
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSee('2 projects found', false)
            ->assertSee('Counted Beta Project');
    }

    public function test_sort_orders_results(): void
    {
        Project::factory()->create(['title' => 'Zulu Sort Project']);
        Project::factory()->create(['title' => 'Alpha Sort Project']);

        $html = Livewire::test(Explorer::class)
            ->set('sort', 'title')
            ->html();

        $this->assertLessThan(strpos($html, 'Zulu Sort Project'), strpos($html, 'Alpha Sort Project'));
    }

    public function test_homepage_features_only_eligible_projects(): void
    {
        $eligible = Project::factory()->create(['title' => 'Featurable Device Project']);
        $eligible->devices()->sync([Device::factory()->create()->id => ['device_variant_id' => null]]);

        Project::factory()->create(['title' => 'Early Stage No Device']);
        Project::factory()->draft()->create(['title' => 'Draft Hidden Project']);

        $archived = Project::factory()->create([
            'title' => 'Archived Shelved Project',
            'activity' => ProjectActivity::Archived,
        ]);
        $archived->devices()->sync([Device::factory()->create()->id => ['device_variant_id' => null]]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Featurable Device Project', false)
            ->assertDontSee('Early Stage No Device', false)
            ->assertDontSee('Draft Hidden Project', false)
            ->assertDontSee('Archived Shelved Project', false);
    }
}
