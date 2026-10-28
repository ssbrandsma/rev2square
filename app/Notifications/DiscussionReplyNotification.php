<?php

namespace App\Notifications;

use App\Models\DiscussionPost;
use App\Services\Discussion\Markup;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class DiscussionReplyNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly DiscussionPost $reply,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $thread = $this->reply->thread();
        $project = $thread->project;

        return [
            'kind' => 'discussion_reply',
            'title' => "{$this->reply->author->name} replied in {$project->title}",
            'body' => Markup::excerpt($this->reply->body),
            'url' => route('projects.discussion.show', [$project, $thread]).'#reply-'.$this->reply->id,
            'project_id' => $project->id,
            'thread_id' => $thread->id,
            'reply_id' => $this->reply->id,
        ];
    }
}
