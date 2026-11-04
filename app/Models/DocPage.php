<?php

namespace App\Models;

use App\Enums\DocCategory;
use App\Enums\PublicationStatus;
use App\Services\Docs\DocDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocPage extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'project_id', 'category', 'title', 'summary', 'publication_status',
        'sort_order', 'current_rev', 'body', 'search_text',
    ];

    protected function casts(): array
    {
        return [
            'category' => DocCategory::class,
            'publication_status' => PublicationStatus::class,
            'body' => 'array',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<DocRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(DocRevision::class)->orderBy('rev');
    }

    /** @return HasMany<DocFinding, $this> */
    public function findings(): HasMany
    {
        return $this->hasMany(DocFinding::class)->orderBy('id');
    }

    public function isPublished(): bool
    {
        return $this->publication_status === PublicationStatus::Published;
    }

    /** Pages a viewer may see: published, plus drafts for managers. */
    public function scopeReadable(Builder $query, Project $project, ?User $viewer): Builder
    {
        if ($viewer !== null && $viewer->can('update', $project)) {
            return $query;
        }

        return $query->where('doc_pages.publication_status', PublicationStatus::Published->value);
    }

    /** @return list<int> media ids referenced by the current body. */
    public function mediaIds(): array
    {
        return DocDocument::mediaIds(is_array($this->body) ? $this->body : null);
    }
}
