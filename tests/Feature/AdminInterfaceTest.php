<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Enums\GlobalRole;
use App\Enums\ReportStatus;
use App\Jobs\AnalyzeProjectJob;
use App\Models\AdminAuditEvent;
use App\Models\AgentConversation;
use App\Models\AiUsageEntry;
use App\Models\AnalysisRun;
use App\Models\ChatMessage;
use App\Models\EvidenceSource;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Services\Revival\AnalysisRunRepository;
use App\Services\Revival\RevivalAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\MockAIProvider;
use Tests\TestCase;

class AdminInterfaceTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['global_role' => GlobalRole::Admin])->save();

        return $admin->fresh();
    }

    private function confirmedSession(): array
    {
        return ['auth.password_confirmed_at' => time()];
    }

    public function test_user_cannot_access_admin_routes_or_craft_role_changes(): void
    {
        $user = User::factory()->create();
        $victim = User::factory()->create();

        $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.settings.secrets'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.usage.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.audit.index'))->assertForbidden();

        $this->actingAs($user)->withSession($this->confirmedSession())->patch(route('admin.users.update', $victim), [
            'global_role' => 'admin',
            'ai_enabled' => true,
            'ai_unlimited' => false,
            'reason' => 'crafted',
        ])->assertForbidden();
        $this->assertSame(GlobalRole::User, $victim->fresh()->global_role);

        // Crafted registration field cannot escalate.
        $this->post(route('logout'));
        $this->post(route('register'), [
            'name' => 'Crafty',
            'email' => 'crafty@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'global_role' => 'admin',
        ]);
        $this->assertSame(GlobalRole::User, User::where('email', 'crafty@example.com')->first()->global_role);

        // Mass assignment cannot escalate either.
        $mass = User::create(['name' => 'M', 'email' => 'm@example.com', 'password' => 'x', 'global_role' => 'admin']);
        $this->assertSame(GlobalRole::User, $mass->fresh()->global_role);
    }

    public function test_moderator_reviews_reports_but_reaches_no_secrets_or_admin(): void
    {
        $moderator = User::factory()->create();
        $moderator->forceFill(['global_role' => GlobalRole::Moderator])->save();

        $this->actingAs($moderator)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($moderator)->get(route('admin.settings.secrets'))->assertForbidden();
        $this->actingAs($moderator)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($moderator)->get(route('admin.usage.index'))->assertForbidden();

        // Lounge report review works for moderators.
        $message = ChatMessage::factory()->create(['project_id' => null]);
        $report = $message->reports()->create([
            'user_id' => $moderator->id,
            'reason' => 'Test report.',
            'status' => ReportStatus::Pending,
        ]);

        $this->actingAs($moderator)->get(route('admin.moderation.index'))->assertOk();
        $this->actingAs($moderator)->post(route('admin.moderation.review', $report), [
            'decision' => 'dismiss',
        ])->assertRedirect();
        $this->assertSame(ReportStatus::Dismissed, $report->fresh()->status);

        // Moderation grants no research access: a private conversation in
        // a project the moderator cannot view stays forbidden.
        $owner = User::factory()->create();
        $project = Project::factory()->draft()->create(['owner_id' => $owner->id]);
        $conversation = AgentConversation::create([
            'project_id' => $project->id, 'user_id' => $owner->id, 'title' => 'private',
        ]);
        $this->actingAs($moderator)->get(route('projects.agent.show', [$project, $conversation]))->assertForbidden();
    }

    public function test_admin_manages_user_through_ui_with_audit(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create();

        $page = $this->actingAs($admin)->get(route('admin.users.show', $user));
        $page->assertOk();
        $page->assertSee($user->email);

        $this->actingAs($admin)->withSession($this->confirmedSession())->patch(route('admin.users.update', $user), [
            'global_role' => 'moderator',
            'ai_enabled' => true,
            'ai_daily_request_limit' => 50,
            'ai_daily_token_limit' => null,
            'ai_unlimited' => false,
            'reason' => 'Promoted for community review.',
        ])->assertRedirect(route('admin.users.show', $user));

        $fresh = $user->fresh();
        $this->assertSame(GlobalRole::Moderator, $fresh->global_role);
        $this->assertSame(50, $fresh->ai_daily_request_limit);

        $audit = AdminAuditEvent::where('action', 'account.updated')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame('Promoted for community review.', $audit->reason);
        $this->assertSame('user', $audit->before['global_role']);
        $this->assertSame('moderator', $audit->after['global_role']);
    }

    public function test_suspension_revokes_access_and_blocks_queued_ai(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $project = Project::factory()->draft()->create(['owner_id' => $user->id]);

        // Existing session loses access on the next request.
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->actingAs($admin)->withSession($this->confirmedSession())->post(route('admin.users.suspend', $user), [
            'reason' => 'Spam investigation.',
        ])->assertRedirect();
        $this->assertTrue($user->fresh()->isSuspended());

        // New logins are refused with an explanation.
        $this->post(route('logout'));
        $login = $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);
        $login->assertRedirect(route('suspended'));

        // Queued AI work by the suspended user never reaches the provider.
        MockAIProvider::fake(['unreachable']);
        $repository = Repository::factory()->create([
            'project_id' => $project->id, 'import_status' => 'ready',
            'last_commit_sha' => str_repeat('b', 40),
        ]);
        EvidenceSource::factory()->create([
            'project_id' => $project->id, 'repository_id' => $repository->id,
            'commit_sha' => $repository->last_commit_sha, 'content' => 'x',
            'content_hash' => hash('sha256', 'x'),
        ]);
        $run = AnalysisRun::create([
            'project_id' => $project->id, 'user_id' => $user->id, 'repository_id' => $repository->id,
            'status' => AnalysisStatus::Queued, 'provider' => 'mock', 'model' => 'mock',
        ]);

        (new AnalyzeProjectJob($run->id))->handle(
            app(RevivalAnalysisService::class),
            app(AnalysisRunRepository::class)
        );

        $this->assertSame([], MockAIProvider::$requests);
        $this->assertSame(0, AiUsageEntry::where('operation', 'analysis-run-'.$run->id)->count());
        $this->assertSame('ACCESS_REVOKED', $run->fresh()->error_code);

        // Attribution survives suspension.
        $this->assertSame($user->id, $project->fresh()->owner_id);
        $this->assertSame($user->id, $run->fresh()->user_id);
    }

    public function test_reactivation_restores_access_but_keeps_ai_restriction(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $user->forceFill(['ai_enabled' => false])->save();
        $project = Project::factory()->create(['owner_id' => $user->id]);

        $this->actingAs($admin)->withSession($this->confirmedSession())->post(route('admin.users.suspend', $user), ['reason' => 'Test.']);
        $this->actingAs($admin)->withSession($this->confirmedSession())->post(route('admin.users.reactivate', $user), ['reason' => ' Cleared. ']);

        $fresh = $user->fresh();
        $this->assertFalse($fresh->isSuspended());
        $this->assertTrue($fresh->aiDisabled());

        // Web access works again...
        $this->actingAs($fresh)->get(route('projects.show', $project))->assertOk();

        // ...but AI stays restricted with a clear message.
        MockAIProvider::fake(['unreachable']);
        $repository = Repository::factory()->create([
            'project_id' => $project->id, 'import_status' => 'ready',
            'last_commit_sha' => str_repeat('c', 40),
        ]);
        $response = $this->actingAs($fresh)->post(route('projects.agent.store', $project), [
            'question' => 'Hello?',
        ]);
        $response->assertSessionHas('error');
        $this->assertSame([], MockAIProvider::$requests);
    }

    public function test_last_active_admin_is_protected(): void
    {
        $admin = $this->makeAdmin();

        // Self-demotion refused.
        $response = $this->actingAs($admin)->withSession($this->confirmedSession())->patch(route('admin.users.update', $admin), [
            'global_role' => 'user',
            'ai_enabled' => true,
            'ai_unlimited' => false,
            'reason' => 'Demote myself.',
        ]);
        $response->assertSessionHas('error');
        $this->assertTrue($admin->fresh()->isAdmin());

        // Self-suspension refused.
        $response = $this->actingAs($admin)->withSession($this->confirmedSession())->post(route('admin.users.suspend', $admin), [
            'reason' => 'Suspend myself.',
        ]);
        $response->assertSessionHas('error');
        $this->assertFalse($admin->fresh()->isSuspended());

        // With a second admin, the first can step down — but the second
        // is then protected in turn.
        $second = $this->makeAdmin();
        $this->actingAs($second)->withSession($this->confirmedSession())->patch(route('admin.users.update', $admin), [
            'global_role' => 'user',
            'ai_enabled' => true,
            'ai_unlimited' => false,
            'reason' => 'Rotation.',
        ])->assertRedirect();
        $this->assertFalse($admin->fresh()->isAdmin());

        $response = $this->actingAs($second)->withSession($this->confirmedSession())->patch(route('admin.users.update', $second), [
            'global_role' => 'user',
            'ai_enabled' => true,
            'ai_unlimited' => false,
            'reason' => 'Demote the last one.',
        ]);
        $response->assertSessionHas('error');
        $this->assertTrue($second->fresh()->isAdmin());
    }
}
