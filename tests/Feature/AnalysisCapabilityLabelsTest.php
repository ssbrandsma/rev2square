<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectArtifact;
use App\Models\User;
use App\Services\Artifacts\ArtifactExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AnalysisCapabilityLabelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unavailable_decompilation_is_qualified_not_green(): void
    {
        Storage::fake('artifacts');
        $owner = User::factory()->create();
        $project = Project::factory()->draft()->create(['owner_id' => $owner->id]);
        $bytes = "PK\x03\x04fake-apk";
        $artifact = ProjectArtifact::create([
            'project_id' => $project->id,
            'uploader_id' => $owner->id,
            'original_name' => 'app.apk',
            'byte_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'storage_key' => str()->random(40),
            'format' => ProjectArtifact::FORMAT_APK,
            'state' => ProjectArtifact::STATE_READY,
        ]);
        Storage::disk('artifacts')->put($artifact->rawPath(), $bytes);
        Storage::disk('artifacts')->put($artifact->snapshotPath(), json_encode([
            'schema' => '1',
            'detection' => [],
            'detection_warnings' => [],
            'warnings' => [],
            'data' => [],
        ]));

        // No spool here and no snapshots: every sandboxed backend is
        // unavailable, so jadx/dalvik must not show a green supported
        // decompilation cell.
        $response = $this->actingAs($owner)->get(route('projects.artifacts.show', [$project, $artifact]));
        $response->assertOk();
        $response->assertSee('JADX (isolated)', false);
        $response->assertSee('decompilation available in backend, not operational yet', false);
        $response->assertDontSee('decompilation:</dt> <dd class="inline font-semibold text-emerald-700">supported</dd>', false);
    }
}
