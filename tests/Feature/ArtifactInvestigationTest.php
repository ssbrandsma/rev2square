<?php

namespace Tests\Feature;

use App\Enums\PublicationStatus;
use App\Jobs\ExtractArtifactJob;
use App\Models\AgentConversation;
use App\Models\Project;
use App\Models\ProjectArtifact;
use App\Models\User;
use App\Services\Agent\ArtifactTools;
use App\Services\Agent\ToolContext;
use App\Services\Artifacts\ArtifactExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MockAIProvider;
use Tests\TestCase;

class ArtifactInvestigationTest extends TestCase
{
    use RefreshDatabase;

    private function seedProject(): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->draft()->create(['owner_id' => $owner->id]);

        return [$project->fresh(), $owner];
    }

    private function fixtureBytes(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/Artifacts/files/'.$name));
    }

    private function storeArtifact(Project $project, User $uploader, string $fixture, ?string $name = null): ProjectArtifact
    {
        Storage::fake('artifacts');
        $bytes = $this->fixtureBytes($fixture);

        $artifact = ProjectArtifact::create([
            'project_id' => $project->id,
            'uploader_id' => $uploader->id,
            'original_name' => $name ?? $fixture,
            'byte_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'storage_key' => str()->random(40),
            'format' => ArtifactExtractor::detect($bytes),
            'state' => ProjectArtifact::STATE_QUEUED,
        ]);

        Storage::disk('artifacts')->put($artifact->rawPath(), $bytes);
        ArtifactExtractor::extract($artifact->fresh());

        return $artifact->fresh();
    }

    public function test_upload_queues_extraction_and_ready_artifact_shows_summary(): void
    {
        Queue::fake();
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();

        $response = $this->actingAs($owner)->post(route('projects.artifacts.store', $project), [
            'files' => [UploadedFile::fake()->createWithContent('firmware.elf', $this->fixtureBytes('elf64-x86_64.elf'))],
        ]);

        $response->assertRedirect(route('projects.artifacts.index', $project));
        $response->assertSessionHas('status');

        $artifact = ProjectArtifact::where('project_id', $project->id)->first();
        $this->assertNotNull($artifact);
        $this->assertSame(ProjectArtifact::STATE_QUEUED, $artifact->state);
        $this->assertSame(64, strlen($artifact->sha256));
        Queue::assertPushed(ExtractArtifactJob::class);

        // Run the queued extraction synchronously (no worker in tests).
        ArtifactExtractor::extract($artifact->fresh());
        $artifact = $artifact->fresh();
        $this->assertSame(ProjectArtifact::STATE_READY, $artifact->state);
        $this->assertSame('x86-64', $artifact->arch);

        $page = $this->actingAs($owner)->get(route('projects.artifacts.show', [$project, $artifact]));
        $page->assertOk();
        $page->assertSee('firmware.elf');
        $page->assertSee($artifact->sha256);
        $page->assertSee('Ready');
    }

    public function test_unsupported_upload_fails_with_actionable_message(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();

        $response = $this->actingAs($owner)->post(route('projects.artifacts.store', $project), [
            'files' => [UploadedFile::fake()->createWithContent('notes.txt', $this->fixtureBytes('not-elf.bin'))],
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, ProjectArtifact::count());
    }

    public function test_investigate_requires_explicit_consent(): void
    {
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeArtifact($project, $owner, 'elf64-x86_64.elf');
        $this->assertTrue($artifact->isUsable());

        $response = $this->actingAs($owner)->post(route('projects.agent.investigate', $project), [
            'investigation_question' => 'What does this contact?',
            'artifact_ids' => [$artifact->id],
            // no consent checkbox
        ]);

        $response->assertSessionHasErrors('consent');
        $this->assertSame(0, AgentConversation::count());
    }

    public function test_investigate_with_consent_cites_artifact_and_links_viewer(): void
    {
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeArtifact($project, $owner, 'elf64-x86_64.elf');

        MockAIProvider::fake([
            ['__tool_calls' => [['id' => 'c1', 'name' => 'list_artifacts', 'arguments' => []]], 'content' => ''],
            ['__tool_calls' => [['id' => 'c2', 'name' => 'search_elf_strings', 'arguments' => ['artifact_id' => $artifact->id, 'query' => 'updates.example.com']]], 'content' => ''],
            "OBSERVED: firmware references https://updates.example.com/fw [A{$artifact->id}]. INFERRED: likely update endpoint. HYPOTHESIS: contacted at boot. UNKNOWN: whether the code path runs.",
        ]);

        $response = $this->actingAs($owner)->post(route('projects.agent.investigate', $project), [
            'investigation_question' => 'Which external services does this contact?',
            'device_context' => 'Test device, capture during cloud connection failed.',
            'artifact_ids' => [$artifact->id],
            'consent' => '1',
        ]);

        $conversation = AgentConversation::where('project_id', $project->id)->first();
        $this->assertNotNull($conversation);
        $this->assertNotNull($conversation->artifact_consent_at);
        $this->assertSame([$artifact->id], $conversation->artifact_ids);
        $response->assertRedirect(route('projects.agent.show', [$project, $conversation]));

        $assistant = $conversation->messages()->where('role', 'assistant')->first();
        $this->assertSame('complete', $assistant->stopped_reason);
        $this->assertSame([
            ['marker' => "[A{$artifact->id}]", 'artifact_id' => $artifact->id, 'verified' => true],
        ], $assistant->citations);

        // Raw bytes never reach the model: the context block carries only
        // redacted summaries, and the snapshot JSON is not inlined.
        foreach (MockAIProvider::$requests as $request) {
            $wire = json_encode($request->messages);
            $this->assertStringNotContainsString('SYNTHETIC-', $wire);
        }

        $page = $this->actingAs($owner)->get(route('projects.agent.show', [$project, $conversation]));
        $page->assertOk();
        $page->assertSee("[A{$artifact->id}]");
        $page->assertSee('elf64-x86_64.elf');
        $page->assertSee('Test device, capture during cloud connection failed.');
        $page->assertSee(route('projects.artifacts.show', [$project, $artifact], false));
    }

    public function test_followup_reuses_evidence_without_reupload(): void
    {
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeArtifact($project, $owner, 'capture-mixed.pcap');

        MockAIProvider::fake(['First answer.']);
        $this->actingAs($owner)->post(route('projects.agent.investigate', $project), [
            'investigation_question' => 'What DNS was seen?',
            'artifact_ids' => [$artifact->id],
            'consent' => '1',
        ]);
        $conversation = AgentConversation::first();
        $this->assertSame(1, ProjectArtifact::count());

        MockAIProvider::fake([
            ['__tool_calls' => [['id' => 'c1', 'name' => 'search_capture', 'arguments' => ['artifact_id' => $artifact->id, 'query' => 'example.com']]], 'content' => ''],
            "OBSERVED: DNS for example.com [A{$artifact->id}].",
        ]);
        $response = $this->actingAs($owner)->post(route('projects.agent.message', [$project, $conversation]), [
            'question' => 'Any DNS details?',
        ]);
        $response->assertRedirect(route('projects.agent.show', [$project, $conversation]));

        // No new artifact rows, no re-extraction: same snapshot reused.
        $this->assertSame(1, ProjectArtifact::count());
        $assistant = $conversation->messages()->where('role', 'assistant')->reorder()->latest('id')->first();
        $this->assertSame([
            ['marker' => "[A{$artifact->id}]", 'artifact_id' => $artifact->id, 'verified' => true],
        ], $assistant->citations);
    }

    public function test_cross_project_artifact_access_is_denied(): void
    {
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeArtifact($project, $owner, 'elf64-x86_64.elf');

        $other = User::factory()->create();
        $otherProject = Project::factory()->draft()->create(['owner_id' => $other->id]);

        // Foreign artifact id in another project's investigation: excluded.
        MockAIProvider::fake(['Answer.']);
        $response = $this->actingAs($other)->post(route('projects.agent.investigate', $otherProject), [
            'investigation_question' => 'What is this?',
            'artifact_ids' => [$artifact->id],
            'consent' => '1',
        ]);
        $response->assertSessionHas('error');
        $this->assertSame(0, AgentConversation::where('project_id', $otherProject->id)->count());

        // Direct viewer access across projects: 404, no content leak.
        $page = $this->actingAs($other)->get(route('projects.artifacts.show', [$otherProject, $artifact]));
        $page->assertNotFound();
    }

    public function test_malicious_filename_is_escaped(): void
    {
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeArtifact($project, $owner, 'elf64-x86_64.elf', '<script>alert(1)</script>.elf');

        $page = $this->actingAs($owner)->get(route('projects.artifacts.show', [$project, $artifact]));
        $page->assertOk();
        $page->assertDontSee('<script>alert(1)</script>', false);
        $page->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;.elf', false);
    }

    public function test_capture_tools_report_dns_and_flows_with_packet_refs(): void
    {
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeArtifact($project, $owner, 'capture-mixed.pcap');
        $this->assertTrue($artifact->isUsable());

        $context = new ToolContext(
            $owner, $project->fresh(), null, null, [$artifact->id], true
        );

        $dns = ArtifactTools::execute('search_capture', [
            'artifact_id' => $artifact->id, 'query' => 'example.com',
        ], $context);
        $this->assertTrue($dns->ok);
        $this->assertNotEmpty($dns->data['matches']);
        $this->assertSame([$artifact->id], $dns->seenArtifactIds);

        $flows = ArtifactTools::execute('list_capture_flows', [
            'artifact_id' => $artifact->id,
        ], $context);
        $this->assertTrue($flows->ok);
        $this->assertNotEmpty($flows->data['flows']);

        $packet = ArtifactTools::execute('read_packet', [
            'artifact_id' => $artifact->id, 'packet' => 1,
        ], $context);
        $this->assertTrue($packet->ok);
        $this->assertSame(1, $packet->data['packet']['n']);
    }

    public function test_compare_matches_term_across_firmware_and_capture(): void
    {
        [$project, $owner] = $this->seedProject();
        // storeArtifact fakes the disk each call; re-store both on one fake.
        Storage::fake('artifacts');
        $elf = $this->makeArtifact($project, $owner, 'elf64-x86_64.elf');
        $pcap = $this->makeArtifact($project, $owner, 'capture-mixed.pcap');

        $context = new ToolContext(
            $owner, $project->fresh(), null, null, [$elf->id, $pcap->id], true
        );

        $result = ArtifactTools::execute('compare_evidence', [
            'query' => 'example.com',
        ], $context);

        $this->assertTrue($result->ok);
        $this->assertCount(2, $result->data['findings']);
        $this->assertNotEmpty($result->data['findings'][0]['matches']);
        $this->assertNotEmpty($result->data['findings'][1]['matches']);
        $this->assertStringContainsString('correlation, not proof', $result->data['interpretation_rule']);
    }

    public function test_tools_reject_unconsented_and_foreign_artifacts(): void
    {
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeArtifact($project, $owner, 'elf64-x86_64.elf');

        // No consent: refused before any read.
        $noConsent = new ToolContext(
            $owner, $project->fresh(), null, null, [$artifact->id], false
        );
        $refused = ArtifactTools::execute('list_artifacts', [], $noConsent);
        $this->assertFalse($refused->ok);
        $this->assertSame('forbidden', $refused->data['code']);

        // Consent but id outside the conversation allowlist: refused.
        $scoped = new ToolContext(
            $owner, $project->fresh(), null, null, [$artifact->id + 999], true
        );
        $foreign = ArtifactTools::execute('search_elf_strings', [
            'artifact_id' => $artifact->id, 'query' => 'test',
        ], $scoped);
        $this->assertFalse($foreign->ok);
        $this->assertSame('forbidden', $foreign->data['code']);
    }

    public function test_failed_artifact_can_retry_and_question_is_kept(): void
    {
        [$project, $owner] = $this->seedProject();
        Storage::fake('artifacts');
        $bytes = 'too short';

        $artifact = ProjectArtifact::create([
            'project_id' => $project->id,
            'uploader_id' => $owner->id,
            'original_name' => 'broken.pcap',
            'byte_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'storage_key' => str()->random(40),
            'format' => ProjectArtifact::FORMAT_UNKNOWN,
            'state' => ProjectArtifact::STATE_FAILED,
            'failure_code' => 'unsupported_format',
            'failure_message' => 'This file is not a supported ELF binary or PCAP/PCAPNG capture.',
        ]);
        Storage::disk('artifacts')->put($artifact->rawPath(), $bytes);

        MockAIProvider::fake(['Answer.']);
        $response = $this->actingAs($owner)->post(route('projects.agent.investigate', $project), [
            'investigation_question' => 'Keep this question?',
            'artifact_ids' => [$artifact->id],
            'consent' => '1',
        ]);

        $response->assertSessionHas('error');
        $response->assertSessionHasInput('investigation_question', 'Keep this question?');
        $this->assertSame(0, AgentConversation::count());
    }

    public function test_artifacts_excluded_from_public_export(): void
    {
        [$project, $owner] = $this->seedProject();
        $this->storeArtifact($project, $owner, 'elf64-x86_64.elf');

        $project->update(['publication_status' => PublicationStatus::Published]);

        $markdown = $this->actingAs($owner)->get(route('projects.export', [$project, 'md']));
        $markdown->assertOk();
        $markdown->assertDontSee('elf64-x86_64.elf');

        $json = $this->actingAs($owner)->get(route('projects.export', [$project, 'json']));
        $json->assertOk();
        $json->assertDontSee('elf64-x86_64.elf');
    }

    public function test_search_capture_finds_http_path_with_host_and_redacted_target(): void
    {
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeArtifact($project, $owner, 'capture-mixed.pcap');

        $context = new ToolContext($owner, $project->fresh(), null, null, [$artifact->id], true);

        $result = ArtifactTools::execute('search_capture', [
            'artifact_id' => $artifact->id, 'query' => '/api/data',
        ], $context);

        $this->assertTrue($result->ok);
        $this->assertNotEmpty($result->data['matches']);
        $match = $result->data['matches'][0];
        $this->assertSame('http', $match['kind']);
        $this->assertSame('GET', $match['method']);
        $this->assertSame('example.com', $match['host']);
        $this->assertSame(6, $match['packet']);
        $this->assertStringContainsString('/api/data', $match['target']);
        $this->assertStringContainsString('token=[REDACTED]', $match['target']);
        $this->assertStringNotContainsString('SYNTHETIC-URL-TOKEN', json_encode($result->data));
    }

    public function test_http_investigation_flows_from_extractor_to_cited_answer(): void
    {
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeArtifact($project, $owner, 'capture-mixed.pcap');

        MockAIProvider::fake([
            ['__tool_calls' => [['id' => 'c1', 'name' => 'search_capture', 'arguments' => ['artifact_id' => $artifact->id, 'query' => '/api/data']]], 'content' => ''],
            ['__tool_calls' => [['id' => 'c2', 'name' => 'read_packet', 'arguments' => ['artifact_id' => $artifact->id, 'packet' => 6]]], 'content' => ''],
            "OBSERVED: GET /api/data?token=[REDACTED] host example.com in packet 6 [A{$artifact->id}].",
        ]);

        $response = $this->actingAs($owner)->post(route('projects.agent.investigate', $project), [
            'investigation_question' => 'What HTTP activity is visible?',
            'artifact_ids' => [$artifact->id],
            'consent' => '1',
        ]);

        $conversation = AgentConversation::where('project_id', $project->id)->first();
        $this->assertNotNull($conversation);
        $response->assertRedirect(route('projects.agent.show', [$project, $conversation]));

        $assistant = $conversation->messages()->where('role', 'assistant')->first();
        $this->assertSame('complete', $assistant->stopped_reason);
        $this->assertSame([
            ['marker' => "[A{$artifact->id}]", 'artifact_id' => $artifact->id, 'verified' => true],
        ], $assistant->citations);

        // The tool results on the wire carried the HTTP detail; the
        // redacted target survived to the answer while the raw token
        // never appeared in any provider request.
        $wire = (string) json_encode(MockAIProvider::$requests);
        $this->assertStringContainsString('api\\/data', $wire);
        $this->assertStringContainsString('token=[REDACTED]', $wire);
        $this->assertStringNotContainsString('SYNTHETIC-URL-TOKEN', $wire);
    }

    public function test_read_packet_joins_http_request_response_and_tls_detail(): void
    {
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeArtifact($project, $owner, 'capture-mixed.pcap');

        $context = new ToolContext($owner, $project->fresh(), null, null, [$artifact->id], true);

        $get = ArtifactTools::execute('read_packet', [
            'artifact_id' => $artifact->id, 'packet' => 6,
        ], $context);
        $this->assertTrue($get->ok);
        $this->assertSame('GET', $get->data['http']['method'] ?? null);
        $this->assertSame('example.com', $get->data['http']['host'] ?? null);
        $this->assertStringContainsString('/api/data', $get->data['http']['target'] ?? '');

        $ok = ArtifactTools::execute('read_packet', [
            'artifact_id' => $artifact->id, 'packet' => 7,
        ], $context);
        $this->assertTrue($ok->ok);
        $this->assertSame(200, $ok->data['http']['status'] ?? null);

        $tls = ArtifactTools::execute('read_packet', [
            'artifact_id' => $artifact->id, 'packet' => 15,
        ], $context);
        $this->assertTrue($tls->ok);
        $this->assertSame('tls.example.com', $tls->data['tls']['sni'] ?? null);
    }

    public function test_flows_present_unordered_endpoints_with_deterministic_bounds(): void
    {
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeArtifact($project, $owner, 'capture-mixed.pcap');

        $context = new ToolContext($owner, $project->fresh(), null, null, [$artifact->id], true);

        $result = ArtifactTools::execute('list_capture_flows', [
            'artifact_id' => $artifact->id, 'limit' => 8,
        ], $context);

        $this->assertTrue($result->ok);
        $this->assertNotEmpty($result->data['flows']);

        foreach ($result->data['flows'] as $flow) {
            // No directional reading: endpoints are an unordered pair.
            $this->assertArrayHasKey('endpoint_1', $flow);
            $this->assertArrayHasKey('endpoint_2', $flow);
            $this->assertArrayNotHasKey('key', $flow);
            $this->assertSame(1720000002, $result->data['flows'][0]['first_ts'] ?? null);

            break;
        }

        // The completed HTTP flow keeps its deterministic packet/time bounds.
        $http = collect($result->data['flows'])->firstWhere('first_packet', 3);
        $this->assertNotNull($http);
        $this->assertSame(7, $http['last_packet']);
        $this->assertSame(1720000002, $http['first_ts']);
        $this->assertSame(1720000006, $http['last_ts']);
        $this->assertSame('completed_handshake', $http['outcome']);
    }

    public function test_viewer_exposes_packet_http_detail_and_elf_offsets(): void
    {
        [$project, $owner] = $this->seedProject();
        Storage::fake('artifacts');
        $elf = $this->makeArtifact($project, $owner, 'elf64-x86_64.elf');
        $pcap = $this->makeArtifact($project, $owner, 'capture-mixed.pcap');

        $capture = $this->actingAs($owner)->get(route('projects.artifacts.show', [$project, $pcap]));
        $capture->assertOk();
        $capture->assertSee('GET', false);
        $capture->assertSee('/api/data', false);
        $capture->assertSee('example.com', false);
        $capture->assertSee('200', false);
        $capture->assertSee('packet-6', false);
        $capture->assertDontSee('SYNTHETIC-URL-TOKEN', false);

        $firmware = $this->actingAs($owner)->get(route('projects.artifacts.show', [$project, $elf]));
        $firmware->assertOk();
        $firmware->assertSee('0x128', false);
        $firmware->assertSee('updates.example.com', false);
        $firmware->assertSee('file offset', false);
        $firmware->assertDontSee('SYNTHETIC-TEST-KEY-12345', false);
    }

    public function test_upload_reports_new_reused_and_rejected_counts(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();

        $response = $this->actingAs($owner)->post(route('projects.artifacts.store', $project), [
            'files' => [
                UploadedFile::fake()->createWithContent('a.elf', $this->fixtureBytes('elf64-x86_64.elf')),
                UploadedFile::fake()->createWithContent('b.elf', $this->fixtureBytes('elf64-x86_64.elf')),
                UploadedFile::fake()->createWithContent('notes.txt', $this->fixtureBytes('not-elf.bin')),
            ],
        ]);

        $response->assertSessionHas('status');
        $status = session('status');
        $this->assertStringContainsString('1 new', $status);
        $this->assertStringContainsString('1 duplicate', $status);
        $this->assertStringContainsString('1 rejected', $status);
        $this->assertSame(1, ProjectArtifact::count());
    }

    public function test_empty_selection_message_and_scoped_question_fields(): void
    {
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeArtifact($project, $owner, 'elf64-x86_64.elf');

        // Missing consent + empty selection: friendly message, no conversation.
        $response = $this->actingAs($owner)->post(route('projects.agent.investigate', $project), [
            'investigation_question' => 'Keep this scoped question?',
            'consent' => '1',
        ]);

        $response->assertSessionHasErrors('artifact_ids');
        $errors = session('errors');
        $this->assertStringContainsString(
            'Select at least one ready or partial file',
            $errors->get('artifact_ids')[0]
        );
        $this->assertSame(0, AgentConversation::count());

        // The failed investigate form must not leak its text into the
        // repository-question field (distinct input names per form).
        $page = $this->actingAs($owner)
            ->withSession(['_old_input' => ['investigation_question' => 'Keep this scoped question?']])
            ->get(route('projects.agent.index', $project));
        $page->assertOk();
        $page->assertSee('Keep this scoped question?', false);
        $this->assertSame(1, substr_count($page->getContent(), 'Keep this scoped question?'));
        $this->assertTrue($artifact->isUsable());
    }

    private function makeArtifact(Project $project, User $uploader, string $fixture): ProjectArtifact
    {
        $bytes = $this->fixtureBytes($fixture);

        $artifact = ProjectArtifact::create([
            'project_id' => $project->id,
            'uploader_id' => $uploader->id,
            'original_name' => $fixture,
            'byte_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'storage_key' => str()->random(40),
            'format' => ArtifactExtractor::detect($bytes),
            'state' => ProjectArtifact::STATE_QUEUED,
        ]);

        Storage::disk('artifacts')->put($artifact->rawPath(), $bytes);
        ArtifactExtractor::extract($artifact->fresh());

        return $artifact->fresh();
    }
}
