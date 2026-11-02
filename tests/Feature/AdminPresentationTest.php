<?php

namespace Tests\Feature;

use App\Enums\GlobalRole;
use App\Models\AdminAuditEvent;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkerCapability;
use App\Services\Admin\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPresentationTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['global_role' => GlobalRole::Admin])->save();

        return $admin->fresh();
    }

    public function test_settings_page_shows_readable_labels_with_keys_in_details(): void
    {
        $html = $this->actingAs($this->makeAdmin())
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->getContent();

        // Readable labels lead; stored keys stay inspectable in details.
        $this->assertStringContainsString('Input price', $html);
        $this->assertStringContainsString('Daily spend cap', $html);
        $this->assertStringContainsString('Max upload size', $html);
        $this->assertStringContainsString('Open registration', $html);
        $this->assertStringContainsString('Setting key and source', $html);
        $this->assertStringContainsString('ai.enabled', $html);
        $this->assertStringContainsString('ai.price_input_per_1k_micros', $html);
        $this->assertStringContainsString('Environment variable:', $html);

        // Comprehensible units; stored values untouched.
        $this->assertStringContainsString('About 64 MiB.', $html);
        $this->assertStringContainsString('No price set — costs show as unknown.', $html);

        // Form semantics intact.
        $this->assertStringContainsString('name="settings[ai.enabled]"', $html);
        $this->assertStringContainsString('<title>Application settings — Rev²Square</title>', $html);
    }

    public function test_settings_money_hints_reflect_effective_values(): void
    {
        $admin = $this->makeAdmin();
        AppSettings::set('ai.price_input_per_1k_micros', 2500, $admin->id);
        AppSettings::set('ai.daily_cost_cap_micros', 5000000, $admin->id);

        $html = $this->actingAs($admin)
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('About $0.0025 per 1,000 tokens.', $html);
        $this->assertStringContainsString('About $5 per day.', $html);
        // Raw stored values still render in the inputs.
        $this->assertStringContainsString('value="2500"', $html);
        $this->assertStringContainsString('value="5000000"', $html);
    }

    public function test_audit_page_shows_readable_summaries_with_raw_values(): void
    {
        $admin = $this->makeAdmin();
        AdminAuditEvent::record('setting.updated', $admin, null, 'Tidy up limits.', ['ai.enabled' => '1'], ['ai.enabled' => '0']);

        $html = $this->actingAs($admin)
            ->get(route('admin.audit.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Setting updated', $html);
        $this->assertStringContainsString('Tidy up limits.', $html);
        $this->assertStringContainsString($admin->email, $html);
        $this->assertStringContainsString('Raw values', $html);
        $this->assertStringContainsString('setting.updated', $html);
        $this->assertStringContainsString('before → after', $html);
        $this->assertStringContainsString('Append-only', $html);
        $this->assertStringContainsString('<title>Audit log — Rev²Square</title>', $html);
    }

    public function test_analysis_page_shows_last_checked_and_ops_details(): void
    {
        $html = $this->actingAs($this->makeAdmin())
            ->get(route('admin.analysis.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Execution readiness', $html);
        $this->assertStringContainsString('Ghidra (isolated headless)', $html);
        $this->assertStringContainsString('Last checked', $html);
        $this->assertStringContainsString('No snapshot yet', $html);
        $this->assertStringContainsString('Bundled with the app', $html);
        $this->assertStringContainsString('Ops details', $html);
        $this->assertStringContainsString('Failures (24h)', $html);
        $this->assertStringContainsString('<title>Analysis backends — Rev²Square</title>', $html);
    }

    public function test_analysis_page_shows_real_snapshot_time_when_present(): void
    {
        WorkerCapability::create([
            'adapter' => 'ghidra',
            'available' => true,
            'version' => 'test-version',
            'targets' => [],
            'formats' => [],
            'details' => ['runner_arch' => 'amd64'],
            'captured_at' => now()->subMinutes(5),
            'worker' => 'test-worker',
        ]);

        $html = $this->actingAs($this->makeAdmin())
            ->get(route('admin.analysis.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('5 minutes ago', $html);
        $this->assertStringContainsString('test-version', $html);
    }

    public function test_moderation_empty_state_and_scope_help(): void
    {
        $html = $this->actingAs($this->makeAdmin())
            ->get(route('admin.moderation.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No reports to review', $html);
        $this->assertStringContainsString('What moderation covers', $html);
        $this->assertStringContainsString('grants no access to API keys', $html);
        $this->assertStringContainsString('<title>Report queue — Rev²Square</title>', $html);
    }

    public function test_transfer_form_keeps_safeguards_and_scannable_steps(): void
    {
        $admin = $this->makeAdmin();
        $project = Project::factory()->create();

        $html = $this->actingAs($admin)
            ->get(route('admin.projects.transfer', $project))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('1. Recipient account email', $html);
        $this->assertStringContainsString('2. Previous owner access', $html);
        $this->assertStringContainsString('3. Internal reason (required, recorded in the audit log)', $html);
        $this->assertStringContainsString('never transferred', $html);
        $this->assertStringContainsString('name="keep_previous_owner"', $html);
        $this->assertStringContainsString('Preview transfer', $html);
        $this->assertStringContainsString(
            '<title>Transfer ownership — '.$project->title.' — Rev²Square</title>',
            $html
        );
    }

    public function test_admin_pages_have_meaningful_titles(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create();
        $project = Project::factory()->create();

        $cases = [
            ['admin.dashboard', [], 'Administration'],
            ['admin.users.index', [], 'Users'],
            ['admin.users.show', [$user], $user->name.' — Users'],
            ['admin.users.edit', [$user], 'Change role or AI limits — '.$user->name],
            ['admin.projects.index', [], 'Projects'],
            ['admin.projects.show', [$project], $project->title.' — Projects'],
            ['admin.usage.index', [], 'AI usage'],
            ['admin.usage.user', [$user], $user->name.' — AI usage'],
            ['admin.settings.secrets', [], 'Meta API key'],
        ];

        foreach ($cases as [$route, $params, $title]) {
            $html = $this->actingAs($admin)->get(route($route, $params))->assertOk()->getContent();
            $this->assertStringContainsString('<title>'.$title.' — Rev²Square</title>', $html, "title for {$route}");
            $this->assertSame(1, substr_count($html, '— Rev²Square</title>'), "single title suffix for {$route}");
        }
    }

    public function test_global_pages_have_meaningful_titles_and_one_h1(): void
    {
        $user = User::factory()->create();

        $this->get(route('login'))->assertOk()
            ->assertSee('<title>Log in — Rev²Square</title>', false)
            ->assertSee('<h1', false);
        $this->get(route('register'))->assertOk()
            ->assertSee('<title>Create your account — Rev²Square</title>', false);
        $this->get(route('password.request'))->assertOk()
            ->assertSee('<title>Reset your password — Rev²Square</title>', false);
        $this->get(route('about'))->assertOk()
            ->assertSee('<title>How Rev²Square revivals work — Rev²Square</title>', false);

        $html = $this->actingAs($user)->get(route('profile.edit'))->assertOk()->getContent();
        $this->assertStringContainsString('<title>Profile — Rev²Square</title>', $html);
        $this->assertSame(1, substr_count($html, '<h1'), 'profile page has one h1');

        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('<title>My workspace — Rev²Square</title>', $html);

        $html = $this->actingAs($user)->get(route('online'))->assertOk()->getContent();
        $this->assertStringContainsString('<title>Online now — Rev²Square</title>', $html);
    }
}
