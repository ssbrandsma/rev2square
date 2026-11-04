<?php

namespace App\Notifications;

use App\Models\DiscussionPost;
use App\Services\Discussion\Markup;

class DiscussionReplyNotification extends DatabaseNotification
{
    public function __construct(
        private readonly DiscussionPost $reply,
    ) {}

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
