<?php

namespace App\Services\Agent;

use App\Enums\PublicationStatus;
use App\Models\DocFindingEvent;
use App\Models\DocPage;
use App\Models\ProjectMedia;
use App\Services\Docs\DocDocument;
use App\Support\Like;
use Illuminate\Support\Str;

/**
 * Bounded documentation tools: list, search, and read project
 * guides. Every call re-checks project view access and draft
 * visibility at execution time; drafts and deleted pages are
 * invisible to readers, and cross-project ids never resolve.
 *
 * Citation format: [D{docId}@{rev}] with an optional #anchor
 * fragment, e.g. [D12@3#step-2]. The revision pins the cited
 * evidence: later edits cannot silently change what was cited.
 * Anchors address sections, steps, images, markers, and findings
 * (see DocDocument::anchors; findings use #finding-{id}).
 *
 * Text only, by design: captions, alt text, annotation legends,
 * and finding statuses are author-written text and safe to send.
 * Image pixels are never attached — the configured provider has
 * no image-input path, so payloads carry no bytes, base64, or
 * image URLs.
 */
final class DocsTools
{
    public const LIST = 'list_docs';

    public const SEARCH = 'search_docs';

    public const READ = 'read_doc';

    public const MAX_QUERY = 200;

    public const MAX_LIMIT = 8;

    public const MAX_READ_BYTES = 32000;

    /** @return list<string> */
    public static function names(): array
    {
        return [self::LIST, self::SEARCH, self::READ];
    }

    /** @return list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}> */
    public static function definitions(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => self::LIST,
                    'description' => 'List this project\'s documentation pages (titles, categories, revisions). Drafts appear only for managers.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'limit' => ['type' => 'integer', 'description' => 'Max pages to return (default 8).'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => self::SEARCH,
                    'description' => 'Search documentation titles, summaries, body text, marker labels, and findings. Returns snippets with pinned revisions.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'Search terms (max 200 chars).'],
                            'limit' => ['type' => 'integer', 'description' => 'Max matches to return (default 8).'],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => self::READ,
                    'description' => 'Read a documentation page: full text, image captions with annotation legends, finding statuses, and linkable anchors. Cite as [D{docId}@{rev}] with an optional #anchor.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'doc_id' => ['type' => 'integer', 'description' => 'Documentation page id.'],
                            'rev' => ['type' => 'integer', 'description' => 'Pinned revision (default: current).'],
                            'section' => ['type' => 'string', 'description' => 'Optional anchor id (e.g. step-2, img-1-marker-1) to read one slice.'],
                        ],
                        'required' => ['doc_id'],
                    ],
                ],
            ],
        ];
    }

    /** @param  array<string, mixed>|null  $args */
    public static function execute(string $name, ?array $args, ToolContext $context): ToolResult
    {
        if (! $context->user->can('view', $context->project)) {
            return ToolResult::error('forbidden', 'This project is not visible to you.');
        }

        return match ($name) {
            self::LIST => self::list($args ?? [], $context),
            self::SEARCH => self::search($args ?? [], $context),
            self::READ => self::read($args ?? [], $context),
            default => ToolResult::error('unknown_tool', "Unknown tool '{$name}'."),
        };
    }

    /** @param  array<string, mixed>  $args */
    private static function list(array $args, ToolContext $context): ToolResult
    {
        $limit = self::limit($args['limit'] ?? null);

        $pages = self::readable($context)->orderBy('category')->orderBy('sort_order')->orderBy('id')
            ->limit($limit)->get(['id', 'title', 'summary', 'category', 'current_rev', 'updated_at']);

        $results = [];
        $seen = [];
        foreach ($pages as $page) {
            $seen[] = $page->id.'@'.$page->current_rev;
            $results[] = [
                'doc_id' => $page->id,
                'title' => $page->title,
                'summary' => $page->summary,
                'category' => $page->category->label(),
                'rev' => $page->current_rev,
            ];
        }

        return ToolResult::ok(['results' => $results], [], [], $seen);
    }

    /** @param  array<string, mixed>  $args */
    private static function search(array $args, ToolContext $context): ToolResult
    {
        $query = is_string($args['query'] ?? null) ? trim((string) $args['query']) : '';
        if ($query === '') {
            return ToolResult::error('invalid_arguments', 'query is required.');
        }
        $query = mb_substr($query, 0, self::MAX_QUERY);
        $limit = self::limit($args['limit'] ?? null);
        $terms = array_values(array_filter(preg_split('/\s+/', strtolower($query)) ?: []));

        $like = Like::contains($query);
        $candidates = self::readable($context)
            ->where(function ($query) use ($like) {
                $query->where('title', 'like', $like)
                    ->orWhere('summary', 'like', $like)
                    ->orWhere('search_text', 'like', $like);
            })
            ->orderBy('id')
            ->limit(40)
            ->get(['id', 'title', 'summary', 'category', 'current_rev', 'search_text']);

        $results = [];
        $seen = [];
        foreach ($candidates as $page) {
            if (count($results) >= $limit) {
                break;
            }
            $seen[] = $page->id.'@'.$page->current_rev;
            $results[] = [
                'doc_id' => $page->id,
                'title' => $page->title,
                'category' => $page->category->label(),
                'rev' => $page->current_rev,
                'snippet' => self::snippet($page->title."\n".(string) $page->summary."\n".(string) $page->search_text, $terms),
            ];
        }

        $data = ['query' => $query, 'results' => $results];
        if ($results === []) {
            $data['hint'] = 'No documentation matched these terms.';
        }

        return ToolResult::ok($data, [], [], $seen);
    }

    /** @param  array<string, mixed>  $args */
    private static function read(array $args, ToolContext $context): ToolResult
    {
        $docId = $args['doc_id'] ?? null;
        if (is_numeric($docId)) {
            $docId = (int) $docId;
        }
        if (! is_int($docId) || $docId < 1) {
            return ToolResult::error('invalid_arguments', 'doc_id is required.');
        }

        // No publication filter here: visibility is decided per
        // requested revision below, so a published rev stays
        // readable after unpublish while draft revs never leak.
        $page = DocPage::where('doc_pages.project_id', $context->project->id)
            ->where('doc_pages.id', $docId)
            ->first();
        if ($page === null) {
            // Missing, deleted, and foreign pages share one code
            // with unauthorized revisions: existence must not leak.
            return ToolResult::error('inaccessible', "Documentation page #{$docId} is not available.");
        }

        $rev = $args['rev'] ?? $page->current_rev;
        if (is_numeric($rev)) {
            $rev = (int) $rev;
        }
        if (! is_int($rev) || $rev < 1 || $rev > $page->current_rev) {
            return ToolResult::error('unknown_revision', "Revision {$args['rev']} does not exist for page #{$docId}.");
        }

        $revision = $rev === $page->current_rev
            ? null
            : $page->revisions()->where('rev', $rev)->first();
        if ($rev !== $page->current_rev && $revision === null) {
            return ToolResult::error('unknown_revision', "Revision {$rev} does not exist for page #{$docId}.");
        }

        // Visibility follows the requested revision's own state: a
        // draft revision stays hidden from readers even after the
        // page is published. Same code as missing pages — existence
        // must not leak.
        $revStatus = $revision?->publication_status ?? $page->publication_status;
        if ($revStatus !== PublicationStatus::Published && ! $context->user->can('update', $context->project)) {
            return ToolResult::error('inaccessible', "Documentation page #{$docId} is not available.");
        }

        $document = $revision?->body ?? $page->body;
        $anchors = DocDocument::anchors(is_array($document) ? $document : null);

        $section = $args['section'] ?? null;
        if (is_string($section) && $section !== '') {
            if (! preg_match('/^[a-z0-9-]{1,80}$/', $section)) {
                return ToolResult::error('invalid_arguments', 'section is not a valid anchor id.');
            }
            $slice = self::slice($document, $section, $page, (int) $rev);
            if ($slice === null) {
                $ids = implode(', ', array_slice(array_column($anchors, 'id'), 0, 12));

                return ToolResult::error('unknown_section', "No such section '{$section}'. Known anchors: {$ids}.");
            }

            return ToolResult::ok([
                'doc_id' => $page->id,
                'title' => $revision?->title ?? $page->title,
                'rev' => $rev,
                'current_rev' => $page->current_rev,
                'section' => $section,
                'text' => substr($slice, 0, self::MAX_READ_BYTES),
                'truncated' => strlen($slice) > self::MAX_READ_BYTES,
            ], [], [], [$page->id.'@'.$rev]);
        }

        // Findings replay the event log up to the pinned revision,
        // so later status changes and deletions cannot drift in.
        $findings = DocFindingEvent::asOf($page->id, $rev);

        return ToolResult::ok([
            'doc_id' => $page->id,
            'title' => $revision !== null ? $revision->title : $page->title,
            'summary' => $revision !== null ? $revision->summary : $page->summary,
            'category' => ($revision !== null ? $revision->category : $page->category)->label(),
            'rev' => $rev,
            'current_rev' => $page->current_rev,
            'anchors' => $anchors,
            'text' => substr(self::fullText($document, $page), 0, self::MAX_READ_BYTES),
            'truncated' => strlen(self::fullText($document, $page)) > self::MAX_READ_BYTES,
            'findings' => $findings->map(fn (DocFindingEvent $event): array => [
                'id' => $event->finding_id,
                'status' => $event->status->value,
                'statement' => $event->statement,
                'measurement_notes' => $event->measurement_notes,
                'refs' => $event->refs,
                'provenance' => $event->action,
                'provenance_note' => $event->reason,
            ])->all(),
        ], [], [], [$page->id.'@'.$rev]);
    }

    private static function readable(ToolContext $context)
    {
        $query = DocPage::where('doc_pages.project_id', $context->project->id);

        if (! $context->user->can('update', $context->project)) {
            $query->where('doc_pages.publication_status', PublicationStatus::Published->value);
        }

        return $query;
    }

    private static function limit(mixed $value): int
    {
        if (is_numeric($value)) {
            return min(self::MAX_LIMIT, max(1, (int) $value));
        }

        return self::MAX_LIMIT;
    }

    /** @param  list<string>  $terms */
    private static function snippet(string $content, array $terms): string
    {
        $lower = strtolower($content);
        $pos = null;
        foreach ($terms as $term) {
            $found = strpos($lower, $term);
            if ($found !== false && ($pos === null || $found < $pos)) {
                $pos = $found;
            }
        }

        if ($pos === null) {
            return trim(substr(preg_replace('/\s+/', ' ', $content) ?? '', 0, 240));
        }

        $start = max(0, $pos - 120);
        $text = substr($content, $start, 300);

        return trim(($start > 0 ? '…' : '').preg_replace('/\s+/', ' ', $text).(strlen($content) > $start + 300 ? '…' : ''));
    }

    /**
     * Render one anchor slice as plain text with captions and
     * legends. Findings slices carry their status; nothing here is
     * an instruction to the model.
     */
    private static function slice(mixed $document, string $section, DocPage $page, int $rev): ?string
    {
        if (preg_match('/^finding-(\d+)$/', $section, $m)) {
            $event = DocFindingEvent::asOf($page->id, $rev)->firstWhere('finding_id', (int) $m[1]);

            return $event === null
                ? null
                : "[{$event->status->label()}] {$event->statement}"
                    .($event->measurement_notes ? "\nNotes: {$event->measurement_notes}" : '')
                    .($event->refs ? "\nRefs: ".json_encode($event->refs) : '')
                    .($event->action === DocFindingEvent::ACTION_LEGACY && $event->reason
                        ? "\nProvenance: legacy import — {$event->reason}"
                        : '');
        }

        $blocks = is_array($document) ? ($document['blocks'] ?? []) : [];
        $heading = 0;
        $step = 0;
        $image = 0;
        $link = 0;

        foreach ($blocks as $index => $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = $block['type'] ?? '';
            if ($type === 'heading') {
                $heading++;
                // Same id derivation as DocDocument::anchors().
                $slug = Str::slug(mb_substr((string) ($block['text'] ?? ''), 0, 60)) ?: 'section';
                if ("section-{$heading}-{$slug}" === $section) {
                    return self::sectionText($blocks, $index);
                }
            } elseif ($type === 'step') {
                $step++;
                if ("step-{$step}" === $section) {
                    $title = trim((string) ($block['title'] ?? ''));

                    return 'Step '.$step.($title !== '' ? ": {$title}" : '')."\n".(string) ($block['text'] ?? '');
                }
            } elseif ($type === 'image') {
                $image++;
                if ("img-{$image}" === $section) {
                    return self::imageText($page, $block, null);
                }
                foreach (array_values($block['markers'] ?? []) as $n => $marker) {
                    if ("img-{$image}-marker-".($n + 1) === $section) {
                        return self::imageText($page, $block, $n);
                    }
                }
            } elseif ($type === 'doc_link') {
                $link++;
                if ("link-{$link}" === $section) {
                    $target = DocPage::where('project_id', $page->project_id)
                        ->where('id', (int) ($block['doc_id'] ?? 0))->first();

                    return 'Link to: '.($target?->title ?? '(unavailable)');
                }
            }
        }

        return null;
    }

    /** @param  list<mixed>  $blocks */
    private static function sectionText(array $blocks, int $from): string
    {
        $out = [];
        for ($i = $from; $i < count($blocks); $i++) {
            $block = $blocks[$i];
            if (! is_array($block)) {
                continue;
            }
            if ($i > $from && ($block['type'] ?? '') === 'heading' && (int) ($block['level'] ?? 2) <= 2) {
                break;
            }
            $text = DocDocument::toPlainText(['version' => 1, 'blocks' => [$block]]);
            if ($text !== '') {
                $out[] = $text;
            }
        }

        return trim(implode("\n\n", $out));
    }

    /** @param  array<string, mixed>  $block */
    private static function imageText(DocPage $page, array $block, ?int $markerIndex): string
    {
        $media = ProjectMedia::where('project_id', $page->project_id)
            ->where('id', (int) ($block['media_id'] ?? 0))->first();

        $caption = trim((string) ($block['caption'] ?? '')) !== ''
            ? (string) $block['caption']
            : (string) ($media?->caption ?? '');
        $alt = trim((string) ($block['alt'] ?? '')) !== ''
            ? (string) $block['alt']
            : (string) ($media?->altText() ?? '');

        $out = 'Photograph'.($caption !== '' ? ": {$caption}" : '').($alt !== '' ? " [{$alt}]" : '');
        $markers = array_values($block['markers'] ?? []);
        if ($markerIndex !== null) {
            $marker = $markers[$markerIndex] ?? null;
            if (! is_array($marker)) {
                return $out;
            }
            $label = trim((string) (($marker['label'] ?? '').' '.($marker['note'] ?? '')));

            return $out."\nMarker ".($markerIndex + 1).($label !== '' ? ": {$label}" : '');
        }

        foreach ($markers as $n => $marker) {
            if (! is_array($marker)) {
                continue;
            }
            $label = trim((string) (($marker['label'] ?? '').' '.($marker['note'] ?? '')));
            $out .= "\nMarker ".($n + 1).($label !== '' ? ": {$label}" : '');
        }

        return $out;
    }

    private static function fullText(mixed $document, DocPage $page): string
    {
        $out = [DocDocument::toPlainText(is_array($document) ? $document : null)];

        // Captions and legends are not in the plain-text body; append
        // them so reads stay faithful to the page.
        $blocks = is_array($document) ? ($document['blocks'] ?? []) : [];
        $image = 0;
        foreach ($blocks as $block) {
            if (! is_array($block) || ($block['type'] ?? '') !== 'image') {
                continue;
            }
            $image++;
            $text = self::imageText($page, $block, null);
            if ($text !== 'Photograph') {
                $out[] = "[Image {$image}] {$text}";
            }
        }

        return trim(implode("\n\n", array_filter($out)));
    }
}
