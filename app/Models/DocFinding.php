<?php

namespace App\Models;

use App\Enums\DocFindingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocFinding extends Model
{
    protected $fillable = [
        'doc_page_id', 'rev', 'status', 'statement', 'refs',
        'measurement_notes', 'status_changed_by_id', 'status_changed_at',
        'status_change_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocFindingStatus::class,
            'refs' => 'array',
            'status_changed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DocPage, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(DocPage::class, 'doc_page_id');
    }

    /** @return BelongsTo<User, $this> */
    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by_id');
    }
}
