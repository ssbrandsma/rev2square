<?php

namespace App\Http\Controllers;

use App\Enums\DocCategory;
use App\Enums\DocFindingStatus;
use App\Enums\PublicationStatus;
use App\Models\AuditEvent;
use App\Models\DocFinding;
use App\Models\DocFindingEvent;
use App\Models\DocPage;
use App\Models\DocRevision;
use App\Models\EvidenceSource;
use App\Models\Project;
use App\Models\ProjectArtifact;
use App\Models\ProjectMedia;
use App\Models\User;
use App\Services\Docs\DocDocument;
use App\Services\Docs\DocRenderer;
use App\Services\Media\MediaUploadService;
use App\Services\Media\StoryRenderer;
use App\Support\Like;
use App\Support\UniqueTitle;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class DocController extends Controller
{
    public function index(Request $request, Project $project): View
    {
        Gate::authorize('view', $project);

        $category = $request->string('category')->toString();
        $category = in_array($category, DocCategory::values(), true) ? $category : null;
        $search = trim($request->string('q')->toString());
        $search = mb_substr($search, 0, 100);

        $pages = $project->docPages()
            ->readable($project, $request->user())
            ->when($category !== null, fn ($query) => $query->where('category', $category))
            ->when($search !== '', function ($query) use ($search) {
                $like = Like::contains($search);
                $query->where(function ($query) use ($like) {
                    $query->where('title', 'like', $like)
                        ->orWhere('summary', 'like', $like)
                        ->orWhere('search_text', 'like', $like);
                });
            })
            ->orderBy('category')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        return view('projects.docs.index', [
            'project' => $project,
            'pages' => $pages,
            'category' => $category,
            'search' => $search,
        ]);
    }

    public function create(Project $project): View
    {
        Gate::authorize('update', $project);

        return view('projects.docs.form', [
            'project' => $project,
            'page' => null,
            'images' => $this->libraryImages($project),
            'pages' => $this->linkTargets($project, null),
            'uploadLimits' => MediaUploadService::uploadLimits(),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $data = $this->validatePage($request);
        $checked = $this->validateDocument($request, $project, $data['doc_document']);
        if (! $checked['ok']) {
            return back()->withInput()->withErrors(['doc_document' => $checked['error']]);
        }
        $document = $checked['document'];

        UniqueTitle::abortIfDuplicate(
            $project->docPages()->where('category', $data['category']),
            $data['title'],
            null,
            'This project already has a documentation page with that title in this category.'
        );

        $page = DocPage::create([
            'project_id' => $project->id,
            'category' => $data['category'],
            'title' => $data['title'],
            'summary' => $data['summary'] ?? null,
            'publication_status' => $data['publication_status'],
            'sort_order' => $data['sort_order'] ?? 0,
            'current_rev' => 1,
            'body' => $document,
            'search_text' => '',
        ]);
        $page->forceFill(['search_text' => $this->searchText($page)])->save();

        DocRevision::create([
            'doc_page_id' => $page->id,
            'rev' => 1,
            'title' => $page->title,
            'summary' => $page->summary,
            'category' => $page->category->value,
            'publication_status' => $page->publication_status->value,
            'body' => $document,
            'author_id' => $request->user()->id,
            'note' => $data['rev_note'] ?? null,
            'created_at' => now(),
        ]);

        AuditEvent::record('doc.created', $request->user(), $project, $page, ['title' => $page->title]);
        if ($page->isPublished()) {
            AuditEvent::record('doc.published', $request->user(), $project, $page, ['title' => $page->title, 'rev' => 1]);
        }

        return redirect()->route('projects.docs.show', [$project, $page])
            ->with('status', 'Documentation page created.');
    }

    public function show(Request $request, Project $project, int $doc): View
    {
        Gate::authorize('view', $project);
        $page = $this->resolve($project, $doc, true);

        if ($page->trashed() && ! $request->user()?->can('update', $project)) {
            abort(404);
        }

        if ($page->trashed()) {
            return view('projects.docs.deleted', ['project' => $project, 'page' => $page]);
        }

        // Visibility follows what is requested: the live page needs
        // a published page, while a pinned revision needs a
        // published revision — a published rev stays viewable even
        // after the page is unpublished, and a draft rev never leaks
        // through a published page.
        $revision = null;
        if ($request->has('rev')) {
            $rev = $request->integer('rev');
            $revision = $page->revisions()->where('rev', $rev)->first();
            abort_if($revision === null || $rev < 1 || $rev > $page->current_rev, 404);
            abort_if($revision->publication_status !== PublicationStatus::Published
                && ! $request->user()?->can('update', $project), 404);
        } elseif (! $page->isPublished() && ! $request->user()?->can('update', $project)) {
            abort(404);
        }

        $body = $revision?->body ?? $page->body;
        $canEdit = $request->user()?->can('update', $project) ?? false;

        return view('projects.docs.show', [
            'project' => $project,
            'page' => $page,
            // Pinned views use the revision's own fields — including
            // an intentionally null summary — never current content.
            'displayTitle' => $revision !== null ? $revision->title : $page->title,
            'displaySummary' => $revision !== null ? $revision->summary : $page->summary,
            'displayCategory' => $revision !== null ? $revision->category : $page->category,
            'displayDate' => $revision !== null ? ($revision->created_at ?? $page->updated_at) : $page->updated_at,
            'html' => DocRenderer::toHtml($body, $project, $request->user()),
            'anchors' => DocDocument::anchors(is_array($body) ? $body : null),
            'findings' => $this->findingCards($page, $revision?->rev),
            'refTargets' => $canEdit && $revision === null ? $this->refTargets($project) : null,
            'revision' => $revision,
            'stale' => $revision !== null && $revision->rev < $page->current_rev,
            'canEdit' => $canEdit,
        ]);
    }

    /**
     * Uniform finding cards for live and pinned views. Live views
     * read current rows; pinned views replay the append-only event
     * log, so statuses, notes, and deletions resolve immutably.
     *
     * @return list<array{id: int, status: DocFindingStatus, statement: string, measurement_notes: ?string, refs: ?array, by: ?string, at: ?Carbon, reason: ?string}>
     */
    private function findingCards(DocPage $page, ?int $rev): array
    {
        if ($rev === null) {
            return $page->findings()->with('statusChangedBy')->orderBy('id')->get()
                ->map(fn (DocFinding $finding): array => [
                    'id' => $finding->id,
                    'status' => $finding->status,
                    'statement' => $finding->statement,
                    'measurement_notes' => $finding->measurement_notes,
                    'refs' => $finding->refs,
                    'by' => $finding->statusChangedBy?->name,
                    'at' => $finding->status_changed_at,
                    'reason' => $finding->status_change_reason,
                ])->all();
        }

        return DocFindingEvent::asOf($page->id, $rev)
            ->map(fn (DocFindingEvent $event): array => [
                'id' => $event->finding_id,
                'status' => $event->status,
                'statement' => $event->statement,
                'measurement_notes' => $event->measurement_notes,
                'refs' => $event->refs,
                'by' => $event->action === DocFindingEvent::ACTION_LEGACY
                    ? 'Legacy import'
                    : $event->changedBy?->name,
                // Legacy rows were written by the backfill, not at
                // the pinned revision — no honest timestamp exists.
                'at' => $event->action === DocFindingEvent::ACTION_LEGACY
                    ? null
                    : $event->created_at,
                'reason' => $event->reason,
            ])->all();
    }

    public function edit(Project $project, int $doc): View
    {
        Gate::authorize('update', $project);
        $page = $this->resolve($project, $doc);
        abort_if($page->trashed(), 404);

        return view('projects.docs.form', [
            'project' => $project,
            'page' => $page,
            'images' => $this->libraryImages($project),
            'pages' => $this->linkTargets($project, $page->id),
            'uploadLimits' => MediaUploadService::uploadLimits(),
        ]);
    }

    public function update(Request $request, Project $project, int $doc): RedirectResponse
    {
        Gate::authorize('update', $project);

        $data = $this->validatePage($request, true);
        $checked = $this->validateDocument($request, $project, $data['doc_document'], $doc);
        if (! $checked['ok']) {
            return back()->withInput()->withErrors(['doc_document' => $checked['error']]);
        }
        $document = $checked['document'];

        // Version check, page save, and revision insert commit
        // together under a row lock: two simultaneous saves cannot
        // both pass the check and interleave bodies with revisions.
        $result = DB::transaction(function () use ($request, $project, $doc, $data, $document): array {
            $page = $this->lockedPage($project, $doc);
            abort_if($page->trashed(), 404);

            UniqueTitle::abortIfDuplicate(
                $project->docPages()->where('category', $data['category']),
                $data['title'],
                $page,
                'This project already has a documentation page with that title in this category.'
            );

            // Optimistic concurrency: a stale version is refused only
            // when the content actually changed, so harmless re-saves
            // never block anyone.
            $changed = $data['title'] !== $page->title
                || ($data['summary'] ?? null) !== $page->summary
                || $data['category'] !== $page->category->value
                || $data['publication_status'] !== $page->publication_status->value
                || ($data['sort_order'] ?? 0) !== (int) $page->sort_order
                || $document !== $page->body;

            if (! $changed) {
                return ['outcome' => 'unchanged', 'page' => $page];
            }

            if ((int) $data['doc_version'] !== $page->current_rev) {
                return ['outcome' => 'conflict', 'page' => $page];
            }

            $wasPublished = $page->isPublished();
            $page->forceFill([
                'title' => $data['title'],
                'summary' => $data['summary'] ?? null,
                'category' => $data['category'],
                'publication_status' => $data['publication_status'],
                'sort_order' => $data['sort_order'] ?? 0,
                'body' => $document,
            ]);
            $page->forceFill(['search_text' => $this->searchText($page)])->save();
            $rev = $this->bumpRevision($page, $request->user(), $data['rev_note'] ?? null);

            return ['outcome' => 'saved', 'page' => $page, 'rev' => $rev, 'wasPublished' => $wasPublished];
        });

        if ($result['outcome'] === 'unchanged') {
            return redirect()->route('projects.docs.show', [$project, $result['page']])
                ->with('status', 'No changes to save.');
        }

        if ($result['outcome'] === 'conflict') {
            return back()->withInput()->withErrors([
                'doc_version' => 'Someone else saved this page while you were editing. Your draft is preserved below — copy any work you need, reload, and save again.',
            ]);
        }

        if (! $result['wasPublished'] && $result['page']->isPublished()) {
            AuditEvent::record('doc.published', $request->user(), $project, $result['page'], ['title' => $result['page']->title, 'rev' => $result['rev']]);
        }

        return redirect()->route('projects.docs.show', [$project, $result['page']])
            ->with('status', 'Documentation page saved (revision '.$result['rev'].').');
    }

    public function destroy(Project $project, int $doc): RedirectResponse
    {
        Gate::authorize('delete', $project);
        $page = $this->resolve($project, $doc);
        abort_if($page->trashed(), 404);

        // Soft delete: revisions and findings stay so existing
        // citations resolve to an explicit unavailable state, and
        // shared media is never touched.
        $page->delete();

        return redirect()->route('projects.docs.index', $project)
            ->with('status', 'Documentation page deleted. Existing citations now show it as unavailable.');
    }

    public function restore(Project $project, int $doc): RedirectResponse
    {
        Gate::authorize('update', $project);
        $page = $this->resolve($project, $doc, true);
        abort_unless($page->trashed(), 404);

        $page->restore();

        return redirect()->route('projects.docs.show', [$project, $page])
            ->with('status', 'Documentation page restored.');
    }

    public function publish(Request $request, Project $project, int $doc): RedirectResponse
    {
        return $this->setStatus($request, $project, $doc, PublicationStatus::Published);
    }

    public function unpublish(Request $request, Project $project, int $doc): RedirectResponse
    {
        return $this->setStatus($request, $project, $doc, PublicationStatus::Draft);
    }

    private function setStatus(Request $request, Project $project, int $doc, PublicationStatus $status): RedirectResponse
    {
        Gate::authorize('update', $project);

        $result = DB::transaction(function () use ($request, $project, $doc, $status): array {
            $page = $this->lockedPage($project, $doc);
            abort_if($page->trashed(), 404);

            if ($page->publication_status === $status) {
                return ['outcome' => 'noop', 'page' => $page];
            }

            $page->forceFill(['publication_status' => $status->value])->save();
            $rev = $this->bumpRevision($page, $request->user(), $status === PublicationStatus::Published ? 'Published' : 'Unpublished to draft');

            return ['outcome' => 'changed', 'page' => $page, 'rev' => $rev];
        });

        if ($result['outcome'] === 'noop') {
            return back()->with('status', 'Already '.$status->label().'.');
        }

        if ($status === PublicationStatus::Published) {
            AuditEvent::record('doc.published', $request->user(), $project, $result['page'], ['title' => $result['page']->title, 'rev' => $result['rev']]);
        }

        return redirect()->route('projects.docs.show', [$project, $result['page']])
            ->with('status', $status === PublicationStatus::Published ? 'Documentation page published.' : 'Documentation page moved back to draft.');
    }

    public function move(Request $request, Project $project, int $doc): RedirectResponse
    {
        Gate::authorize('update', $project);

        $data = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);

        $category = DB::transaction(function () use ($project, $doc, $data): string {
            $page = $this->lockedPage($project, $doc);
            abort_if($page->trashed(), 404);

            $siblings = $project->docPages()
                ->where('category', $page->category->value)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // Normalize first: new pages share sort_order 0, and
            // swapping equal values would report success while the
            // listing (tie-broken by id) never moves.
            foreach ($siblings->values() as $rank => $sibling) {
                if ((int) $sibling->sort_order !== $rank * 10) {
                    $sibling->forceFill(['sort_order' => $rank * 10])->save();
                }
            }

            $index = $siblings->search(fn (DocPage $sibling): bool => $sibling->id === $page->id);
            $neighbor = $siblings->get($data['direction'] === 'up' ? $index - 1 : $index + 1);

            // Ordering is presentation, not content: swapping
            // positions never writes a revision. Swap the normalized
            // sibling instances, not the pre-normalize locked copy.
            if ($neighbor !== null) {
                $self = $siblings->get($index);
                [$self->sort_order, $neighbor->sort_order] = [$neighbor->sort_order, $self->sort_order];
                $self->save();
                $neighbor->save();
            }

            return $page->category->value;
        });

        return redirect()->route('projects.docs.index', ['project' => $project, 'category' => $category])
            ->with('status', 'Page order updated.');
    }

    public function revisions(Request $request, Project $project, int $doc): View
    {
        Gate::authorize('view', $project);
        $page = $this->resolve($project, $doc);
        abort_if($page->trashed(), 404);
        $this->denyDraft($request, $project, $page);

        // Readers see published revisions only; draft titles and
        // notes in history would leak the drafts themselves.
        $revisions = $page->revisions()->with('author')->orderByDesc('rev');
        if (! $request->user()?->can('update', $project)) {
            $revisions->where('publication_status', PublicationStatus::Published->value);
        }

        return view('projects.docs.revisions', [
            'project' => $project,
            'page' => $page,
            'revisions' => $revisions->get(),
            'canEdit' => request()->user()?->can('update', $project) ?? false,
        ]);
    }

    public function restoreRevision(Request $request, Project $project, int $doc, int $rev): RedirectResponse
    {
        Gate::authorize('update', $project);

        // Restoration never rewrites history: the old body becomes a
        // new revision, so existing citations keep their meaning.
        $result = DB::transaction(function () use ($request, $project, $doc, $rev): array {
            $page = $this->lockedPage($project, $doc);
            abort_if($page->trashed(), 404);

            $revision = $page->revisions()->where('rev', $rev)->first();
            abort_if($revision === null, 404);

            $page->forceFill([
                'title' => $revision->title,
                'summary' => $revision->summary,
                'category' => $revision->category->value,
                'body' => $revision->body,
            ]);
            $page->forceFill(['search_text' => $this->searchText($page)])->save();
            $newRev = $this->bumpRevision($page, $request->user(), 'Restored from revision '.$revision->rev);

            return ['page' => $page, 'rev' => $newRev];
        });

        return redirect()->route('projects.docs.show', [$project, $result['page']])
            ->with('status', 'Revision '.$rev.' restored as revision '.$result['rev'].'.');
    }

    public function export(Request $request, Project $project, int $doc, string $format): SymfonyResponse
    {
        Gate::authorize('view', $project);
        abort_unless(in_array($format, ['md', 'json'], true), 404);
        $page = $this->resolve($project, $doc);
        abort_if($page->trashed(), 404);
        $this->denyDraft($request, $project, $page);

        $page->load('findings.statusChangedBy');
        $viewer = $request->user();

        // Exports carry metadata plus authorized route URLs, never
        // image bytes or private storage paths.
        $data = [
            'title' => $page->title,
            'summary' => $page->summary,
            'category' => $page->category->label(),
            'status' => $page->publication_status->label(),
            'revision' => $page->current_rev,
            'updated_at' => $page->updated_at->toIso8601String(),
            'markdown' => DocRenderer::toMarkdown(is_array($page->body) ? $page->body : null, $project, $viewer),
            'findings' => $page->findings->map(fn (DocFinding $finding): array => [
                'status' => $finding->status->label(),
                'statement' => $finding->statement,
                'measurement_notes' => $finding->measurement_notes,
                'refs' => $finding->refs,
                'recorded_at_revision' => $finding->rev,
            ])->all(),
        ];

        if ($format === 'json') {
            return Response::json($data)
                ->header('Content-Disposition', "attachment; filename=\"{$project->slug}-doc-{$page->id}.json\"");
        }

        $markdown = '# '.$page->title."\n\n".$data['markdown'];
        if ($data['findings'] !== []) {
            $markdown .= "\n\n## Findings\n";
            foreach ($data['findings'] as $finding) {
                $markdown .= "\n- **{$finding['status']}**: {$finding['statement']}";
                if ($finding['measurement_notes']) {
                    $markdown .= " ({$finding['measurement_notes']})";
                }
            }
            $markdown .= "\n";
        }

        return Response::make($markdown, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$project->slug}-doc-{$page->id}.md\"",
        ]);
    }

    public function storeFinding(Request $request, Project $project, int $doc): RedirectResponse
    {
        Gate::authorize('update', $project);

        $data = $request->validateWithBag('finding_create', [
            'statement' => ['required', 'string', 'max:2000'],
            'status' => ['required', Rule::enum(DocFindingStatus::class)],
            'measurement_notes' => ['nullable', 'string', 'max:2000'],
            'refs' => ['nullable', 'array', 'max:10'],
        ]);
        $refs = $this->cleanFindingRefs($project, $this->keptRefs($data['refs'] ?? []));
        if ($refs === null) {
            return back()->withInput()->withErrors(['refs' => 'One of the references is invalid.'], 'finding_create')->with('findingForm', 'create');
        }
        if ($data['status'] === DocFindingStatus::Verified->value && trim((string) ($data['measurement_notes'] ?? '')) === '' && $refs === []) {
            return back()->withInput()->withErrors(['status' => 'A Verified finding needs measurement notes or corroborating references.'], 'finding_create')->with('findingForm', 'create');
        }

        // Findings are evidence: recording one appends a page
        // revision and a history event, so pinned citations resolve
        // the finding exactly as recorded.
        $result = DB::transaction(function () use ($request, $project, $doc, $data, $refs): array {
            $page = $this->lockedPage($project, $doc);
            abort_if($page->trashed(), 404);

            $finding = DocFinding::create([
                'doc_page_id' => $page->id,
                'rev' => $page->current_rev + 1,
                'status' => $data['status'],
                'statement' => trim($data['statement']),
                'refs' => $refs === [] ? null : $refs,
                'measurement_notes' => ($data['measurement_notes'] ?? null) ? trim((string) $data['measurement_notes']) : null,
                'status_changed_by_id' => $request->user()->id,
                'status_changed_at' => now(),
                'status_change_reason' => 'Recorded as '.$data['status'],
            ]);

            $page->forceFill(['search_text' => $this->searchText($page)])->save();
            $rev = $this->bumpRevision($page, $request->user(), 'Finding recorded');
            DocFindingEvent::record($page, $finding->id, DocFindingEvent::ACTION_CREATED,
                $finding->status, $finding->statement, $finding->refs, $finding->measurement_notes,
                $rev, $request->user()->id, 'Recorded as '.$data['status']);

            return ['page' => $page, 'finding' => $finding];
        });

        if ($result['finding']->status === DocFindingStatus::Verified) {
            AuditEvent::record('doc.finding_status', $request->user(), $project, $result['page'], [
                'title' => $result['page']->title, 'status' => 'verified', 'statement' => $result['finding']->statement,
            ]);
        }

        return redirect(route('projects.docs.show', [$project, $result['page']]).'#findings')
            ->with('status', 'Finding recorded.');
    }

    public function updateFinding(Request $request, Project $project, int $doc, DocFinding $finding): RedirectResponse
    {
        Gate::authorize('update', $project);

        $bag = 'finding_'.$finding->id.'_edit';
        $data = $request->validateWithBag($bag, [
            'statement' => ['required', 'string', 'max:2000'],
            'measurement_notes' => ['nullable', 'string', 'max:2000'],
            'refs' => ['nullable', 'array', 'max:10'],
        ]);
        $refs = $this->cleanFindingRefs($project, $this->keptRefs($data['refs'] ?? []));
        if ($refs === null) {
            return back()->withInput()->withErrors(['refs' => 'One of the references is invalid.'], $bag)->with('editFinding', $finding->id);
        }

        $valid = DB::transaction(function () use ($request, $project, $doc, $finding, $data, $refs): bool {
            $page = $this->lockedPage($project, $doc);
            abort_if($page->trashed(), 404);
            $finding = $page->findings()->whereKey($finding->id)->lockForUpdate()->first();
            abort_if($finding === null, 404);

            // Edits cannot strip a Verified finding's last support:
            // downgrade the status explicitly instead.
            $notes = ($data['measurement_notes'] ?? null) ? trim((string) $data['measurement_notes']) : null;
            if ($finding->status === DocFindingStatus::Verified && ($notes === null || $notes === '') && $refs === []) {
                return false;
            }

            $finding->forceFill([
                'statement' => trim($data['statement']),
                'measurement_notes' => $notes,
                'refs' => $refs === [] ? null : $refs,
            ])->save();

            $page->forceFill(['search_text' => $this->searchText($page)])->save();
            $rev = $this->bumpRevision($page, $request->user(), 'Finding updated');
            DocFindingEvent::record($page, $finding->id, DocFindingEvent::ACTION_EDITED,
                $finding->status, $finding->statement, $finding->refs, $finding->measurement_notes,
                $rev, $request->user()->id, null);

            return true;
        });

        if (! $valid) {
            return back()->withInput()->withErrors(['status' => 'A Verified finding needs measurement notes or corroborating references. Change its status to remove them.'], $bag)->with('editFinding', $finding->id);
        }

        return redirect(route('projects.docs.show', [$project, $doc]).'#finding-'.$finding->id)
            ->with('status', 'Finding updated.');
    }

    public function updateFindingStatus(Request $request, Project $project, int $doc, DocFinding $finding): RedirectResponse
    {
        Gate::authorize('update', $project);

        $bag = 'finding_'.$finding->id.'_status';
        $data = $request->validateWithBag($bag, [
            'status' => ['required', Rule::enum(DocFindingStatus::class)],
            'reason' => ['required', 'string', 'max:500'],
            'measurement_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $result = DB::transaction(function () use ($request, $project, $doc, $finding, $data): array {
            $page = $this->lockedPage($project, $doc);
            abort_if($page->trashed(), 404);
            $finding = $page->findings()->whereKey($finding->id)->lockForUpdate()->first();
            abort_if($finding === null, 404);

            $notes = array_key_exists('measurement_notes', $data) ? trim((string) $data['measurement_notes']) : $finding->measurement_notes;
            if ($data['status'] === DocFindingStatus::Verified->value && ($notes === '' || $notes === null) && ($finding->refs ?? []) === []) {
                return ['outcome' => 'invalid'];
            }

            // Statuses move only by explicit maintainer action with
            // a reason; nothing promotes AI output automatically.
            // Every transition appends a page revision and a history
            // event, so pinned citations keep the old status.
            $finding->forceFill([
                'status' => $data['status'],
                'measurement_notes' => $notes === '' ? null : $notes,
                'status_changed_by_id' => $request->user()->id,
                'status_changed_at' => now(),
                'status_change_reason' => trim($data['reason']),
            ])->save();

            $page->forceFill(['search_text' => $this->searchText($page)])->save();
            $rev = $this->bumpRevision($page, $request->user(), 'Finding status: '.$data['status']);
            DocFindingEvent::record($page, $finding->id, DocFindingEvent::ACTION_STATUS,
                $finding->status, $finding->statement, $finding->refs, $finding->measurement_notes,
                $rev, $request->user()->id, trim($data['reason']));

            return ['outcome' => 'saved', 'page' => $page, 'finding' => $finding];
        });

        if ($result['outcome'] === 'invalid') {
            return back()->withInput()->withErrors(['status' => 'A Verified finding needs measurement notes or corroborating references.'], $bag)->with('statusFinding', $finding->id);
        }

        if ($result['finding']->status === DocFindingStatus::Verified) {
            AuditEvent::record('doc.finding_status', $request->user(), $project, $result['page'], [
                'title' => $result['page']->title, 'status' => 'verified', 'statement' => $result['finding']->statement,
            ]);
        }

        return redirect(route('projects.docs.show', [$project, $result['page']]).'#findings')
            ->with('status', 'Finding status updated.');
    }

    public function destroyFinding(Request $request, Project $project, int $doc, DocFinding $finding): RedirectResponse
    {
        Gate::authorize('update', $project);

        $data = $request->validateWithBag('finding_'.$finding->id.'_status', ['reason' => ['nullable', 'string', 'max:500']]);

        $page = DB::transaction(function () use ($request, $project, $doc, $finding, $data): DocPage {
            $page = $this->lockedPage($project, $doc);
            abort_if($page->trashed(), 404);
            $finding = $page->findings()->whereKey($finding->id)->lockForUpdate()->first();
            abort_if($finding === null, 404);

            // Deletion snapshots the last state first: pinned
            // citations keep resolving the removed finding.
            DocFindingEvent::record($page, $finding->id, DocFindingEvent::ACTION_DELETED,
                $finding->status, $finding->statement, $finding->refs, $finding->measurement_notes,
                $page->current_rev + 1, $request->user()->id, ($data['reason'] ?? null) ? trim((string) $data['reason']) : 'Removed');
            $finding->delete();

            $page->forceFill(['search_text' => $this->searchText($page)])->save();
            $this->bumpRevision($page, $request->user(), 'Finding removed');

            return $page;
        });

        return redirect(route('projects.docs.show', [$project, $page]).'#findings')
            ->with('status', 'Finding removed.');
    }

    /** @return array<string, mixed> */
    private function validatePage(Request $request, bool $updating = false): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:200'],
            'summary' => ['nullable', 'string', 'max:500'],
            'category' => ['required', Rule::enum(DocCategory::class)],
            'publication_status' => ['required', Rule::enum(PublicationStatus::class)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'doc_document' => ['required', 'string', 'max:200000'],
            'rev_note' => ['nullable', 'string', 'max:500'],
        ];
        if ($updating) {
            $rules['doc_version'] = ['required', 'integer', 'min:1'];
        }

        return $request->validate($rules);
    }

    /**
     * @return array{ok: bool, document?: array<string, mixed>, error?: string}
     *                                                                          The validator result verbatim: failures carry the specific
     *                                                                          block/limit/recovery message, never a generic fallback.
     */
    private function validateDocument(Request $request, Project $project, string $json, ?int $ignoreDocId = null): array
    {
        $decoded = json_decode($json, true);

        return DocDocument::validate($decoded, $project, $ignoreDocId);
    }

    private function resolve(Project $project, int $doc, bool $withTrashed = false): DocPage
    {
        $page = $withTrashed
            ? DocPage::withTrashed()->find($doc)
            : DocPage::find($doc);
        abort_if($page === null, 404);
        $this->assertBelongsToProject($project, $page);

        return $page;
    }

    /**
     * Re-read the page under a write lock inside a transaction, so a
     * version check followed by a save is atomic: a concurrent
     * writer waits, then sees the bumped revision.
     */
    private function lockedPage(Project $project, int $doc): DocPage
    {
        $page = DocPage::whereKey($doc)->lockForUpdate()->first();
        abort_if($page === null, 404);
        $this->assertBelongsToProject($project, $page);

        return $page;
    }

    /**
     * Append a revision snapshot of the (already saved) locked page
     * and advance its counter. Must run inside the locking
     * transaction so page and revision commit together.
     */
    private function bumpRevision(DocPage $page, ?User $user, ?string $note): int
    {
        $page->forceFill(['current_rev' => $page->current_rev + 1])->save();

        DocRevision::create([
            'doc_page_id' => $page->id,
            'rev' => $page->current_rev,
            'title' => $page->title,
            'summary' => $page->summary,
            'category' => $page->category->value,
            'publication_status' => $page->publication_status->value,
            'body' => $page->body,
            'author_id' => $user?->id,
            'note' => $note,
            'created_at' => now(),
        ]);

        return $page->current_rev;
    }

    private function denyDraft(Request $request, Project $project, DocPage $page): void
    {
        if (! $page->isPublished() && ! $request->user()?->can('update', $project)) {
            abort(404);
        }
    }

    /** @return Collection<int, ProjectMedia> */
    private function libraryImages(Project $project)
    {
        return $project->media()->where('kind', ProjectMedia::KIND_IMAGE)->orderBy('id')->get();
    }

    /** @return array{pages: Collection, artifacts: Collection, evidence: Collection, images: Collection} */
    private function refTargets(Project $project): array
    {
        return [
            'pages' => $project->docPages()->orderBy('title')->get(['id', 'title']),
            'artifacts' => ProjectArtifact::where('project_id', $project->id)->orderBy('id')->get(['id', 'original_name']),
            'evidence' => EvidenceSource::whereIn('repository_id', $project->repositories()->pluck('id'))
                ->orderBy('path')->limit(200)->get(['id', 'path']),
            'images' => $project->media()->where('kind', ProjectMedia::KIND_IMAGE)->orderBy('id')->get(['id', 'title', 'original_name']),
        ];
    }

    /** @return Collection<int, DocPage> */
    private function linkTargets(Project $project, ?int $ignoreDocId)
    {
        return $project->docPages()
            ->when($ignoreDocId !== null, fn ($query) => $query->where('id', '!=', $ignoreDocId))
            ->orderBy('category')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'title', 'category']);
    }

    private function searchText(DocPage $page): string
    {
        $statements = $page->findings()->pluck('statement')->all();

        return trim(implode("\n\n", array_filter([
            $page->title,
            (string) $page->summary,
            DocDocument::toPlainText(is_array($page->body) ? $page->body : null),
            implode("\n", $statements),
        ])));
    }

    /**
     * Drop blank picker rows (kind "None") and rows flagged for
     * removal before reference validation.
     *
     * @return list<array<string, mixed>>
     */
    private function keptRefs(mixed $refs): array
    {
        if (! is_array($refs)) {
            return [];
        }

        return array_values(array_filter($refs, fn ($ref): bool => is_array($ref)
            && ($ref['kind'] ?? '') !== ''
            && empty($ref['remove'])));
    }

    /**
     * Clean finding references. Kinds: page {doc_id, anchor?},
     * artifact {artifact_id}, evidence {source_id}, image
     * {media_id, marker?, placement?}, url {url}. Same-project ids
     * only. placement is the 1-based image-block ordinal on the
     * finding's page, so two uses of one photo stay distinct.
     *
     * @return list<array<string, mixed>>|null
     */
    private function cleanFindingRefs(Project $project, mixed $refs): ?array
    {
        if ($refs === null || $refs === []) {
            return [];
        }
        if (! is_array($refs)) {
            return null;
        }

        $clean = [];
        foreach ($refs as $ref) {
            if (! is_array($ref)) {
                return null;
            }
            $kind = $ref['kind'] ?? null;
            $entry = match ($kind) {
                'page' => isset($ref['doc_id']) && is_numeric($ref['doc_id'])
                    && (! isset($ref['anchor']) || (is_string($ref['anchor']) && preg_match('/^[a-z0-9-]{1,80}$/', $ref['anchor'])))
                    ? ['kind' => 'page', 'doc_id' => (int) $ref['doc_id']] + (isset($ref['anchor']) ? ['anchor' => $ref['anchor']] : []) : null,
                'artifact' => isset($ref['artifact_id']) && is_numeric($ref['artifact_id'])
                    ? ['kind' => 'artifact', 'artifact_id' => (int) $ref['artifact_id']] : null,
                'evidence' => isset($ref['source_id']) && is_numeric($ref['source_id'])
                    ? ['kind' => 'evidence', 'source_id' => (int) $ref['source_id']] : null,
                'image' => isset($ref['media_id']) && is_numeric($ref['media_id'])
                    && (! isset($ref['marker']) || (is_numeric($ref['marker']) && $ref['marker'] >= 1 && $ref['marker'] <= DocDocument::MAX_MARKERS))
                    && (! isset($ref['placement']) || (is_numeric($ref['placement']) && $ref['placement'] >= 1 && $ref['placement'] <= DocDocument::MAX_BLOCKS))
                    ? ['kind' => 'image', 'media_id' => (int) $ref['media_id']]
                        + (isset($ref['marker']) ? ['marker' => (int) $ref['marker']] : [])
                        + (isset($ref['placement']) ? ['placement' => (int) $ref['placement']] : []) : null,
                'url' => isset($ref['url']) && is_string($ref['url']) && StoryRenderer::safeUrl($ref['url'])
                    ? ['kind' => 'url', 'url' => $ref['url']] : null,
                default => null,
            };
            if ($entry === null) {
                return null;
            }
            $clean[] = $entry;
        }

        // Same-project scope for every id reference; URLs stay as
        // validated http(s) links.
        $docIds = array_unique(array_column(array_filter($clean, fn ($r): bool => $r['kind'] === 'page'), 'doc_id'));
        if ($docIds !== [] && DocPage::where('project_id', $project->id)->whereIn('id', $docIds)->count() !== count($docIds)) {
            return null;
        }
        $artifactIds = array_unique(array_column(array_filter($clean, fn ($r): bool => $r['kind'] === 'artifact'), 'artifact_id'));
        if ($artifactIds !== [] && ProjectArtifact::where('project_id', $project->id)->whereIn('id', $artifactIds)->count() !== count($artifactIds)) {
            return null;
        }
        $sourceIds = array_unique(array_column(array_filter($clean, fn ($r): bool => $r['kind'] === 'evidence'), 'source_id'));
        if ($sourceIds !== []) {
            $repositoryIds = $project->repositories()->pluck('id')->all();
            if ($repositoryIds === [] || EvidenceSource::whereIn('repository_id', $repositoryIds)->whereIn('id', $sourceIds)->count() !== count($sourceIds)) {
                return null;
            }
        }
        $mediaIds = array_unique(array_column(array_filter($clean, fn ($r): bool => $r['kind'] === 'image'), 'media_id'));
        if ($mediaIds !== [] && ProjectMedia::where('project_id', $project->id)->where('kind', ProjectMedia::KIND_IMAGE)->whereIn('id', $mediaIds)->count() !== count($mediaIds)) {
            return null;
        }

        return $clean;
    }
}
