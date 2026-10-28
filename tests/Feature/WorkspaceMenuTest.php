<?php

namespace Tests\Feature;

use App\Enums\InvitationStatus;
use App\Enums\OfferStatus;
use App\Enums\ProjectRole;
use App\Enums\PublicationStatus;
use App\Models\ContributionOffer;
use App\Models\Project;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_menu_shows_identity_links_and_real_unread(): void
    {
        $user = User::factory()->create(['handle' => 'menu-tester']);
        $project = Project::factory()->create([
            'publication_status' => PublicationStatus::Published,
            'owner_id' => $user->id,
            'title' => 'Menu Recent Project',
        ]);

        $content = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Account menu for '.$user->name, $content);
        $this->assertStringContainsString('@menu-tester', $content);
        $this->assertStringContainsString('View my public profile', $content);
        $this->assertStringContainsString('My workspace', $content);
        $this->assertStringContainsString('Saved projects', $content);
        $this->assertStringContainsString('Edit profile &amp; account settings', $content);
        $this->assertStringContainsString('Log out', $content);
        $this->assertStringContainsString('Menu Recent Project', $content);
        $this->assertStringContainsString($user->profileUrl(), $content);
        $this->assertSame($project->id, $project->fresh()->id);

        // Anonymous header: no menu, appropriate auth links.
        $this->post(route('logout'))->assertRedirect('/');
        $anon = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('Account menu for', $anon);
        $this->assertStringContainsString('Log in', $anon);
    }

    public function test_menu_unread_count_matches_inbox(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['publication_status' => PublicationStatus::Published]);

        $this->actingAs($project->owner)
            ->post(route('projects.discussion.store', $project), [
                'type' => 'update',
                'title' => 'Unread marker update',
                'body' => 'Body.',
            ]);

        $project->follows()->create(['user_id' => $user->id]);

        $this->actingAs($project->owner)
            ->post(route('projects.discussion.store', $project), [
                'type' => 'update',
                'title' => 'Second unread marker',
                'body' => 'Body.',
            ]);

        // One notification (only the second update landed after following).
        $content = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('Inbox, 1 unread', $content);
        $this->assertStringContainsString('Inbox (1 unread)', $content);
    }

    public function test_workspace_surfaces_invitations_and_next_actions(): void
    {
        $project = Project::factory()->create(['publication_status' => PublicationStatus::Published]);
        $user = User::factory()->create();

        TeamInvitation::factory()->for($project, 'project')->for($user, 'user')->create([
            'role' => ProjectRole::Maintainer,
            'status' => InvitationStatus::Pending,
            'expires_at' => now()->addWeek(),
        ]);

        ContributionOffer::factory()->for($project, 'project')->for($user, 'user')->create([
            'status' => OfferStatus::Accepted,
        ]);

        $content = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('invited you to join the team', $content);
        $this->assertStringContainsString('Join project as contributor', $content);
        $this->assertStringContainsString('My applications', $content);
        $this->assertStringContainsString('Recent activity', $content);
    }

    public function test_workspace_activity_reuses_inbox_rows(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['publication_status' => PublicationStatus::Published]);
        $project->follows()->create(['user_id' => $user->id]);

        $this->actingAs($project->owner)
            ->post(route('projects.discussion.store', $project), [
                'type' => 'update',
                'title' => 'Workspace activity marker',
                'body' => 'Body.',
            ]);

        $content = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('Workspace activity marker', $content);

        // Same row the inbox shows — one table, two views.
        $this->assertSame(1, $user->notifications()->count());
        $this->actingAs($user)->get(route('inbox.index'))->assertOk()->assertSee('Workspace activity marker', false);
    }

    public function test_contributor_without_projects_sees_useful_workspace(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['publication_status' => PublicationStatus::Published]);

        ContributionOffer::factory()->for($project, 'project')->for($user, 'user')->create([
            'status' => OfferStatus::Pending,
        ]);

        $content = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        // No longer "No projects yet" as the entire experience.
        $this->assertStringContainsString('My applications', $content);
        $this->assertStringContainsString($project->title, $content);
        $this->assertStringContainsString('Needs attention', $content);
    }
}
