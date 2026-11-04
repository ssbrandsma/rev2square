<?php

namespace App\Models;

use App\Enums\DocFindingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use RuntimeException;

class DocFindingEvent extends Model
{
    public const ACTION_CREATED = 'created';

    public const ACTION_EDITED = 'edited';

    public const ACTION_STATUS = 'status';

    public const ACTION_DELETED = 'deleted';

    protected $fillable = [
        'doc_page_id', 'finding_id', 'action', 'status', 'statement',
        'refs', 'measurement_notes', 'page_rev', 'changed_by_id', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocFindingStatus::class,
            'refs' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // History is append-only: pinned citations resolve through it.
        static::updating(fn (): never => throw new RuntimeException('Finding history is immutable.'));
        static::deleting(fn (): never => throw new RuntimeException('Finding history is immutable.'));
    }

    /** @return BelongsTo<DocPage, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(DocPage::class, 'doc_page_id');
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_id');
    }

    public const ACTION_LEGACY = 'legacy';

    /**
     * One-time transition for findings that predate the event log:
     * one event per row without a creation record, capturing its
     * known state at its creation revision. Earlier transitions
     * were never recorded and are not reconstructed — the reason
     * says so. Idempotent: rows with a created/legacy event are
     * skipped, even when later events already exist.
     */
    public static function backfillLegacy(): int
    {
        $withOrigin = self::query()
            ->whereIn('action', [self::ACTION_CREATED, self::ACTION_LEGACY])
            ->distinct()->pluck('finding_id')->all();

        $orphans = DocFinding::whereNotIn('id', $withOrigin)->orderBy('id')->get();
        foreach ($orphans as $finding) {
            self::create([
                'doc_page_id' => $finding->doc_page_id,
                'finding_id' => $finding->id,
                'action' => self::ACTION_LEGACY,
                'status' => $finding->status->value,
                'statement' => $finding->statement,
                'refs' => $finding->refs,
                'measurement_notes' => $finding->measurement_notes,
                'page_rev' => $finding->rev,
                'changed_by_id' => $finding->status_changed_by_id,
                'reason' => trim(($finding->status_change_reason ? $finding->status_change_reason.' — ' : '')
                    .'Migrated existing finding; earlier transitions were not recorded.'),
            ]);
        }

        return $orphans->count();
    }

    public static function record(
        DocPage $page,
        int $findingId,
        string $action,
        DocFindingStatus $status,
        string $statement,
        ?array $refs,
        ?string $measurementNotes,
        int $pageRev,
        ?int $changedById,
        ?string $reason,
    ): self {
        return self::create([
            'doc_page_id' => $page->id,
            'finding_id' => $findingId,
            'action' => $action,
            'status' => $status->value,
            'statement' => $statement,
            'refs' => $refs,
            'measurement_notes' => $measurementNotes,
            'page_rev' => $pageRev,
            'changed_by_id' => $changedById,
            'reason' => $reason,
        ]);
    }

    /**
     * Findings as they were at page revision $rev: the latest event
     * at or below the revision per finding, excluding findings
     * deleted by then. Each entry carries its event row so views
     * render author and reason.
     *
     * @return Collection<int, self>
     */
    public static function asOf(int $pageId, int $rev): Collection
    {
        // Order by revision, not insertion: backfilled legacy rows
        // carry low revisions with high ids and must not shadow
        // real contemporaneous events.
        $events = self::where('doc_page_id', $pageId)
            ->where('page_rev', '<=', $rev)
            ->with('changedBy')
            ->orderBy('page_rev')
            ->orderBy('id')
            ->get()
            ->groupBy('finding_id');

        return $events
            ->map(fn ($group) => $group->last())
            ->reject(fn (self $event): bool => $event->action === self::ACTION_DELETED)
            ->values();
    }
}
