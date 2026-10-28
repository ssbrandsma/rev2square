<?php

namespace App\Notifications;

use App\Models\DiscussionPost;
use App\Services\Discussion\Markup;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class FollowedPostNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly DiscussionPost $thread,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $project = $this->thread->project;

        return [
            'kind' => 'followed_post',
            'title' => "New discussion on {$project->title}",
            'body' => "{$this->thread->author->name}: ".Markup::excerpt($this->thread->body),
            'url' => route('projects.discussion.show', [$project, $this->thread]),
            'project_id' => $project->id,
            'thread_id' => $thread->id,
        ];
    }
}
