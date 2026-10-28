<?php

namespace Tests\Feature;

use App\Enums\PublicationStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavedProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_save_and_unsave_are_idempotent_and_private(): void
    {
        $project = Project::factory()->create(['publication_status' => PublicationStatus::Published]);
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->post(route('projects.save', $project))->assertRedirect();
        $this->actingAs($user)->post(route('projects.save', $project))->assertRedirect();
        $this->assertSame(1, $project->saves()->count());

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee($project->title, false);

        // Nobody else's workspace or profile reveals the save.
        $this->actingAs($other)->get(route('dashboard'))->assertOk()->assertDontSee($project->title, false);
        $this->actingAs($other)->get($user->profileUrl())->assertOk()->assertDontSee($project->title, false);

        // Saving is not following and not membership.
        $this->assertSame(0, $project->follows()->count());
        $this->assertSame(0, $project->members()->count());

        $this->actingAs($user)->delete(route('projects.unsave', $project))->assertRedirect();
        $this->assertSame(0, $project->saves()->count());
    }

    public function test_draft_save_requires_view_access_and_unpublish_hides(): void
    {
        $draft = Project::factory()->draft()->create(['title' => 'Hidden Draft For Save Test']);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->post(route('projects.save', $draft))->assertForbidden();

        $this->actingAs($draft->owner)->post(route('projects.save', $draft))->assertRedirect();
        $this->actingAs($draft->owner)->get(route('dashboard'))->assertSee('Hidden Draft For Save Test', false);

        // Another member's save disappears from their workspace with an
        // honest note instead of leaking the draft title.
        $project = Project::factory()->create([
            'publication_status' => PublicationStatus::Published,
            'title' => 'Vanishing Saved Project',
        ]);
        $saver = User::factory()->create();
        $this->actingAs($saver)->post(route('projects.save', $project))->assertRedirect();

        $project->update(['publication_status' => PublicationStatus::Draft]);

        $content = $this->actingAs($saver)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Vanishing Saved Project', $content);
        $this->assertStringContainsString('hidden because you can no longer view', $content);
    }
}
