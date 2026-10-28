<?php

namespace Tests\Feature;

use App\Enums\ProjectActivity;
use App\Enums\ProjectRole;
use App\Enums\PublicationStatus;
use App\Models\DiscussionPost;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private function publishedProject(array $overrides = []): Project
    {
        return Project::factory()->create([
            'publication_status' => PublicationStatus::Published,
            ...$overrides,
        ]);
    }

    public function test_public_profile_shows_identity_but_never_email(): void
    {
        $user = User::factory()->create([
            'email' => 'profile-secret@example.test',
            'handle' => 'reviver-jane',
            'bio' => 'I fix tape decks.',
            'skills' => ['soldering', 'firmware'],
            'links' => [['label' => 'Blog', 'url' => 'https://example.test/blog']],
            'availability' => 'Weekends',
            'hardware_interests' => 'Walkmans',
        ]);

        foreach (['anon' => null, 'other' => User::factory()->create(), 'self' => $user] as $viewer) {
            $request = $viewer === null ? $this : $this->actingAs($viewer);
            $response = $request->get($user->profileUrl())->assertOk();

            $response->assertSee('@reviver-jane', false);
            $response->assertSee('I fix tape decks.', false);
            $response->assertSee('soldering', false);
            $response->assertSee('https://example.test/blog', false);
            $response->assertSee('Weekends', false);
            $response->assertDontSee('profile-secret@example.test', false);
        }
    }

    public function test_bio_and_links_render_safely(): void
    {
        $user = User::factory()->create([
            'bio' => '<script>alert("xss")</script>hello',
            'skills' => ['<img src=x onerror=alert(1)>'],
        ]);

        $content = $this->get($user->profileUrl())->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert', $content);
        $this->assertStringNotContainsString('<img src=x', $content);
        $this->assertStringContainsString('hello', $content);
    }

    public function test_handle_routes_resolve_current_and_retired_handles(): void
    {
        $user = User::factory()->create(['handle' => 'current-name']);

        $this->get('/u/current-name')->assertOk();
        $this->get('/u/CURRENT-NAME')->assertOk();
        $this->get('/u/no-such-handle-xyz')->assertNotFound();

        $this->actingAs($user)
            ->patch(route('profile.public.update'), ['handle' => 'new-name'])
            ->assertRedirect(route('profile.edit'));

        $this->get('/u/new-name')->assertOk();
        // The retired handle keeps resolving to the same member.
        $this->get('/u/current-name')->assertOk()->assertSee($user->name, false);
        // …and nobody else can claim it.
        $this->actingAs(User::factory()->create())
            ->patch(route('profile.public.update'), ['handle' => 'current-name'])
            ->assertSessionHasErrors('handle');
    }

    public function test_handle_validation_rejects_reserved_duplicates_and_shapes(): void
    {
        User::factory()->create(['handle' => 'taken-handle']);
        $user = User::factory()->create();

        foreach (['admin', 'projects', 'u', 'ab', 'has space', '-leading', 'trailing-', 'taken-handle'] as $bad) {
            $this->actingAs($user)
                ->patch(route('profile.public.update'), ['handle' => $bad])
                ->assertSessionHasErrors('handle');
        }

        $this->assertNull($user->fresh()->handle);

        $this->actingAs($user)
            ->patch(route('profile.public.update'), ['handle' => 'Good-Name_9'])
            ->assertRedirect(route('profile.edit'));

        $this->assertSame('good-name_9', $user->fresh()->handle);
    }

    public function test_public_sections_derive_from_relationships_with_agreeing_counts(): void
    {
        $member = User::factory()->create();
        $started = $this->publishedProject(['owner_id' => $member->id]);
        $maintained = $this->publishedProject();
        ProjectMember::factory()->for($maintained, 'project')->for($member, 'user')->create([
            'role' => ProjectRole::Maintainer, 'status' => 'active',
        ]);
        $working = $this->publishedProject();
        ProjectMember::factory()->for($working, 'project')->for($member, 'user')->create([
            'role' => ProjectRole::Contributor, 'status' => 'active',
        ]);

        $content = $this->actingAs(User::factory()->create())->get($member->profileUrl())->assertOk()->getContent();

        foreach ([$started, $maintained, $working] as $project) {
            $this->assertStringContainsString($project->title, $content);
        }

        // Counts agree with the visible lists. The owned project counts
        // under both Started and Maintaining (the owner is its maintainer).
        $this->assertStringContainsString('Started <span class="text-zinc-400">(1)</span>', $content);
        $this->assertStringContainsString('Maintaining <span class="text-zinc-400">(2)</span>', $content);
        $this->assertStringContainsString('Working on <span class="text-zinc-400">(1)</span>', $content);
        $this->assertStringNotContainsString('profile-secret', $content);
    }

    public function test_drafts_never_leak_through_public_profile(): void
    {
        $member = User::factory()->create();
        $draft = Project::factory()->draft()->create([
            'owner_id' => $member->id,
            'title' => 'Top Secret Draft Project Title',
        ]);

        foreach ([$this->get($member->profileUrl()), $this->actingAs(User::factory()->create())->get($member->profileUrl())] as $response) {
            $response->assertOk();
            $response->assertDontSee('Top Secret Draft Project Title', false);
        }

        $content = $this->get($member->profileUrl())->getContent();
        $this->assertStringContainsString('Started <span class="text-zinc-400">(0)</span>', $content);

        // …but the owner still sees it in their own workspace.
        $this->actingAs($member)->get(route('dashboard'))->assertOk()->assertSee('Top Secret Draft Project Title', false);
        $this->assertSame($draft->id, $draft->fresh()->id);
    }

    public function test_unpublishing_hides_project_from_public_profile_immediately(): void
    {
        $member = User::factory()->create();
        $project = $this->publishedProject(['owner_id' => $member->id, 'title' => 'Soon Hidden Project']);

        $this->get($member->profileUrl())->assertSee('Soon Hidden Project', false);

        $project->update(['publication_status' => PublicationStatus::Draft]);

        $content = $this->get($member->profileUrl())->assertOk()->getContent();
        $this->assertStringNotContainsString('Soon Hidden Project', $content);
        $this->assertStringContainsString('Started <span class="text-zinc-400">(0)</span>', $content);
    }

    public function test_archived_projects_stay_visible_with_label(): void
    {
        $member = User::factory()->create();
        $project = $this->publishedProject(['owner_id' => $member->id, 'activity' => ProjectActivity::Archived]);

        $this->get($member->profileUrl())->assertOk()
            ->assertSee($project->title, false)
            ->assertSee('Archived', false);
    }

    public function test_profile_update_only_ever_touches_self(): void
    {
        $alice = User::factory()->create(['bio' => 'Alice bio.']);
        $bob = User::factory()->create();

        // There is no target-user parameter: Bob's PATCH edits Bob.
        $this->actingAs($bob)
            ->patch(route('profile.public.update'), ['bio' => 'Bob was here.'])
            ->assertRedirect(route('profile.edit'));

        $this->assertSame('Alice bio.', $alice->fresh()->bio);
        $this->assertSame('Bob was here.', $bob->fresh()->bio);
    }

    public function test_display_name_change_keeps_attribution_links_working(): void
    {
        $author = User::factory()->create(['name' => 'Old Name', 'handle' => 'steady-handle']);
        $project = $this->publishedProject();
        $thread = DiscussionPost::factory()->for($project, 'project')->create(['user_id' => $author->id]);

        $author->update(['name' => 'New Name']);

        $content = $this->get(route('projects.discussion.show', [$project, $thread]))->assertOk()->getContent();
        $this->assertStringContainsString('New Name', $content);
        $this->assertStringContainsString('/u/steady-handle', $content);
    }
}
