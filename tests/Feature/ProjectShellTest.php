<?php

namespace Tests\Feature;

use App\Models\ContributorTask;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectShellTest extends TestCase
{
    use RefreshDatabase;

    private function projectNav(string $html): string
    {
        $start = strpos($html, '<nav aria-label="Project sections"');
        $this->assertNotFalse($start, 'Project sections nav is missing.');
        $end = strpos($html, '</nav>', $start);
        $this->assertNotFalse($end, 'Project sections nav is not closed.');

        return substr($html, $start, $end - $start);
    }

    private function assertTabActive(string $nav, string $label): void
    {
        $pattern = '/<a\b[^>]*aria-current="page"[^>]*>\s*'.preg_quote($label, '/').'/';
        $this->assertMatchesRegularExpression($pattern, $nav, "Tab '{$label}' is not marked active.");
    }

    private function assertTabInactive(string $nav, string $label): void
    {
        $pattern = '/<a\b[^>]*aria-current="page"[^>]*>\s*'.preg_quote($label, '/').'/';
        $this->assertDoesNotMatchRegularExpression($pattern, $nav, "Tab '{$label}' should not be active.");
    }

    public function test_guest_sees_public_tabs_but_not_evidence_or_agent(): void
    {
        $project = Project::factory()->create();

        $html = $this->get(route('projects.team.index', $project))->assertOk()->getContent();
        $nav = $this->projectNav($html);

        foreach (['Overview', 'Discussion', 'Updates', 'Chat', 'Team', 'Architecture', 'Roadmap', 'Tasks'] as $label) {
            $this->assertStringContainsString($label, $nav);
        }
        $this->assertStringNotContainsString(route('projects.evidence', $project), $nav);
        $this->assertStringNotContainsString(route('projects.agent.index', $project), $nav);
        $this->assertTabActive($nav, 'Team');
        $this->assertStringContainsString(
            '<title>Team — '.e($project->title).' — Rev²Square</title>', $html);
    }

    public function test_signed_in_member_sees_agent_but_not_evidence(): void
    {
        $project = Project::factory()->create();

        $html = $this->actingAs(User::factory()->create())
            ->get(route('projects.team.index', $project))->assertOk()->getContent();
        $nav = $this->projectNav($html);

        $this->assertStringContainsString(route('projects.agent.index', $project), $nav);
        $this->assertStringNotContainsString(route('projects.evidence', $project), $nav);
    }

    public function test_manager_sees_evidence_tab_with_active_state(): void
    {
        $project = Project::factory()->create();

        $html = $this->actingAs($project->owner)
            ->get(route('projects.evidence', $project))->assertOk()->getContent();
        $nav = $this->projectNav($html);

        $this->assertStringContainsString(route('projects.evidence', $project), $nav);
        $this->assertTabActive($nav, 'Evidence');
        $this->assertStringContainsString(
            '<title>Evidence — '.e($project->title).' — Rev²Square</title>', $html);
    }

    public function test_nested_pages_keep_parent_tab_active(): void
    {
        $project = Project::factory()->create();
        $task = ContributorTask::factory()->create(['project_id' => $project->id]);
        $member = User::factory()->create();

        $cases = [
            // [route, params, acting-as-owner?, expected active tab, expected title prefix]
            ['projects.milestones.create', [$project], true, 'Roadmap', 'Add a milestone'],
            ['projects.tasks.create', [$project], true, 'Tasks', 'Add a task'],
            ['projects.tasks.show', [$project, $task], false, 'Tasks', $task->title],
            ['projects.offers.create', [$project], false, 'Team', 'Contribute'],
            ['projects.test-reports.create', [$project], false, 'Tasks', 'File a test report'],
        ];

        foreach ($cases as [$route, $params, $asOwner, $active, $titlePrefix]) {
            $user = $asOwner ? $project->owner : $member;
            $html = $this->actingAs($user)->get(route($route, $params))->assertOk()->getContent();
            $nav = $this->projectNav($html);

            $this->assertTabActive($nav, $active);
            $this->assertStringContainsString(
                '<title>'.e($titlePrefix).' — '.e($project->title).' — Rev²Square</title>', $html);
        }
    }

    public function test_overview_keeps_roadmap_and_tasks_anchor_destinations(): void
    {
        $project = Project::factory()->create();

        $html = $this->get(route('projects.show', $project))->assertOk()->getContent();
        $nav = $this->projectNav($html);

        $this->assertTabActive($nav, 'Overview');
        $this->assertTabInactive($nav, 'Roadmap');
        $this->assertTabInactive($nav, 'Tasks');
        $this->assertStringContainsString(route('projects.show', $project).'#roadmap', $nav);
        $this->assertStringContainsString(route('projects.show', $project).'#tasks', $nav);
        $this->assertStringContainsString('id="roadmap"', $html);
        $this->assertStringContainsString('id="tasks"', $html);
        $this->assertStringContainsString(
            '<title>'.e($project->title).' — Rev²Square</title>', $html);
    }

    public function test_draft_project_shell_leaks_no_title_to_outsiders(): void
    {
        $project = Project::factory()->draft()->create();

        $html = $this->get(route('projects.team.index', $project))->assertForbidden()->getContent();

        $this->assertStringNotContainsString($project->title, $html);
        $this->assertStringNotContainsString('<nav aria-label="Project sections"', $html);
    }

    public function test_error_pages_render_site_suffix_once(): void
    {
        $html = $this->get('/projects/does-not-exist-xyz')->assertNotFound()->getContent();

        $this->assertStringContainsString('<title>Page not found — Rev²Square</title>', $html);
        $this->assertStringNotContainsString('Rev²Square — Rev²Square', $html);
    }
}
