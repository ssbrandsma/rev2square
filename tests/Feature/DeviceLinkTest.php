<?php

namespace Tests\Feature;

use App\Enums\ProjectActivity;
use App\Enums\ProjectPhase;
use App\Enums\PublicationStatus;
use App\Models\Device;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeviceLinkTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Sound Revival',
            'pitch' => 'A test pitch.',
            'body' => 'Story.',
            'phase' => ProjectPhase::ResearchFeasibility->value,
            'activity' => ProjectActivity::Active->value,
            'publication_status' => PublicationStatus::Draft->value,
        ], $overrides);
    }

    public function test_create_links_device_with_variant(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('projects.store'), $this->payload([
            'device_manufacturer' => 'Bose',
            'device_model' => 'SoundTouch',
            'device_hardware_revision' => 'A1',
            'device_firmware_version' => '2.0',
        ]))->assertRedirect();

        $project = Project::where('title', 'Sound Revival')->firstOrFail();
        $device = $project->devices()->firstOrFail();
        $this->assertSame('Bose', $device->manufacturer);
        $this->assertSame('SoundTouch', $device->model);
        $this->assertSame('Bose SoundTouch', $device->displayName());

        $pivot = DB::table('project_devices')->where('project_id', $project->id)->firstOrFail();
        $variant = $device->variants()->findOrFail($pivot->device_variant_id);
        $this->assertSame('A1', $variant->hardware_revision);
        $this->assertSame('2.0', $variant->firmware_version);
    }

    public function test_edit_links_replaces_and_unlinks(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $base = $this->payload(['title' => $project->title]);

        // Link.
        $this->actingAs($user)->put(
            route('projects.update', $project),
            $base + ['device_manufacturer' => 'Logitech', 'device_model' => 'Squeezebox']
        )->assertRedirect();

        $this->assertSame('Logitech Squeezebox', $project->fresh()->devices()->firstOrFail()->displayName());

        // Prefilled on the edit form, and visible on the project page.
        $this->actingAs($user)->get(route('projects.edit', $project))
            ->assertOk()
            ->assertSee('value="Logitech"', false)
            ->assertSee('value="Squeezebox"', false);
        $this->actingAs($user)->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Logitech Squeezebox');

        // Replace: the old device row survives, the link moves.
        $this->actingAs($user)->put(
            route('projects.update', $project),
            $base + ['device_manufacturer' => 'Bose', 'device_model' => 'SoundTouch']
        )->assertRedirect();

        $this->assertSame(1, $project->fresh()->devices()->count());
        $this->assertSame('Bose SoundTouch', $project->fresh()->devices()->firstOrFail()->displayName());
        $this->assertSame(1, Device::where('model', 'Squeezebox')->count());

        // Blank fields unlink without deleting the device row.
        $this->actingAs($user)->put(
            route('projects.update', $project),
            $base + ['device_manufacturer' => '', 'device_model' => '']
        )->assertRedirect();

        $this->assertSame(0, $project->fresh()->devices()->count());
        $this->assertSame(1, Device::where('model', 'SoundTouch')->count());
    }

    public function test_ordinary_editing_never_mutates_shared_device(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        $sibling = Project::factory()->create(['owner_id' => $other->id]);

        $device = Device::factory()->create(['manufacturer' => 'Bose', 'model' => 'SoundTouch']);
        $project->devices()->sync([$device->id => ['device_variant_id' => null]]);
        $sibling->devices()->sync([$device->id => ['device_variant_id' => null]]);
        $before = $device->fresh()->updated_at->toIso8601String();

        // Re-saving the same values leaves the shared row byte-identical.
        $this->actingAs($owner)->put(
            route('projects.update', $project),
            $this->payload(['title' => $project->title, 'device_manufacturer' => 'Bose', 'device_model' => 'SoundTouch'])
        )->assertRedirect();

        $this->assertSame($before, $device->fresh()->updated_at->toIso8601String());
        $this->assertSame(1, Device::where('manufacturer', 'Bose')->where('model', 'SoundTouch')->count());

        // Moving one project away leaves the sibling link untouched.
        $this->actingAs($owner)->put(
            route('projects.update', $project),
            $this->payload(['title' => $project->title, 'device_manufacturer' => 'Acme', 'device_model' => 'Widget'])
        )->assertRedirect();

        $this->assertSame('Bose SoundTouch', $sibling->fresh()->devices()->firstOrFail()->displayName());
        $this->assertSame('Acme Widget', $project->fresh()->devices()->firstOrFail()->displayName());
    }

    public function test_stranger_cannot_link_device(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        $this->actingAs($stranger)->put(
            route('projects.update', $project),
            $this->payload(['title' => $project->title, 'device_manufacturer' => 'Bose', 'device_model' => 'SoundTouch'])
        )->assertForbidden();

        $this->assertSame(0, $project->fresh()->devices()->count());
        $this->assertSame(0, Device::count());
    }

    public function test_device_fields_are_validated(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $base = $this->payload(['title' => $project->title]);

        $this->actingAs($user)->put(
            route('projects.update', $project),
            $base + ['device_manufacturer' => 'Bose', 'device_model' => '']
        )->assertSessionHasErrors('device_model');

        $this->actingAs($user)->put(
            route('projects.update', $project),
            $base + ['device_model' => str_repeat('x', 256)]
        )->assertSessionHasErrors('device_model');

        $this->assertSame(0, $project->fresh()->devices()->count());
        $this->assertSame(0, Device::count());
    }

    public function test_show_page_points_maintainer_to_linking(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        $this->actingAs($owner)->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('No device linked yet.')
            ->assertSee('Edit project to link one');
    }
}
