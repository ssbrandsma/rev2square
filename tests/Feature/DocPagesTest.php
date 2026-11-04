<?php

namespace Tests\Feature;

use App\Enums\DocCategory;
use App\Enums\DocFindingStatus;
use App\Enums\PublicationStatus;
use App\Models\AuditEvent;
use App\Models\DocFinding;
use App\Models\DocFindingEvent;
use App\Models\DocPage;
use App\Models\DocRevision;
use App\Models\Project;
use App\Models\ProjectMedia;
use App\Models\User;
use App\Services\Agent\DocsTools;
use App\Services\Agent\ToolContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class DocPagesTest extends TestCase
{
    use RefreshDatabase;

    private function publishedProject(?User $owner = null): Project
    {
        $owner ??= User::factory()->create();

        return Project::factory()->create([
            'owner_id' => $owner->id,
            'publication_status' => PublicationStatus::Published,
        ]);
    }

    /** @return array{Project, User} */
    private function seedProject(): array
    {
        $owner = User::factory()->create();

        return [$this->publishedProject($owner), $owner];
    }

    private function docPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Mainboard overview',
            'summary' => 'What lives on the mainboard.',
            'category' => DocCategory::CircuitBoards->value,
            'publication_status' => PublicationStatus::Published->value,
            'sort_order' => 0,
            'doc_document' => json_encode(['version' => 1, 'blocks' => [
                ['type' => 'heading', 'level' => 2, 'text' => 'Power rails'],
                ['type' => 'paragraph', 'text' => 'The 3V3 rail feeds the SoC.'],
            ]]),
        ], $overrides);
    }

    public function test_nav_tab_links_to_docs_and_stays_active(): void
    {
        [$project, $owner] = $this->seedProject();
        $page = DocPage::factory()->published()->create(['project_id' => $project->id]);

        $index = $this->get(route('projects.docs.index', $project));
        $index->assertOk();
        $index->assertSee('Documentation', false);
        $index->assertSee(route('projects.docs.index', $project), false);

        $show = $this->get(route('projects.docs.show', [$project, $page]));
        $show->assertOk();
        $show->assertSee('Documentation', false);
    }

    public function test_owner_can_create_draft_and_it_starts_at_revision_one(): void
    {
        [$project, $owner] = $this->seedProject();

        $this->actingAs($owner)
            ->post(route('projects.docs.store', $project), $this->docPayload([
                'publication_status' => PublicationStatus::Draft->value,
            ]))
            ->assertRedirect();

        $page = DocPage::where('project_id', $project->id)->firstOrFail();
        $this->assertSame(1, $page->current_rev);
        $this->assertFalse($page->isPublished());
        $this->assertSame(1, DocRevision::where('doc_page_id', $page->id)->count());
        $this->assertTrue(AuditEvent::where('project_id', $project->id)->where('event', 'doc.created')->exists());
        $this->assertFalse(AuditEvent::where('project_id', $project->id)->where('event', 'doc.published')->exists());
    }

    public function test_guest_sees_published_but_not_draft_or_private_project_docs(): void
    {
        [$project] = $this->seedProject();
        $published = DocPage::factory()->published()->create(['project_id' => $project->id]);
        $draft = DocPage::factory()->create(['project_id' => $project->id]);

        $this->get(route('projects.docs.show', [$project, $published]))->assertOk();
        $this->get(route('projects.docs.show', [$project, $draft]))->assertNotFound();

        $index = $this->get(route('projects.docs.index', $project));
        $index->assertOk();
        $index->assertSee($published->title);
        $index->assertDontSee($draft->title);

        $private = Project::factory()->draft()->create();
        DocPage::factory()->published()->create(['project_id' => $private->id]);
        $this->get(route('projects.docs.index', $private))->assertForbidden();
    }

    public function test_member_sees_published_but_cannot_write_or_see_drafts(): void
    {
        [$project] = $this->seedProject();
        $member = User::factory()->create();
        $published = DocPage::factory()->published()->create(['project_id' => $project->id]);
        $draft = DocPage::factory()->create(['project_id' => $project->id]);

        $this->actingAs($member)->get(route('projects.docs.show', [$project, $published]))->assertOk();
        $this->actingAs($member)->get(route('projects.docs.show', [$project, $draft]))->assertNotFound();
        $this->actingAs($member)->get(route('projects.docs.create', $project))->assertForbidden();
        $this->actingAs($member)->post(route('projects.docs.store', $project), $this->docPayload())->assertForbidden();
        $this->actingAs($member)->get(route('projects.docs.edit', [$project, $published]))->assertForbidden();
    }

    public function test_cross_project_page_access_is_blocked(): void
    {
        [$project] = $this->seedProject();
        [$other, $otherOwner] = $this->seedProject();
        $page = DocPage::factory()->published()->create(['project_id' => $other->id]);

        // Same id under a different project slug: 404, never leaked.
        $this->actingAs($otherOwner)->get(route('projects.docs.show', [$project, $page->id]))->assertNotFound();
    }

    public function test_invalid_document_is_rejected_with_input_preserved(): void
    {
        [$project, $owner] = $this->seedProject();

        $this->actingAs($owner)
            ->post(route('projects.docs.store', $project), $this->docPayload([
                'doc_document' => json_encode(['version' => 1, 'blocks' => [['type' => 'table']]]),
            ]))
            ->assertSessionHasErrors('doc_document')
            ->assertSessionHasInput('title', 'Mainboard overview');

        $this->assertSame(0, DocPage::where('project_id', $project->id)->count());
    }

    public function test_cross_project_and_self_links_are_rejected(): void
    {
        [$project, $owner] = $this->seedProject();
        $other = Project::factory()->create();
        $foreign = DocPage::factory()->create(['project_id' => $other->id]);

        $this->actingAs($owner)
            ->post(route('projects.docs.store', $project), $this->docPayload([
                'doc_document' => json_encode(['version' => 1, 'blocks' => [
                    ['type' => 'doc_link', 'doc_id' => $foreign->id],
                ]]),
            ]))
            ->assertSessionHasErrors('doc_document');

        $page = DocPage::factory()->create(['project_id' => $project->id]);
        $this->actingAs($owner)
            ->patch(route('projects.docs.update', [$project, $page]), $this->docPayload([
                'doc_version' => 1,
                'doc_document' => json_encode(['version' => 1, 'blocks' => [
                    ['type' => 'doc_link', 'doc_id' => $page->id],
                ]]),
            ]))
            ->assertSessionHasErrors('doc_document');
    }

    public function test_stale_version_is_refused_but_unchanged_save_skips(): void
    {
        [$project, $owner] = $this->seedProject();
        $page = DocPage::factory()->create(['project_id' => $project->id, 'current_rev' => 2]);

        // Stale writer with real changes: refused, draft preserved.
        $this->actingAs($owner)
            ->patch(route('projects.docs.update', [$project, $page]), $this->docPayload([
                'doc_version' => 1,
                'title' => 'Stale title',
            ]))
            ->assertSessionHasErrors('doc_version')
            ->assertSessionHasInput('title', 'Stale title');
        $this->assertSame(2, $page->fresh()->current_rev);

        // Unchanged re-save with a stale version: harmless, no new rev.
        $same = DocPage::factory()->create([
            'project_id' => $project->id,
            'title' => 'Same',
            'current_rev' => 3,
            'body' => ['version' => 1, 'blocks' => [['type' => 'paragraph', 'text' => 'Same body.']]],
        ]);
        $this->actingAs($owner)
            ->patch(route('projects.docs.update', [$project, $same]), [
                'title' => 'Same',
                'summary' => $same->summary,
                'category' => $same->category->value,
                'publication_status' => $same->publication_status->value,
                'sort_order' => 0,
                'doc_version' => 1,
                'doc_document' => json_encode($same->body),
            ])
            ->assertRedirect(route('projects.docs.show', [$project, $same]));
        $this->assertSame(3, $same->fresh()->current_rev);
    }

    public function test_revisions_pin_old_bodies_and_restore_appends(): void
    {
        [$project, $owner] = $this->seedProject();
        $page = DocPage::factory()->published()->create([
            'project_id' => $project->id,
            'title' => 'First title',
            'current_rev' => 1,
            'body' => ['version' => 1, 'blocks' => [['type' => 'paragraph', 'text' => 'First body.']]],
        ]);

        $this->actingAs($owner)
            ->patch(route('projects.docs.update', [$project, $page]), $this->docPayload([
                'title' => 'Second title',
                'doc_version' => 1,
                'rev_note' => 'Rewrote intro',
            ]))
            ->assertRedirect();

        $this->assertSame(2, $page->fresh()->current_rev);
        $this->assertSame(2, DocRevision::where('doc_page_id', $page->id)->count());

        // Pinned old revision renders old body with a stale banner.
        $pinned = $this->get(route('projects.docs.show', [$project, $page, 'rev' => 1]));
        $pinned->assertOk();
        $pinned->assertSee('First body.', false);
        $pinned->assertDontSee('The 3V3 rail feeds the SoC.', false);
        $pinned->assertSee('newer revision (2) is available', false);

        // Restore appends rev 3; rev 1 still pins the original.
        $this->actingAs($owner)
            ->post(route('projects.docs.revisions.restore', [$project, $page, 1]))
            ->assertRedirect();
        $this->assertSame(3, $page->fresh()->current_rev);
        $this->assertSame('First title', $page->fresh()->title);
        $this->assertSame('First body.', DocRevision::where('doc_page_id', $page->id)->where('rev', 1)->firstOrFail()->body['blocks'][0]['text']);
    }

    public function test_publish_and_unpublish_write_revisions_and_audit(): void
    {
        [$project, $owner] = $this->seedProject();
        $page = DocPage::factory()->create(['project_id' => $project->id]);

        $this->actingAs($owner)->post(route('projects.docs.publish', [$project, $page]))->assertRedirect();
        $this->assertTrue($page->fresh()->isPublished());
        $this->assertSame(2, $page->fresh()->current_rev);
        $this->assertTrue(AuditEvent::where('project_id', $project->id)->where('event', 'doc.published')->exists());

        $this->actingAs($owner)->post(route('projects.docs.unpublish', [$project, $page]))->assertRedirect();
        $this->assertFalse($page->fresh()->isPublished());
        $this->assertSame(3, $page->fresh()->current_rev);
    }

    public function test_delete_hides_from_readers_but_keeps_tombstone_for_managers(): void
    {
        [$project, $owner] = $this->seedProject();
        $member = User::factory()->create();
        $page = DocPage::factory()->published()->create(['project_id' => $project->id]);

        $this->actingAs($owner)->delete(route('projects.docs.destroy', [$project, $page]))->assertRedirect();

        $this->assertSoftDeleted($page);
        $this->actingAs($member)->get(route('projects.docs.show', [$project, $page]))->assertNotFound();
        $this->get(route('projects.docs.show', [$project, $page]))->assertNotFound();

        $tombstone = $this->actingAs($owner)->get(route('projects.docs.show', [$project, $page]));
        $tombstone->assertOk();
        $tombstone->assertSee('was deleted', false);

        $this->actingAs($owner)->post(route('projects.docs.restore', [$project, $page]))->assertRedirect();
        $this->assertFalse($page->fresh()->trashed());
        $this->get(route('projects.docs.show', [$project, $page]))->assertOk();
    }

    public function test_move_reorders_within_category(): void
    {
        [$project, $owner] = $this->seedProject();
        $first = DocPage::factory()->published()->create(['project_id' => $project->id, 'title' => 'AAA first', 'sort_order' => 0]);
        $second = DocPage::factory()->published()->create(['project_id' => $project->id, 'title' => 'ZZZ second', 'sort_order' => 1]);

        $this->actingAs($owner)
            ->post(route('projects.docs.move', [$project, $second]), ['direction' => 'up'])
            ->assertRedirect();

        $this->assertTrue($second->fresh()->sort_order < $first->fresh()->sort_order);
        $this->get(route('projects.docs.index', $project))->assertSeeInOrder(['ZZZ second', 'AAA first'], false);
        // Ordering never writes a revision.
        $this->assertSame(1, $second->fresh()->current_rev);
    }

    public function test_findings_require_evidence_for_verified_and_audit_status(): void
    {
        [$project, $owner] = $this->seedProject();
        $page = DocPage::factory()->published()->create(['project_id' => $project->id]);

        // Verified without notes or refs: refused.
        $this->actingAs($owner)
            ->post(route('projects.docs.findings.store', [$project, $page]), [
                'statement' => 'U1 is the SoC.',
                'status' => DocFindingStatus::Verified->value,
            ])
            ->assertSessionHasErrors('status', null, 'finding_create');
        $this->assertSame(0, DocFinding::count());

        // Observed records with author + rev pin.
        $this->actingAs($owner)
            ->post(route('projects.docs.findings.store', [$project, $page]), [
                'statement' => 'U1 is the SoC.',
                'status' => DocFindingStatus::Observed->value,
            ])
            ->assertRedirect();
        $finding = DocFinding::firstOrFail();
        $this->assertSame($page->fresh()->current_rev, $finding->rev);
        $this->assertSame($owner->id, $finding->status_changed_by_id);

        // Status change requires a reason and records who/why.
        $this->actingAs($owner)
            ->patch(route('projects.docs.findings.status', [$project, $page, $finding]), [
                'status' => DocFindingStatus::Verified->value,
                'reason' => 'Probed pin 1: 3V3 present.',
                'measurement_notes' => '3.28V at TP1.',
            ])
            ->assertRedirect();
        $finding->refresh();
        $this->assertTrue($finding->status === DocFindingStatus::Verified);
        $this->assertSame('Probed pin 1: 3V3 present.', $finding->status_change_reason);
        $this->assertTrue(AuditEvent::where('project_id', $project->id)->where('event', 'doc.finding_status')->exists());

        $show = $this->get(route('projects.docs.show', [$project, $page]));
        $show->assertSee('Verified', false);
        $show->assertSee('Probed pin 1: 3V3 present.', false);
    }

    public function test_finding_refs_validate_scope_and_render_as_links(): void
    {
        [$project, $owner] = $this->seedProject();
        $other = Project::factory()->create();
        $foreign = DocPage::factory()->create(['project_id' => $other->id]);
        $page = DocPage::factory()->published()->create(['project_id' => $project->id]);
        $target = DocPage::factory()->published()->create(['project_id' => $project->id, 'title' => 'Linked guide']);

        // Cross-project references are rejected.
        $this->actingAs($owner)
            ->post(route('projects.docs.findings.store', [$project, $page]), [
                'statement' => 'See elsewhere.',
                'status' => DocFindingStatus::Observed->value,
                'refs' => [['kind' => 'page', 'doc_id' => $foreign->id]],
            ])
            ->assertSessionHasErrors('refs', null, 'finding_create');
        $this->assertSame(0, DocFinding::count());

        // Same-project references store and render as links.
        $this->actingAs($owner)
            ->post(route('projects.docs.findings.store', [$project, $page]), [
                'statement' => 'See the linked guide.',
                'status' => DocFindingStatus::Observed->value,
                'refs' => [
                    ['kind' => 'page', 'doc_id' => $target->id, 'anchor' => 'step-1'],
                    ['kind' => 'url', 'url' => 'https://example.com/datasheet'],
                ],
            ])
            ->assertRedirect();
        $this->assertSame(1, DocFinding::count());

        $show = $this->get(route('projects.docs.show', [$project, $page]));
        $show->assertSee('Linked guide', false);
        $show->assertSee(route('projects.docs.show', [$project, $target]).'#step-1', false);
        $show->assertSee('https://example.com/datasheet', false);
    }

    public function test_search_scopes_to_readable_pages_and_escapes_wildcards(): void
    {
        [$project] = $this->seedProject();
        $member = User::factory()->create();
        DocPage::factory()->published()->create([
            'project_id' => $project->id, 'title' => 'Capacitor map', 'search_text' => 'Capacitor map',
        ]);
        DocPage::factory()->create([
            'project_id' => $project->id, 'title' => 'Secret draft notes', 'search_text' => 'Secret draft notes',
        ]);

        $hits = $this->actingAs($member)->get(route('projects.docs.index', [$project, 'q' => 'Capacitor']));
        $hits->assertSee('Capacitor map', false);
        $hits->assertDontSee('Secret draft notes', false);

        // A literal % must not widen the match.
        $wild = $this->actingAs($member)->get(route('projects.docs.index', [$project, 'q' => '%']));
        $wild->assertDontSee('Capacitor map', false);
        $wild->assertDontSee('Secret draft notes', false);
    }

    public function test_per_doc_export_carries_markdown_but_no_bytes_or_paths(): void
    {
        [$project] = $this->seedProject();
        $member = User::factory()->create();
        $page = DocPage::factory()->published()->create([
            'project_id' => $project->id,
            'body' => ['version' => 1, 'blocks' => [['type' => 'paragraph', 'text' => 'Export me.']]],
        ]);
        DocFinding::create([
            'doc_page_id' => $page->id, 'rev' => 1, 'status' => DocFindingStatus::Observed->value,
            'statement' => 'Found it.',
        ]);

        $json = $this->actingAs($member)->get(route('projects.docs.export', [$project, $page, 'json']));
        $json->assertOk();
        $json->assertJsonPath('title', $page->title);
        $json->assertJsonPath('findings.0.statement', 'Found it.');
        $this->assertStringNotContainsString('storage_key', $json->getContent());
        $this->assertStringNotContainsString('media/', $json->getContent());

        $md = $this->actingAs($member)->get(route('projects.docs.export', [$project, $page, 'md']));
        $md->assertOk();
        $md->assertSee('Export me.', false);
        $md->assertSee('Found it.', false);

        $this->actingAs($member)->get(route('projects.docs.export', [$project, $page, 'pdf']))->assertNotFound();

        $draft = DocPage::factory()->create(['project_id' => $project->id]);
        $this->actingAs($member)->get(route('projects.docs.export', [$project, $draft, 'json']))->assertNotFound();
    }

    public function test_project_export_includes_readable_docs_only(): void
    {
        [$project] = $this->seedProject();
        $member = User::factory()->create();
        DocPage::factory()->published()->create([
            'project_id' => $project->id,
            'title' => 'Exported guide',
            'body' => ['version' => 1, 'blocks' => [['type' => 'paragraph', 'text' => 'Guide words.']]],
        ]);
        DocPage::factory()->create(['project_id' => $project->id, 'title' => 'Draft guide']);

        $json = $this->actingAs($member)->get(route('projects.export', [$project, 'json']));
        $json->assertOk();
        $json->assertJsonPath('docs.0.title', 'Exported guide');
        $json->assertJsonCount(1, 'docs');
        $this->assertStringNotContainsString('storage_key', $json->getContent());

        $md = $this->actingAs($member)->get(route('projects.export', [$project, 'md']));
        $md->assertOk();
        $md->assertSee('Exported guide', false);
        $md->assertSee('Guide words.', false);
        $md->assertDontSee('Draft guide', false);
    }

    public function test_historical_draft_revisions_stay_hidden_after_publish(): void
    {
        [$project, $owner] = $this->seedProject();
        $member = User::factory()->create();
        $page = DocPage::factory()->create([
            'project_id' => $project->id,
            'title' => 'Guide',
            'body' => ['version' => 1, 'blocks' => [['type' => 'paragraph', 'text' => 'Sensitive draft notes.']]],
        ]);

        // Clean the page and publish: rev 2 is public, rev 1 stays private.
        $this->actingAs($owner)
            ->patch(route('projects.docs.update', [$project, $page]), $this->docPayload([
                'publication_status' => PublicationStatus::Published->value,
                'doc_version' => 1,
            ]))
            ->assertRedirect();

        $this->actingAs($member)->get(route('projects.docs.show', [$project, $page, 'rev' => 1]))->assertNotFound();
        $this->actingAs($member)->get(route('projects.docs.show', [$project, $page, 'rev' => 2]))->assertOk();
        $this->get(route('projects.docs.show', [$project, $page, 'rev' => 1]))->assertNotFound();

        $history = $this->actingAs($member)->get(route('projects.docs.revisions', [$project, $page]));
        $history->assertOk();
        $history->assertDontSee('Sensitive draft notes', false);
        $history->assertSee('Revision 2', false);

        $this->actingAs($owner)->get(route('projects.docs.show', [$project, $page, 'rev' => 1]))->assertOk();
    }

    public function test_unpublish_edit_republish_history_shows_only_published_to_readers(): void
    {
        [$project, $owner] = $this->seedProject();
        $member = User::factory()->create();
        $page = DocPage::factory()->published()->create(['project_id' => $project->id]);

        $this->actingAs($owner)->post(route('projects.docs.unpublish', [$project, $page]))->assertRedirect();
        $this->actingAs($owner)
            ->patch(route('projects.docs.update', [$project, $page]), $this->docPayload([
                'publication_status' => PublicationStatus::Draft->value,
                'doc_version' => 2,
            ]))
            ->assertRedirect();
        $this->actingAs($owner)->post(route('projects.docs.publish', [$project, $page]))->assertRedirect();
        $this->assertSame(4, $page->fresh()->current_rev);

        // Draft revs 2-3 hidden from readers; published revs 1 and 4 visible.
        $this->actingAs($member)->get(route('projects.docs.show', [$project, $page, 'rev' => 2]))->assertNotFound();
        $this->actingAs($member)->get(route('projects.docs.show', [$project, $page, 'rev' => 3]))->assertNotFound();
        $this->actingAs($member)->get(route('projects.docs.show', [$project, $page, 'rev' => 1]))->assertOk();
        $this->actingAs($member)->get(route('projects.docs.show', [$project, $page, 'rev' => 4]))->assertOk();
    }

    public function test_findings_and_metadata_are_pinned_per_revision(): void
    {
        [$project, $owner] = $this->seedProject();
        $page = DocPage::factory()->published()->create([
            'project_id' => $project->id, 'title' => 'First title', 'summary' => 'First summary',
        ]);

        $this->actingAs($owner)
            ->post(route('projects.docs.findings.store', [$project, $page]), [
                'statement' => 'U1 regulates 3V3.',
                'status' => DocFindingStatus::Observed->value,
            ])
            ->assertRedirect();
        $finding = DocFinding::firstOrFail();
        $createdRev = $page->fresh()->current_rev;

        $this->actingAs($owner)
            ->patch(route('projects.docs.findings.status', [$project, $page, $finding]), [
                'status' => DocFindingStatus::Hypothesis->value,
                'reason' => 'Needs a second measurement.',
            ])
            ->assertRedirect();

        $this->actingAs($owner)
            ->patch(route('projects.docs.update', [$project, $page]), $this->docPayload([
                'title' => 'Second title',
                'summary' => 'Second summary',
                'doc_version' => $page->fresh()->current_rev,
            ]))
            ->assertRedirect();

        // The creation revision pins Observed plus the original metadata…
        $pinned = $this->get(route('projects.docs.show', [$project, $page, 'rev' => $createdRev]));
        $pinned->assertOk();
        $pinned->assertSee('Observed', false);
        $pinned->assertDontSee('Hypothesis', false);
        $pinned->assertSee('First title', false);
        $pinned->assertSee('First summary', false);
        $pinned->assertDontSee('Second summary', false);

        // …while the current page shows the new status and metadata.
        $current = $this->get(route('projects.docs.show', [$project, $page]));
        $current->assertSee('Hypothesis', false);
        $current->assertSee('Second title', false);

        // Every transition is preserved, not just promotions to Verified.
        $this->assertSame(2, DocFindingEvent::where('finding_id', $finding->id)->count());
        $this->assertTrue(DocFindingEvent::where('finding_id', $finding->id)
            ->where('action', 'status')->where('status', 'hypothesis')
            ->where('reason', 'Needs a second measurement.')->exists());

        // Deleting the finding removes it from current but not history.
        $this->actingAs($owner)
            ->delete(route('projects.docs.findings.destroy', [$project, $page, $finding]))
            ->assertRedirect();
        $this->get(route('projects.docs.show', [$project, $page]))->assertDontSee('U1 regulates 3V3.', false);
        $this->get(route('projects.docs.show', [$project, $page, 'rev' => $createdRev]))->assertSee('U1 regulates 3V3.', false);
    }

    public function test_stale_editor_upload_preserves_version_and_save_is_refused(): void
    {
        [$project, $owner] = $this->seedProject();
        $page = DocPage::factory()->create(['project_id' => $project->id, 'current_rev' => 1]);

        // Second editor saves first: the page is now at rev 2.
        $this->actingAs($owner)
            ->patch(route('projects.docs.update', [$project, $page]), $this->docPayload(['doc_version' => 1]))
            ->assertRedirect();
        $this->assertSame(2, $page->fresh()->current_rev);

        // First (stale) editor uploads a photo mid-edit: the stale
        // version token must survive the upload round trip.
        $img = imagecreatetruecolor(60, 40);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 120, 60));
        ob_start();
        imagejpeg($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        $this->actingAs($owner)
            ->post(route('projects.media.store-image', $project), [
                'images' => [UploadedFile::fake()->createWithContent('board.jpg', $bytes)],
                'title' => 'Stale title',
                'doc_version' => 1,
                'doc_document' => json_encode($page->body),
            ])
            ->assertSessionHasInput('doc_version', 1);

        // …so the following save is refused instead of overwriting rev 2.
        $this->actingAs($owner)
            ->patch(route('projects.docs.update', [$project, $page]), $this->docPayload([
                'title' => 'Stale title',
                'doc_version' => 1,
            ]))
            ->assertSessionHasErrors('doc_version');
        $this->assertSame(2, $page->fresh()->current_rev);
    }

    public function test_media_used_in_history_or_findings_blocks_deletion(): void
    {
        [$project, $owner] = $this->seedProject();
        $media = ProjectMedia::factory()->create(['project_id' => $project->id]);
        $page = DocPage::factory()->create([
            'project_id' => $project->id,
            'body' => ['version' => 1, 'blocks' => [['type' => 'image', 'media_id' => $media->id]]],
        ]);

        // Remove the image from the current body: history still pins it.
        $this->actingAs($owner)
            ->patch(route('projects.docs.update', [$project, $page]), $this->docPayload(['doc_version' => 1]))
            ->assertRedirect();

        $this->actingAs($owner)
            ->delete(route('projects.media.destroy', [$project, $media]))
            ->assertSessionHas('error');
        $this->assertDatabaseHas('project_media', ['id' => $media->id]);
    }

    public function test_move_with_tied_order_flips_listing(): void
    {
        [$project, $owner] = $this->seedProject();
        $first = DocPage::factory()->published()->create(['project_id' => $project->id, 'title' => 'AAA first', 'sort_order' => 0]);
        $second = DocPage::factory()->published()->create(['project_id' => $project->id, 'title' => 'ZZZ second', 'sort_order' => 0]);

        $this->actingAs($owner)
            ->post(route('projects.docs.move', [$project, $second]), ['direction' => 'up'])
            ->assertRedirect();

        $index = $this->get(route('projects.docs.index', $project));
        $index->assertSeeInOrder(['ZZZ second', 'AAA first'], false);
        $this->assertNotSame($first->fresh()->sort_order, $second->fresh()->sort_order);
    }

    public function test_finding_edit_updates_refs_with_placement(): void
    {
        [$project, $owner] = $this->seedProject();
        $media = ProjectMedia::factory()->create(['project_id' => $project->id]);
        $other = Project::factory()->create();
        $foreignMedia = ProjectMedia::factory()->create(['project_id' => $other->id]);
        $page = DocPage::factory()->published()->create([
            'project_id' => $project->id,
            'body' => ['version' => 1, 'blocks' => [
                ['type' => 'image', 'media_id' => $media->id],
                ['type' => 'image', 'media_id' => $media->id, 'markers' => [
                    ['x' => 0.1, 'y' => 0.2, 'label' => 'U1'],
                ]],
            ]],
        ]);
        $finding = DocFinding::create([
            'doc_page_id' => $page->id, 'rev' => 1, 'status' => DocFindingStatus::Observed->value,
            'statement' => 'Chip here.',
        ]);

        // Cross-project media is rejected.
        $this->actingAs($owner)
            ->put(route('projects.docs.findings.update', [$project, $page, $finding]), [
                'statement' => 'Chip here.',
                'refs' => [['kind' => 'image', 'media_id' => $foreignMedia->id]],
            ])
            ->assertSessionHasErrors('refs', null, 'finding_'.$finding->id.'_edit');

        // Placement disambiguates two uses of the same image.
        $this->actingAs($owner)
            ->put(route('projects.docs.findings.update', [$project, $page, $finding]), [
                'statement' => 'Chip here, second photo.',
                'refs' => [['kind' => 'image', 'media_id' => $media->id, 'marker' => 1, 'placement' => 2]],
            ])
            ->assertRedirect();

        $show = $this->get(route('projects.docs.show', [$project, $page]));
        $show->assertSee('#img-2-marker-1', false);

        // A placement that does not exist renders explicitly unavailable.
        $this->actingAs($owner)
            ->put(route('projects.docs.findings.update', [$project, $page, $finding]), [
                'statement' => 'Chip here.',
                'refs' => [['kind' => 'image', 'media_id' => $media->id, 'placement' => 9]],
            ])
            ->assertRedirect();
        $this->get(route('projects.docs.show', [$project, $page]))->assertSee('[Image unavailable]', false);
    }

    public function test_legacy_findings_backfill_preserves_known_state(): void
    {
        [$project] = $this->seedProject();
        $member = User::factory()->create();
        $page = DocPage::factory()->published()->create(['project_id' => $project->id]);

        // A pre-events finding: live row only, no history, as if
        // created before the finding-events table existed.
        $finding = DocFinding::create([
            'doc_page_id' => $page->id, 'rev' => 1, 'status' => DocFindingStatus::Hypothesis->value,
            'statement' => 'Legacy idea.',
            'status_changed_by_id' => null, 'status_changed_at' => now(), 'status_change_reason' => null,
        ]);
        $this->assertSame(0, DocFindingEvent::count());

        $migrated = DocFindingEvent::backfillLegacy();

        $this->assertSame(1, $migrated);
        $event = DocFindingEvent::firstOrFail();
        $this->assertSame('legacy', $event->action);
        $this->assertSame($finding->id, $event->finding_id);
        $this->assertSame(1, $event->page_rev);
        $this->assertTrue($event->status === DocFindingStatus::Hypothesis);
        $this->assertStringContainsString('not recorded', (string) $event->reason);

        // Backfilled findings resolve in pinned views and tool reads…
        $this->get(route('projects.docs.show', [$project, $page, 'rev' => 1]))->assertSee('Legacy idea.', false);
        $read = DocsTools::execute('read_doc', ['doc_id' => $page->id, 'rev' => 1],
            new ToolContext($member, $project, null));
        $this->assertSame('hypothesis', $read->data['findings'][0]['status']);

        // A real event recorded before the backfill keeps precedence
        // over the legacy row even though the legacy row carries
        // the higher id: ordering is by revision, not insertion.
        $later = DocFinding::create([
            'doc_page_id' => $page->id, 'rev' => 1, 'status' => DocFindingStatus::Observed->value,
            'statement' => 'Edited later.',
        ]);
        DocFindingEvent::record($page, $later->id,
            DocFindingEvent::ACTION_EDITED, DocFindingStatus::Observed,
            'Edited later.', null, null, 2, $member->id, null);
        $this->assertSame(1, DocFindingEvent::backfillLegacy());
        $this->assertSame('Legacy idea.', DocFindingEvent::asOf($page->id, 1)
            ->firstWhere('finding_id', $finding->id)->statement);
        $this->assertSame('Edited later.', DocFindingEvent::asOf($page->id, 2)
            ->firstWhere('finding_id', $later->id)->statement);

        // …and the backfill is idempotent.
        $this->assertSame(0, DocFindingEvent::backfillLegacy());

        // Findings with a recorded creation are never backfilled,
        // even when only later events exist for other rows.
        $fresh = DocPage::factory()->published()->create(['project_id' => $project->id]);
        DocFinding::create([
            'doc_page_id' => $fresh->id, 'rev' => 1, 'status' => DocFindingStatus::Observed->value,
            'statement' => 'New.',
        ]);
        $newFinding = DocFinding::where('doc_page_id', $fresh->id)->firstOrFail();
        DocFindingEvent::record($fresh, $newFinding->id,
            DocFindingEvent::ACTION_CREATED, DocFindingStatus::Observed,
            'New.', null, null, 1, null, 'Recorded as observed');
        $this->assertSame(0, DocFindingEvent::backfillLegacy());
    }

    public function test_verified_findings_cannot_lose_their_last_support_by_edit(): void
    {
        [$project, $owner] = $this->seedProject();
        $page = DocPage::factory()->published()->create(['project_id' => $project->id]);
        $this->actingAs($owner)
            ->post(route('projects.docs.findings.store', [$project, $page]), [
                'statement' => 'U1 regulates 3V3.',
                'status' => DocFindingStatus::Verified->value,
                'measurement_notes' => 'Probed 3V28 at TP1.',
            ])
            ->assertRedirect();
        $finding = DocFinding::firstOrFail();
        $revBefore = $page->fresh()->current_rev;

        // Clearing the last note with no refs is rejected, nothing saved.
        $this->actingAs($owner)
            ->put(route('projects.docs.findings.update', [$project, $page, $finding]), [
                'statement' => 'U1 regulates 3V3.',
                'measurement_notes' => '',
                'refs' => [],
            ])
            ->assertSessionHasErrors('status', null, 'finding_'.$finding->id.'_edit');
        $this->assertSame('Probed 3V28 at TP1.', $finding->fresh()->measurement_notes);
        $this->assertSame($revBefore, $page->fresh()->current_rev);

        // Keeping support (or adding refs instead) still saves.
        $media = ProjectMedia::factory()->create(['project_id' => $project->id]);
        $this->actingAs($owner)
            ->put(route('projects.docs.findings.update', [$project, $page, $finding]), [
                'statement' => 'U1 regulates 3V3, confirmed by photo.',
                'measurement_notes' => '',
                'refs' => [['kind' => 'image', 'media_id' => $media->id]],
            ])
            ->assertRedirect();
        $this->assertSame('U1 regulates 3V3, confirmed by photo.', $finding->fresh()->statement);
    }

    public function test_pinned_photo_refs_keep_their_revision_in_links(): void
    {
        [$project, $owner] = $this->seedProject();
        $media = ProjectMedia::factory()->create(['project_id' => $project->id]);
        $page = DocPage::factory()->published()->create([
            'project_id' => $project->id,
            'body' => ['version' => 1, 'blocks' => [
                ['type' => 'image', 'media_id' => $media->id],
                ['type' => 'image', 'media_id' => $media->id, 'markers' => [
                    ['x' => 0.1, 'y' => 0.2, 'label' => 'U1'],
                ]],
            ]],
        ]);
        $this->actingAs($owner)
            ->post(route('projects.docs.findings.store', [$project, $page]), [
                'statement' => 'Chip on second photo.',
                'status' => DocFindingStatus::Observed->value,
            ])
            ->assertRedirect();
        $finding = DocFinding::firstOrFail();
        $this->actingAs($owner)
            ->put(route('projects.docs.findings.update', [$project, $page, $finding]), [
                'statement' => 'Chip on second photo.',
                'refs' => [['kind' => 'image', 'media_id' => $media->id, 'marker' => 1, 'placement' => 2]],
            ])
            ->assertRedirect();
        $pinnedRev = $page->fresh()->current_rev;

        // Reorder the photos afterwards: current placement 2 is now
        // the unmarked photo, but the pinned rev is unchanged.
        $this->actingAs($owner)
            ->patch(route('projects.docs.update', [$project, $page]), $this->docPayload([
                'doc_version' => $pinnedRev,
                'doc_document' => json_encode(['version' => 1, 'blocks' => [
                    ['type' => 'image', 'media_id' => $media->id, 'markers' => [
                        ['x' => 0.1, 'y' => 0.2, 'label' => 'U1'],
                    ]],
                    ['type' => 'image', 'media_id' => $media->id],
                ]]),
            ]))
            ->assertRedirect();

        // The old revision links to itself, resolving the original
        // placement — following it shows the marked second photo.
        $old = $this->get(route('projects.docs.show', [$project, $page, 'rev' => $pinnedRev]));
        $old->assertOk();
        $expected = route('projects.docs.show', [$project, $page, 'rev' => $pinnedRev]).'#img-2-marker-1';
        $old->assertSee($expected, false);
        $followed = $this->get(route('projects.docs.show', [$project, $page, 'rev' => $pinnedRev]));
        $followed->assertSee('id="img-2-marker-1"', false);

        // Live links carry no revision pin — and the reordered live
        // body no longer matches placement 2, so it says so plainly.
        $live = $this->get(route('projects.docs.show', [$project, $page]));
        $live->assertSee('[Image unavailable]', false);
        $live->assertDontSee('?rev=', false);
    }

    public function test_hostile_text_is_escaped_everywhere(): void
    {
        [$project, $owner] = $this->seedProject();
        $media = ProjectMedia::factory()->create(['project_id' => $project->id]);
        $xss = '"><script>alert(1)</script>';

        $this->actingAs($owner)
            ->post(route('projects.docs.store', $project), $this->docPayload([
                'title' => 'T'.$xss,
                'summary' => 'S'.$xss,
                'doc_document' => json_encode(['version' => 1, 'blocks' => [
                    ['type' => 'paragraph', 'text' => 'P'.$xss.' [evil](javascript:alert(1))'],
                    ['type' => 'step', 'title' => 'ST'.$xss, 'text' => 'do it', 'media_ids' => []],
                    ['type' => 'image', 'media_id' => $media->id, 'caption' => 'C'.$xss, 'alt' => 'A'.$xss, 'markers' => [
                        ['x' => 0.5, 'y' => 0.5, 'label' => 'L'.$xss, 'note' => 'N'.$xss],
                    ]],
                ]]),
            ]))
            ->assertRedirect();

        $page = DocPage::where('project_id', $project->id)->firstOrFail();
        $show = $this->get(route('projects.docs.show', [$project, $page]));
        $show->assertOk();
        $content = $show->getContent();
        $this->assertStringNotContainsString('<script>', $content);
        $this->assertStringNotContainsString('href="javascript', $content);
        $this->assertStringContainsString(e('T'.$xss), $content);
        $this->assertStringContainsString(e('L'.$xss), $content);
        // Unsafe links render as literal text, never anchors.
        $this->assertStringContainsString(e('[evil](javascript:alert(1))'), $content);
    }

    public function test_steps_number_and_markers_render_with_legend(): void
    {
        [$project] = $this->seedProject();
        $media = ProjectMedia::factory()->create(['project_id' => $project->id]);
        $page = DocPage::factory()->published()->create([
            'project_id' => $project->id,
            'body' => ['version' => 1, 'blocks' => [
                ['type' => 'step', 'title' => 'Open', 'text' => 'Lift.', 'media_ids' => [$media->id]],
                ['type' => 'paragraph', 'text' => 'Between.'],
                ['type' => 'step', 'text' => 'Probe.', 'media_ids' => []],
                ['type' => 'image', 'media_id' => $media->id, 'markers' => [
                    ['x' => 0.25, 'y' => 0.75, 'label' => 'U1', 'note' => 'Main chip'],
                ]],
            ]],
        ]);

        $show = $this->get(route('projects.docs.show', [$project, $page]));
        $show->assertOk();
        $show->assertSee('Step 1: Open', false);
        $show->assertSee('Step 2', false);
        $show->assertSee('id="step-1"', false);
        $show->assertSee('id="img-1-marker-1"', false);
        $show->assertSee('U1', false);
        $show->assertSee('Main chip', false);
        // Normalized coordinates become percentages, aligned at any size.
        $show->assertSee('left: 25%; top: 75%;', false);
        // Zoom opens the high-resolution variant, not the card.
        $show->assertSee('variant=wide', false);
    }

    public function test_doc_links_resolve_or_show_unavailable_truthfully(): void
    {
        [$project] = $this->seedProject();
        $target = DocPage::factory()->published()->create(['project_id' => $project->id, 'title' => 'Target guide']);
        $draftTarget = DocPage::factory()->create(['project_id' => $project->id, 'title' => 'Hidden draft']);
        $page = DocPage::factory()->published()->create([
            'project_id' => $project->id,
            'body' => ['version' => 1, 'blocks' => [
                ['type' => 'doc_link', 'doc_id' => $target->id],
                ['type' => 'doc_link', 'doc_id' => $draftTarget->id, 'label' => 'Draft link'],
            ]],
        ]);

        $show = $this->get(route('projects.docs.show', [$project, $page]));
        $show->assertSee('Target guide', false);
        $show->assertSee(route('projects.docs.show', [$project, $target]), false);
        $show->assertSee('[Documentation unavailable]', false);
        $show->assertDontSee('Hidden draft', false);
        $show->assertDontSee('Draft link', false);
    }

    public function test_docs_media_use_blocks_deletion_until_removed(): void
    {
        [$project, $owner] = $this->seedProject();
        $media = ProjectMedia::factory()->create(['project_id' => $project->id]);
        $page = DocPage::factory()->create([
            'project_id' => $project->id,
            'body' => ['version' => 1, 'blocks' => [
                ['type' => 'image', 'media_id' => $media->id],
                ['type' => 'step', 'text' => 'x', 'media_ids' => [$media->id]],
            ]],
        ]);

        $this->actingAs($owner)
            ->delete(route('projects.media.destroy', [$project, $media]))
            ->assertSessionHas('error');
        $this->assertDatabaseHas('project_media', ['id' => $media->id]);

        // Removing every current reference still leaves the
        // retained revision pinning the bytes: history is immutable.
        $this->actingAs($owner)
            ->patch(route('projects.docs.update', [$project, $page]), $this->docPayload([
                'doc_version' => 1,
            ]))
            ->assertRedirect();
        $this->actingAs($owner)
            ->delete(route('projects.media.destroy', [$project, $media]))
            ->assertSessionHas('error');
        $this->assertDatabaseHas('project_media', ['id' => $media->id]);
    }

    public function test_photo_upload_mid_edit_preserves_the_draft(): void
    {
        [$project, $owner] = $this->seedProject();

        $img = imagecreatetruecolor(100, 80);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 120, 60));
        ob_start();
        imagejpeg($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        $draft = json_encode(['version' => 1, 'blocks' => [['type' => 'paragraph', 'text' => 'Unsaved words.']]]);
        $this->actingAs($owner)
            ->post(route('projects.media.store-image', $project), [
                'images' => [UploadedFile::fake()->createWithContent('board.jpg', $bytes)],
                'title' => 'Unsaved title',
                'doc_document' => $draft,
            ])
            ->assertSessionHas('status')
            ->assertSessionHasInput('title', 'Unsaved title')
            ->assertSessionHasInput('doc_document', $draft);

        $this->assertSame(1, ProjectMedia::where('project_id', $project->id)->count());
    }

    public function test_edit_finding_validation_errors_reopen_that_edit_form_only(): void
    {
        [$project, $owner] = $this->seedProject();
        $page = DocPage::factory()->published()->create(['project_id' => $project->id]);
        $this->actingAs($owner)
            ->post(route('projects.docs.findings.store', [$project, $page]), [
                'statement' => 'U1 regulates 3V3.',
                'status' => DocFindingStatus::Verified->value,
                'measurement_notes' => 'Probed at TP1.',
            ])
            ->assertRedirect();
        $finding = DocFinding::firstOrFail();

        // Stripping the last support is refused. The bag placement
        // is proven through the rendered page below: each form
        // reads only its own named bag, so visible, correctly
        // placed errors imply correct routing. (Reading flashed
        // bags here would consume them for the follow-up GET in
        // this same-process harness; browsers never do that.)
        $revBefore = $page->fresh()->current_rev;
        $this->actingAs($owner)
            ->put(route('projects.docs.findings.update', [$project, $page, $finding]), [
                'statement' => 'U1 regulates 3V3, edited.',
                'measurement_notes' => '',
                'refs' => [],
            ])
            ->assertRedirect();
        $this->assertSame('Probed at TP1.', $finding->fresh()->measurement_notes);
        $this->assertSame($revBefore, $page->fresh()->current_rev);

        // The affected edit form reopens with its input and error;
        // Add a finding stays closed and empty.
        $response = $this->actingAs($owner)->get(route('projects.docs.show', [$project, $page]));
        $response->assertSee('A Verified finding needs measurement notes', false);
        $response->assertSee('<textarea name="statement" rows="2" required maxlength="2000" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm">U1 regulates 3V3, edited.</textarea>', false);
        $response->assertSee('<textarea name="statement" rows="2" required maxlength="2000" class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500"></textarea>', false);
        $response->assertSee('<details class="mt-4 rounded-xl border border-zinc-200 bg-white p-4">', false);
        $this->assertSame(1, substr_count($response->getContent(), '<details class="mt-2 text-sm" open>'));
    }

    public function test_edit_finding_rule_violations_use_the_edit_bag(): void
    {
        [$project, $owner] = $this->seedProject();
        $page = DocPage::factory()->published()->create(['project_id' => $project->id]);
        $this->actingAs($owner)
            ->post(route('projects.docs.findings.store', [$project, $page]), [
                'statement' => 'U1 regulates 3V3.',
                'status' => DocFindingStatus::Observed->value,
            ])
            ->assertRedirect();
        $finding = DocFinding::firstOrFail();

        // Rule violations take the same named-bag path as custom
        // errors; the reopened form below proves the routing.
        $this->actingAs($owner)
            ->put(route('projects.docs.findings.update', [$project, $page, $finding]), [
                'statement' => '',
            ])
            ->assertRedirect();

        $response = $this->actingAs($owner)->get(route('projects.docs.show', [$project, $page]));
        $response->assertSee('The statement field is required.', false);
        $this->assertSame(1, substr_count($response->getContent(), '<details class="mt-2 text-sm" open>'));
        $response->assertSee('<details class="mt-4 rounded-xl border border-zinc-200 bg-white p-4">', false);
    }

    public function test_status_change_validation_errors_reopen_that_status_form_only(): void
    {
        [$project, $owner] = $this->seedProject();
        $page = DocPage::factory()->published()->create(['project_id' => $project->id]);
        $this->actingAs($owner)
            ->post(route('projects.docs.findings.store', [$project, $page]), [
                'statement' => 'U1 regulates 3V3.',
                'status' => DocFindingStatus::Observed->value,
            ])
            ->assertRedirect();
        $finding = DocFinding::firstOrFail();

        // Promoting to Verified without support is refused; the
        // reopened status form below proves the bag routing.
        $this->actingAs($owner)
            ->patch(route('projects.docs.findings.status', [$project, $page, $finding]), [
                'status' => DocFindingStatus::Verified->value,
                'reason' => 'Looks verified to me.',
            ])
            ->assertRedirect();
        $this->assertTrue($finding->fresh()->status === DocFindingStatus::Observed);

        $response = $this->actingAs($owner)->get(route('projects.docs.show', [$project, $page]));
        $response->assertSee('A Verified finding needs measurement notes', false);
        $response->assertSee('value="Looks verified to me."', false);
        $response->assertSee('<textarea name="statement" rows="2" required maxlength="2000" class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500"></textarea>', false);
        $this->assertSame(1, substr_count($response->getContent(), '<details class="mt-2 text-sm" open>'));
    }
}
