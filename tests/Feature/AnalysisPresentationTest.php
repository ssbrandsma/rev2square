<?php

namespace Tests\Feature;

use App\Enums\PublicationStatus;
use App\Models\AgentConversation;
use App\Models\AgentMessage;
use App\Models\BinaryAnalysis;
use App\Models\Project;
use App\Models\ProjectArtifact;
use App\Models\Repository;
use App\Models\User;
use App\Services\Admin\AppSettings;
use App\Services\Artifacts\ArtifactExtractor;
use App\Support\WarningLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BinaryFixtures;
use Tests\TestCase;

/**
 * Results-before-internals presentation for artifact, analysis,
 * and agent pages: translated warnings with diagnostic codes,
 * status variants, partial coverage, and scoped empty states.
 */
class AnalysisPresentationTest extends TestCase
{
    use RefreshDatabase;

    private function seedProject(): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->draft()->create(['owner_id' => $owner->id]);

        return [$project->fresh(), $owner];
    }

    private function storeBytes(Project $project, User $uploader, string $bytes, string $name): ProjectArtifact
    {
        $artifact = ProjectArtifact::create([
            'project_id' => $project->id,
            'uploader_id' => $uploader->id,
            'original_name' => $name,
            'byte_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'storage_key' => str()->random(40),
            'format' => ArtifactExtractor::detect($bytes, $name),
            'state' => ProjectArtifact::STATE_QUEUED,
        ]);
        Storage::disk('artifacts')->put($artifact->rawPath(), $bytes);
        ArtifactExtractor::extract($artifact->fresh());

        return $artifact->fresh();
    }

    private function putEvidence(ProjectArtifact $artifact, array $evidence): string
    {
        $key = str()->random(40);
        Storage::disk('artifacts')->put($artifact->project_id.'/'.$key.'.analysis.json', json_encode($evidence));

        return $key;
    }

    private function seedRepository(Project $project, string $status): Repository
    {
        return Repository::factory()->create([
            'project_id' => $project->id,
            'owner' => 'acme',
            'name' => 'widgets',
            'canonical_url' => 'https://github.com/acme/widgets',
            'import_status' => $status,
            'last_commit_sha' => str_repeat('a', 40),
        ]);
    }

    public function test_warning_labels_translate_known_and_unknown_codes(): void
    {
        $this->assertSame(
            'The section table was cut off; some sections are missing.',
            WarningLabels::label('truncated_sections')
        );
        $this->assertSame(
            'Member lib/foo.so is encrypted and was skipped.',
            WarningLabels::label('encrypted_member:lib/foo.so')
        );
        $this->assertSame(
            'Section 3 points outside the file and was skipped.',
            WarningLabels::label('section_out_of_bounds:3')
        );
        // Unknown codes humanize instead of rendering raw.
        $this->assertSame('Future code.', WarningLabels::label('future_code'));
        // Sentence-style warnings pass through untouched.
        $sentence = 'Submodules are not expanded; their contents were not imported: vendor/lib';
        $this->assertSame($sentence, WarningLabels::label($sentence));

        $this->assertSame(['code' => 'so_unreadable', 'detail' => 'lib/a.so'], WarningLabels::split('so_unreadable:lib/a.so'));
        $this->assertSame(['code' => 'xrefs_capped', 'detail' => null], WarningLabels::split('xrefs_capped'));

        $this->assertSame('The analysis backend timed out.', WarningLabels::errorLabel('backend_timeout'));
        $this->assertNull(WarningLabels::errorLabel('no_such_code'));
        $this->assertNull(WarningLabels::errorLabel(null));
    }

    public function test_artifact_page_translates_warnings_and_collapses_provenance(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::prgBasic(), 'game.prg');
        $artifact->forceFill(['warnings' => ['truncated_sections', 'section_out_of_bounds:3']])->save();

        $page = $this->actingAs($owner)->get(route('projects.artifacts.show', [$project, $artifact]));

        $page->assertOk();
        $page->assertSee('Extraction warnings (2)', false);
        $page->assertSee('The section table was cut off; some sections are missing.', false);
        $page->assertSee('Section 3 points outside the file and was skipped.', false);
        $page->assertSee('Diagnostic code', false);
        $page->assertSee('section_out_of_bounds:3', false);
        // Provenance facts survive inside the collapsed envelope.
        $page->assertSee('Technical details', false);
        $page->assertSee($artifact->sha256, false);
        $page->assertSee('Copy SHA-256', false);
        $page->assertSee($artifact->stateLabel(), false);
    }

    public function test_run_page_shows_partial_coverage_and_error_diagnostics(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::prgBasic(), 'game.prg');
        $partial = BinaryAnalysis::create([
            'project_artifact_id' => $artifact->id,
            'requested_by' => $owner->id,
            'adapter' => 'jadx',
            'adapter_version' => 'test',
            'target' => 'dalvik',
            'config' => ['target' => 'dalvik'],
            'status' => BinaryAnalysis::STATUS_PARTIAL,
            'dedupe_key' => 'test-'.str()->random(32),
            'evidence_key' => $this->putEvidence($artifact, [
                'schema' => 'binary-analysis/1',
                'warnings' => ['reconstruction_capped'],
                'segments' => [],
                'functions' => [],
                'xrefs' => [],
                'disassembly' => ['windows' => []],
                'reconstruction' => [
                    'files' => [['path' => 'sources/com/Foo.java', 'size' => 9]],
                    'inline' => [],
                    'truncated' => true,
                    'budget' => ['files_seen' => 4327, 'manifest_retained' => 2000],
                ],
            ]),
        ]);

        $page = $this->actingAs($owner)->get(route('projects.artifacts.analyses.show', [$project, $artifact, $partial]));

        $page->assertOk();
        $page->assertSee('Partial', false);
        $page->assertSee('Partial coverage: what was capped', false);
        $page->assertSee('2,000 of 4,327 reconstructed files indexed.', false);
        $page->assertSee('Reconstruction hit the output budget; some files are listed without content.', false);
        $page->assertSee('Technical details', false);
        $page->assertSee('dalvik', false);

        $failed = BinaryAnalysis::create([
            'project_artifact_id' => $artifact->id,
            'requested_by' => $owner->id,
            'adapter' => 'objdump',
            'adapter_version' => 'test',
            'target' => 'arm32',
            'config' => [],
            'status' => BinaryAnalysis::STATUS_FAILED,
            'error_code' => 'backend_timeout',
            'error_message' => 'The worker went quiet mid-run.',
            'dedupe_key' => 'test-'.str()->random(32),
        ]);

        $failedPage = $this->actingAs($owner)->get(route('projects.artifacts.analyses.show', [$project, $artifact, $failed]));

        $failedPage->assertOk();
        $failedPage->assertSee('Failed', false);
        $failedPage->assertSee('The worker went quiet mid-run.', false);
        $failedPage->assertSee('Diagnostic code', false);
        $failedPage->assertSee('backend_timeout', false);

        $failed->forceFill(['error_message' => null])->save();
        $codeless = $this->actingAs($owner)->get(route('projects.artifacts.analyses.show', [$project, $artifact, $failed]));
        $codeless->assertOk();
        $codeless->assertSee('The analysis backend timed out.', false);
    }

    public function test_jadx_run_hides_empty_memory_map_but_explains_functions(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::prgBasic(), 'game.prg');
        $evidence = [
            'schema' => 'binary-analysis/1',
            'warnings' => [],
            'segments' => [],
            'functions' => [],
            'xrefs' => [],
            'disassembly' => ['windows' => []],
            'reconstruction' => ['files' => [], 'inline' => [], 'truncated' => false],
        ];
        $jadx = BinaryAnalysis::create([
            'project_artifact_id' => $artifact->id,
            'requested_by' => $owner->id,
            'adapter' => 'jadx',
            'adapter_version' => 'test',
            'target' => 'dalvik',
            'config' => [],
            'status' => BinaryAnalysis::STATUS_READY,
            'dedupe_key' => 'test-'.str()->random(32),
            'evidence_key' => $this->putEvidence($artifact, $evidence),
        ]);

        $page = $this->actingAs($owner)->get(route('projects.artifacts.analyses.show', [$project, $artifact, $jadx]));

        $page->assertOk();
        $page->assertDontSee('Memory map', false);
        $page->assertSee('No function table: JADX reconstructs Java sources', false);

        $native = BinaryAnalysis::create([
            'project_artifact_id' => $artifact->id,
            'requested_by' => $owner->id,
            'adapter' => 'php-native',
            'adapter_version' => 'test',
            'target' => 'mos6502',
            'config' => [],
            'status' => BinaryAnalysis::STATUS_READY,
            'dedupe_key' => 'test-'.str()->random(32),
            'evidence_key' => $this->putEvidence($artifact, $evidence),
        ]);

        $nativePage = $this->actingAs($owner)->get(route('projects.artifacts.analyses.show', [$project, $artifact, $native]));

        $nativePage->assertOk();
        $nativePage->assertSee('Memory map (0 segments)', false);
    }

    public function test_file_lists_state_coverage_and_reuse_the_source_browser(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::prgBasic(), 'game.prg');
        $analysis = BinaryAnalysis::create([
            'project_artifact_id' => $artifact->id,
            'requested_by' => $owner->id,
            'adapter' => 'jadx',
            'adapter_version' => 'test',
            'target' => 'dalvik',
            'config' => [],
            'status' => BinaryAnalysis::STATUS_READY,
            'dedupe_key' => 'test-'.str()->random(32),
            'evidence_key' => $this->putEvidence($artifact, [
                'schema' => 'binary-analysis/1',
                'warnings' => [],
                'segments' => [],
                'functions' => [],
                'xrefs' => [],
                'disassembly' => ['windows' => []],
                'reconstruction' => [
                    'files' => [
                        ['path' => 'sources/com/A.java', 'size' => 9],
                        ['path' => 'sources/com/B.java', 'size' => 9],
                        ['path' => 'sources/com/C.java', 'size' => 9],
                    ],
                    'inline' => [],
                    'truncated' => false,
                ],
                'extraction' => [
                    'files' => [['path' => 'res/values.xml', 'size' => 4]],
                    'profile' => 'test',
                    'truncated' => false,
                ],
            ]),
        ]);

        $page = $this->actingAs($owner)->get(route('projects.artifacts.analyses.show', [$project, $artifact, $analysis]));

        $page->assertOk();
        $page->assertSee('Showing 3 of 3 indexed files inline.', false);
        $page->assertSee('Showing 1 of 1 indexed files inline.', false);
        $page->assertSee('Browse all indexed files with search', false);
        $page->assertSee(route('projects.artifacts.analyses.source', [$project, $artifact, $analysis], false), false);
    }

    public function test_runs_list_highlights_latest_usable_result_and_keeps_failures(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::prgBasic(), 'game.prg');
        $usable = BinaryAnalysis::create([
            'project_artifact_id' => $artifact->id,
            'requested_by' => $owner->id,
            'adapter' => 'jadx',
            'adapter_version' => 'test',
            'target' => 'dalvik',
            'config' => [],
            'status' => BinaryAnalysis::STATUS_READY,
            'dedupe_key' => 'test-'.str()->random(32),
        ]);
        BinaryAnalysis::create([
            'project_artifact_id' => $artifact->id,
            'requested_by' => $owner->id,
            'adapter' => 'objdump',
            'adapter_version' => 'test',
            'target' => 'arm32',
            'config' => [],
            'status' => BinaryAnalysis::STATUS_FAILED,
            'error_code' => 'backend_crash',
            'error_message' => 'The backend crashed.',
            'dedupe_key' => 'test-'.str()->random(32),
        ]);

        $page = $this->actingAs($owner)->get(route('projects.artifacts.show', [$project, $artifact]));

        $page->assertOk();
        $page->assertSee('Latest usable result:', false);
        $page->assertSee(route('projects.artifacts.analyses.show', [$project, $artifact, $usable], false), false);
        // Failed history is retained, with its diagnostic code.
        $page->assertSee('The backend crashed.', false);
        $page->assertSee('backend_crash', false);
    }

    public function test_agent_index_scopes_empty_chat_message(): void
    {
        [$project, $owner] = $this->seedProject();

        // Maintainer, no repository, uploads enabled: both paths named.
        $page = $this->actingAs($owner)->get(route('projects.agent.index', $project));
        $page->assertOk();
        $page->assertSee('Import repository evidence to enable chat', false);
        $page->assertSee('Or investigate uploaded files below.', false);
        $page->assertSee(route('projects.evidence', $project, false), false);
        // Consent stays explicit with its key facts.
        $page->assertSee('Send bounded, redacted evidence', false);
        $page->assertSee('raw files are never sent in full', false);
        $page->assertSee('name="consent"', false);

        // Maintainer, uploads disabled: no investigation hint.
        AppSettings::set('artifacts.enabled', false, $owner->id);
        $locked = $this->actingAs($owner)->get(route('projects.agent.index', $project));
        $locked->assertOk();
        $locked->assertSee('Import repository evidence to enable chat', false);
        $locked->assertDontSee('investigate uploaded files below', false);
        AppSettings::set('artifacts.enabled', true, $owner->id);

        // Import states are named accurately.
        $this->seedRepository($project, 'importing');
        $importing = $this->actingAs($owner)->get(route('projects.agent.index', $project));
        $importing->assertSee('Repository import is still running', false);

        Repository::where('project_id', $project->id)->update(['import_status' => 'failed']);
        $failed = $this->actingAs($owner)->get(route('projects.agent.index', $project));
        $failed->assertSee('Repository import failed', false);

        // Plain viewer on a public project: no maintainer wording.
        Repository::where('project_id', $project->id)->delete();
        $project->update(['publication_status' => PublicationStatus::Published]);
        $stranger = User::factory()->create();
        $viewer = $this->actingAs($stranger)->get(route('projects.agent.index', $project));
        $viewer->assertOk();
        $viewer->assertSee('No repository evidence to chat about yet.', false);
        $viewer->assertSee('investigate uploaded files below', false);
    }

    public function test_conversation_prioritizes_answers_with_compact_diagnostics(): void
    {
        [$project, $owner] = $this->seedProject();
        $conversation = AgentConversation::create([
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'title' => 'Pairing question',
        ]);
        AgentMessage::create([
            'agent_conversation_id' => $conversation->id,
            'role' => AgentMessage::ROLE_USER,
            'content' => 'How does pairing work?',
        ]);
        AgentMessage::create([
            'agent_conversation_id' => $conversation->id,
            'role' => AgentMessage::ROLE_ASSISTANT,
            'content' => 'Pairing lives in the radio module.',
            'token_usage' => ['input' => 100, 'output' => 50],
            'tool_trace' => [['name' => 'search_evidence', 'args' => 'pairing', 'ms' => 12, 'ok' => true]],
        ]);

        $page = $this->actingAs($owner)->get(route('projects.agent.show', [$project, $conversation]));
        $html = $page->assertOk()->getContent();

        $this->assertNotFalse($html);
        // The answer renders before the collapsed diagnostics.
        $answerAt = strpos($html, 'Pairing lives in the radio module.');
        $diagnosticsAt = strpos($html, 'Diagnostics');
        $this->assertIsInt($answerAt);
        $this->assertIsInt($diagnosticsAt);
        $this->assertLessThan($diagnosticsAt, $answerAt);
        $page->assertSee('100 in / 50 out tokens', false);
        $page->assertSee('Investigation trace (1 lookups)', false);
    }
}
