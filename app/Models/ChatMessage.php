<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChatMessage extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'room',
        'project_id',
        'user_id',
        'body',
        'client_key',
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return MorphMany<Report, $this> */
    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'reportable');
    }

    public static function loungeRoom(): string
    {
        return 'lounge';
    }

    public static function projectRoom(Project $project): string
    {
        // Dots, not colons: channel patterns use dots as separators, so the
        // room doubles as the channel suffix in chat.{room}.
        return 'project.'.$project->id;
    }

    public function channel(): string
    {
        return 'chat.'.$this->room;
    }
}
