<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Enums\GlobalRole;
use App\Jobs\AnalyzeArchitectureJob;
use App\Jobs\AnalyzeProjectJob;
use App\Models\AdminAuditEvent;
use App\Models\AgentConversation;
use App\Models\AiUsageEntry;
use App\Models\AppSetting;
use App\Models\ArchitectureSnapshot;
use App\Models\EvidenceSource;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Services\Admin\AiBudgets;
use App\Services\Admin\AppSettings;
use App\Services\AI\AiDeniedException;
use App\Services\Revival\AnalysisRunRepository;
use App\Services\Revival\ArchitectureService;
use App\Services\Revival\RevivalAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\Support\MockAIProvider;
use Tests\TestCase;

class AdminUsageBudgetsTest extends TestCase
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

    public function test_secret_is_write_only_and_encrypted(): void
    {
        // The developer environment may export a real MODEL_API_KEY,
        // which correctly locks the UI. Neutralize it for this process
        // only (fake key below; the real value is never read or used).
        $savedServer = $_SERVER['MODEL_API_KEY'] ?? null;
        $savedEnv = $_ENV['MODEL_API_KEY'] ?? null;
        $savedGetenv = getenv('MODEL_API_KEY');
        unset($_SERVER['MODEL_API_KEY'], $_ENV['MODEL_API_KEY']);
        putenv('MODEL_API_KEY');

        try {
            $admin = $this->makeAdmin();
            $secret = 'test-key-'.str()->random(20);

            $this->actingAs($admin)->withSession($this->confirmedSession())
                ->patch(route('admin.settings.secrets.update'), [
                    'api_key' => $secret,
                    'reason' => 'Initial test key.',
                ])->assertRedirect();

            $row = AppSetting::where('key', 'meta.api_key')->first();
            $this->assertNotNull($row);
            $this->assertTrue($row->is_secret);
            $this->assertNotSame($secret, $row->getAttributes()['value']);
            $this->assertSame($secret, Crypt::decryptString($row->getAttributes()['value']));
            $this->assertSame($secret, AppSettings::get('meta.api_key'));

            // Never rendered back: page shows status metadata only.
            $page = $this->actingAs($admin)->get(route('admin.settings.secrets'));
            $page->assertOk();
            $page->assertSee('configured');
            $page->assertDontSee($secret, false);

            // Never in audit payloads.
            $audit = AdminAuditEvent::where('action', 'secret.replaced')->latest('id')->first();
            $this->assertNotNull($audit);
            $this->assertStringNotContainsString($secret, json_encode($audit->toArray()));

            // Blank submission retains the key.
            $this->actingAs($admin)->withSession($this->confirmedSession())
                ->patch(route('admin.settings.secrets.update'), ['api_key' => '', 'reason' => 'No-op.'])
                ->assertSessionHas('status');
            $this->assertSame($secret, AppSettings::get('meta.api_key'));

            // Explicit removal is separate.
            $this->actingAs($admin)->withSession($this->confirmedSession())
                ->delete(route('admin.settings.secrets.destroy'), ['reason' => 'Rotate out.']);
            $this->assertNull(AppSettings::get('meta.api_key'));
            $this->assertNotNull(AdminAuditEvent::where('action', 'secret.removed')->first());
        } finally {
            if ($savedServer !== null) {
                $_SERVER['MODEL_API_KEY'] = $savedServer;
            }
            if ($savedEnv !== null) {
                $_ENV['MODEL_API_KEY'] = $savedEnv;
            }
            if ($savedGetenv !== false) {
                putenv('MODEL_API_KEY='.$savedGetenv);
            }
        }
    }

    public function test_setting_precedence_and_worker_propagation(): void
    {
        $admin = $this->makeAdmin();

        // Database value applies to runtime config.
        AppSettings::set('ai.agent_token_budget', 12345, $admin->id);
        AppSettings::applyToConfig();
        $this->assertSame(12345, config('ai.agent_token_budget'));
        $this->assertSame('database', AppSettings::source('ai.agent_token_budget'));

        // Environment wins when set (simulated for this process only).
        $_SERVER['AI_AGENT_TOKEN_BUDGET'] = '777';
        try {
            $this->assertTrue(AppSettings::isLocked('ai.agent_token_budget'));
            $this->assertSame(777, AppSettings::get('ai.agent_token_budget'));
            $this->assertSame('environment', AppSettings::source('ai.agent_token_budget'));

            // Locked values cannot be changed through the UI.
            $response = $this->actingAs($admin)->withSession($this->confirmedSession())
                ->patch(route('admin.settings.update'), [
                    'settings' => ['ai.agent_token_budget' => 999],
                    'reason' => 'Try locked.',
                ]);
            $response->assertSessionHas('error');
            $this->assertSame(777, AppSettings::get('ai.agent_token_budget'));
        } finally {
            unset($_SERVER['AI_AGENT_TOKEN_BUDGET']);
        }

        // Unknown keys are rejected outright.
        $this->expectException(\InvalidArgumentException::class);
        AppSettings::get('nope.not_real');
    }

    public function test_console_promote_requires_confirmation_and_audits(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);

        $this->artisan('admin:promote', ['identifier' => 'owner@example.com'])
            ->expectsConfirmation('Type yes to confirm this promotion.', 'no')
            ->assertSuccessful();
        $this->assertFalse($user->fresh()->isAdmin());

        $this->artisan('admin:promote', ['identifier' => 'owner@example.com'])
            ->expectsConfirmation('Type yes to confirm this promotion.', 'yes')
            ->assertSuccessful();
        $this->assertTrue($user->fresh()->isAdmin());
        $this->assertNotNull(AdminAuditEvent::where('action', 'role.promoted')->first());

        $this->artisan('admin:promote', ['identifier' => 'nobody@example.com'])->assertFailed();
    }

    public function test_revival_and_architecture_runs_attribute_usage(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->draft()->create(['owner_id' => $owner->id]);
        $repository = Repository::factory()->create([
            'project_id' => $project->id, 'import_status' => 'ready',
            'last_commit_sha' => str_repeat('d', 40),
        ]);
        EvidenceSource::factory()->create([
            'project_id' => $project->id, 'repository_id' => $repository->id,
            'commit_sha' => $repository->last_commit_sha, 'content' => 'content here',
            'content_hash' => hash('sha256', 'content here'),
        ]);

        MockAIProvider::fake([MockAIProvider::reportPayload([
            ['source_id' => EvidenceSource::first()->id, 'path' => 'x'],
        ])]);
        $run = app(AnalysisRunRepository::class)->createRun($project, $owner, $repository, 'mock', 'mock');
        (new AnalyzeProjectJob($run->id))->handle(
            app(RevivalAnalysisService::class),
            app(AnalysisRunRepository::class)
        );

        $entry = AiUsageEntry::where('operation', 'analysis-run-'.$run->id)->first();
        $this->assertNotNull($entry);
        $this->assertSame($owner->id, $entry->user_id);
        $this->assertSame($project->id, $entry->project_id);
        $this->assertSame('revival_investigator', $entry->feature);
        $this->assertSame('succeeded', $entry->status);
        $this->assertSame(100, $entry->input_tokens);
        $this->assertNull($entry->estimated_cost_micros);

        $evidence = EvidenceSource::first();
        MockAIProvider::fake([MockAIProvider::architecturePayload([
            ['source_id' => $evidence->id, 'path' => $evidence->path, 'content_hash' => $evidence->content_hash],
        ])]);
        $snapshot = ArchitectureSnapshot::create([
            'project_id' => $project->id, 'repository_id' => $repository->id, 'user_id' => $owner->id,
            'status' => AnalysisStatus::Queued, 'cache_key' => 'k1',
            'schema_version' => '1.0', 'prompt_version' => '1.0',
        ]);
        (new AnalyzeArchitectureJob($snapshot->id))->handle(
            app(ArchitectureService::class)
        );

        $arch = AiUsageEntry::where('operation', 'architecture-snapshot-'.$snapshot->id)->first();
        $this->assertNotNull($arch);
        $this->assertSame('architecture_investigator', $arch->feature);
        $this->assertSame($owner->id, $arch->user_id);
    }

    public function test_agent_turn_records_each_attempt_without_double_counting(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->draft()->create(['owner_id' => $owner->id]);
        $repository = Repository::factory()->create([
            'project_id' => $project->id, 'import_status' => 'ready',
            'last_commit_sha' => str_repeat('e', 40),
        ]);
        EvidenceSource::factory()->create([
            'project_id' => $project->id, 'repository_id' => $repository->id,
            'path' => 'main.py', 'commit_sha' => $repository->last_commit_sha,
            'content' => 'pairing code', 'content_hash' => hash('sha256', 'pairing code'),
        ]);
        $sourceId = EvidenceSource::first()->id;

        MockAIProvider::fake([
            ['__tool_calls' => [['id' => 'c1', 'name' => 'search_evidence', 'arguments' => ['query' => 'pairing']]], 'content' => ''],
            ['__tool_calls' => [['id' => 'c2', 'name' => 'read_evidence', 'arguments' => ['source_id' => $sourceId]]], 'content' => ''],
            'Done.',
        ]);

        $this->actingAs($owner)->post(route('projects.agent.store', $project), ['question' => 'How?']);
        $conversation = AgentConversation::first();

        $rows = AiUsageEntry::where('operation', 'conversation-'.$conversation->id)->orderBy('attempt_seq')->get();
        $this->assertCount(3, $rows);
        $this->assertSame([1, 2, 3], $rows->pluck('attempt_seq')->all());
        $this->assertSame('repo_agent', $rows->first()->feature);
        $this->assertSame($owner->id, $rows->first()->user_id);
        // One correlation across the turn; no aggregate row invented.
        $this->assertCount(1, $rows->pluck('correlation_id')->unique());
    }

    public function test_budget_reservation_denies_over_allowance(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['ai_daily_request_limit' => 1, 'ai_daily_token_limit' => 100000])->save();

        $first = AiBudgets::reserve($user->fresh(), 10);
        $this->assertGreaterThan(0, $first['counter_id']);

        $this->expectException(AiDeniedException::class);
        AiBudgets::reserve($user->fresh(), 10);
    }

    public function test_spend_cap_without_pricing_refuses_requests(): void
    {
        $admin = $this->makeAdmin();
        AppSettings::set('ai.daily_cost_cap_micros', 1000000, $admin->id);

        $user = User::factory()->create();

        try {
            AiBudgets::reserve($user, 10);
            $this->fail('Expected AiDeniedException.');
        } catch (AiDeniedException $e) {
            $this->assertSame('budget_pricing_unknown', $e->reason);
        }
    }

    public function test_users_see_only_their_own_usage(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $project = Project::factory()->draft()->create(['owner_id' => $owner->id]);
        $repository = Repository::factory()->create([
            'project_id' => $project->id, 'import_status' => 'ready',
            'last_commit_sha' => str_repeat('f', 40),
        ]);
        EvidenceSource::factory()->create([
            'project_id' => $project->id, 'repository_id' => $repository->id,
            'path' => 'a.py', 'commit_sha' => $repository->last_commit_sha,
            'content' => 'zz top', 'content_hash' => hash('sha256', 'zz top'),
        ]);

        MockAIProvider::fake(['Answer one.']);
        $this->actingAs($owner)->post(route('projects.agent.store', $project), ['question' => 'Q1?']);

        $mine = $this->actingAs($owner)->get(route('my-usage.show'));
        $mine->assertOk();
        $mine->assertSee('repo_agent');

        $theirs = $this->actingAs($other)->get(route('my-usage.show'));
        $theirs->assertOk();
        $theirs->assertSee('No recorded AI usage yet.');
    }

    public function test_usage_csv_export_is_metadata_only_and_injection_safe(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create(['email' => '=cmd@example.com']);

        AiUsageEntry::create([
            'idempotency_key' => 'k1', 'user_id' => $user->id, 'feature' => 'repo_agent',
            'operation' => '=HYPERLINK("http://evil")', 'attempt_seq' => 1,
            'provider' => 'mock', 'status' => 'succeeded',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.usage.export'));
        $response->assertOk();
        $body = $response->streamedContent();
        $this->assertStringContainsString("'=cmd@example.com", $body);
        $this->assertStringContainsString("'=HYPERLINK", $body);
        $this->assertStringNotContainsString('password', strtolower($body));
    }

    public function test_admin_pages_render_with_key_markers(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertSee('AI usage today');
        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.settings.index'))->assertOk()->assertSee('ai.enabled');
        $this->actingAs($admin)->get(route('admin.usage.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.audit.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.moderation.index'))->assertOk();
    }
}
