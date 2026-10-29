<?php

namespace Database\Seeders;

use App\Enums\MilestoneStatus;
use App\Enums\ProjectActivity;
use App\Enums\ProjectPhase;
use App\Enums\PublicationStatus;
use App\Enums\TaskStatus;
use App\Models\Device;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Authentic showcase content. Every factual claim below comes from the PRD
 * (§9.1 Bublcam, §9.2 Squeezebox). Milestones and tasks are maintainer
 * plans, never claimed achievements; nothing here asserts AI results,
 * experiment outcomes, contributor counts, or metrics.
 */
class ShowcaseSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::firstOrCreate(
            ['email' => 'maintainer@rev2square.test'],
            [
                'name' => 'Rev²Square Maintainer',
                'password' => Hash::make('password'),
            ]
        );

        $this->seedBublcam($owner);
        $this->seedSqueezebox($owner);
    }

    private function seedBublcam(User $owner): void
    {
        $device = Device::firstOrCreate(
            ['model' => 'Bublcam'],
            [
                'category' => '360° camera',
                'description' => '360° camera with legacy app and cloud dependencies. '
                    .'Revival research investigates offline use and modern Android companion functionality.',
            ]
        );

        $project = Project::updateOrCreate(
            ['slug' => 'bublcam-revival'],
            [
                'owner_id' => $owner->id,
                'title' => 'Bublcam Revival',
                'pitch' => 'Investigating how to keep the Bublcam 360° camera useful once its legacy app and cloud services are gone.',
                'body' => "The Bublcam is a 360° camera whose companion app and cloud dependencies are no longer maintained.\n\n"
                    ."This project collects the existing public research, maps what the hardware and software can still do, and works out the most informative next tests toward offline revival and a modern Android companion.\n\n"
                    .'Planned — not yet demonstrated: offline capture workflow, Android companion functionality. No full device support is claimed until real tests establish it.',
                'phase' => ProjectPhase::ResearchFeasibility,
                'activity' => ProjectActivity::Active,
                'publication_status' => PublicationStatus::Published,
            ]
        );
        $project->devices()->syncWithoutDetaching([$device->id]);

        $map = $project->milestones()->updateOrCreate(
            ['title' => 'Map current app and cloud dependencies'],
            [
                'description' => 'Planned: inventory every legacy dependency from public sources before proposing fixes.',
                'status' => MilestoneStatus::Planned,
                'sort_order' => 1,
            ]
        );
        $project->milestones()->updateOrCreate(
            ['title' => 'Define first offline experiments'],
            [
                'description' => 'Planned: turn the dependency map into low-risk, information-rich tests with pass/fail criteria.',
                'status' => MilestoneStatus::Planned,
                'sort_order' => 2,
            ]
        );

        $project->tasks()->updateOrCreate(
            ['title' => 'Document the companion app network dependencies'],
            [
                'milestone_id' => $map->id,
                'description' => 'Planned: list the endpoints and services the legacy app contacts, with sources.',
                'skills' => ['research', 'android', 'networking'],
                'status' => TaskStatus::Open,
                'definition_of_done' => 'Dependency list with a source link per entry.',
                'requires_hardware' => false,
            ]
        );
        $project->tasks()->updateOrCreate(
            ['title' => 'Capture Bublcam network traffic for protocol analysis'],
            [
                'milestone_id' => $map->id,
                'description' => 'Planned: record what the camera sends on a local network so the protocol can be studied.',
                'skills' => ['networking', 'protocol-research'],
                'status' => TaskStatus::Open,
                'definition_of_done' => 'Annotated capture with reproducible setup notes.',
                'requires_hardware' => true,
            ]
        );
    }

    private function seedSqueezebox(User $owner): void
    {
        $device = Device::firstOrCreate(
            ['model' => 'Squeezebox Radio'],
            [
                'description' => 'Network music player. Revival work explores standalone use independent of legacy online infrastructure '
                    .'under real constraints: legacy ARM hardware, proxy and local services.',
            ]
        );

        $project = Project::updateOrCreate(
            ['slug' => 'squeezebox-standalone'],
            [
                'owner_id' => $owner->id,
                'title' => 'Squeezebox Standalone',
                'pitch' => 'Making the Squeezebox Radio useful independently of legacy online infrastructure.',
                'body' => "Independent prior work explored making the Squeezebox Radio stand on its own: local services, proxy approaches, and on-device applets within the limits of legacy ARM hardware.\n\n"
                    .'That history is background material, not a finished result. This project tracks which approaches are verified by real tests, documents the constraints honestly, and organizes the remaining work.',
                'phase' => ProjectPhase::Development,
                'activity' => ProjectActivity::Active,
                'publication_status' => PublicationStatus::Published,
            ]
        );
        $project->devices()->syncWithoutDetaching([$device->id]);

        $verify = $project->milestones()->updateOrCreate(
            ['title' => 'Verify local-service playback path'],
            [
                'description' => 'Planned: confirm end-to-end playback against local services on real hardware, with conditions recorded.',
                'status' => MilestoneStatus::Planned,
                'sort_order' => 1,
            ]
        );

        $project->tasks()->updateOrCreate(
            ['title' => 'Reproduce local playback on a second device revision'],
            [
                'milestone_id' => $verify->id,
                'description' => 'Planned: repeat the documented setup on different hardware to check which revisions are covered.',
                'skills' => ['testing', 'hardware'],
                'status' => TaskStatus::Open,
                'definition_of_done' => 'Test report with firmware/hardware revisions and outcome.',
                'requires_hardware' => true,
            ]
        );
        $project->tasks()->updateOrCreate(
            ['title' => 'Write the standalone setup guide from verified steps only'],
            [
                'milestone_id' => $verify->id,
                'description' => 'Planned: turn verified observations into a setup guide; mark every unverified step as such.',
                'skills' => ['documentation', 'testing'],
                'status' => TaskStatus::Open,
                'definition_of_done' => 'Guide where each step links to its verifying test.',
                'requires_hardware' => false,
            ]
        );
    }
}
