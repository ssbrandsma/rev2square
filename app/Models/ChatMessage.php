<?php

namespace App\Models;

use App\Services\Realtime\ChannelNames;
use App\Services\Realtime\RealtimeEpoch;
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

    public static function loungeChannel(): string
    {
        return ChannelNames::lounge(RealtimeEpoch::current());
    }

    /**
     * Retract/report/remove endpoints for live-rendered messages (P2-6).
     * URLs are not secrets: the destroy/report routes authorize every
     * request server-side. The lounge has no maintainer Remove action.
     *
     * @return array{retract_url: ?string, report_url: ?string, remove_url: ?string}
     */
    public function actionUrls(): array
    {
        if ($this->project_id === null) {
            return [
                'retract_url' => route('lounge.destroy', $this),
                'report_url' => route('lounge.report', $this),
                'remove_url' => null,
            ];
        }

        $this->loadMissing('project');

        // Orphaned rows (project deleted) offer no actions: every control
        // would 404, so live rendering shows none.
        if ($this->project === null) {
            return ['retract_url' => null, 'report_url' => null, 'remove_url' => null];
        }

        return [
            'retract_url' => route('projects.chat.destroy', [$this->project, $this]),
            'report_url' => route('projects.chat.report', [$this->project, $this]),
            'remove_url' => route('projects.chat.destroy', [$this->project, $this]),
        ];
    }

    public function channel(): string
    {
        if ($this->project_id === null) {
            return self::loungeChannel();
        }

        $this->loadMissing('project');

        // A message whose project vanished addresses a channel nobody can
        // join; broadcasting there is a harmless no-op.
        if ($this->project === null) {
            return 'chat.project.unknown.0.'.RealtimeEpoch::current();
        }

        return $this->project->chatChannel();
    }
}
