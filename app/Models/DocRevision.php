<?php

namespace App\Models;

use App\Enums\DocCategory;
use App\Enums\PublicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class DocRevision extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'doc_page_id', 'rev', 'title', 'summary', 'category',
        'publication_status', 'body', 'author_id', 'note', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'category' => DocCategory::class,
            'publication_status' => PublicationStatus::class,
            'body' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Revisions are immutable: citations pin (page, rev), so an
        // edit or delete would silently rewrite cited evidence.
        static::updating(fn (): never => throw new RuntimeException('Doc revisions are immutable.'));
        static::deleting(fn (): never => throw new RuntimeException('Doc revisions are immutable.'));
    }

    /** @return BelongsTo<DocPage, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(DocPage::class, 'doc_page_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
