<?php

namespace App\Notifications;

use App\Models\DiscussionPost;
use App\Services\Discussion\Markup;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class MentionNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly DiscussionPost $post,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $thread = $this->post->thread();
        $project = $thread->project;

        return [
            'kind' => 'mention',
            'title' => "{$this->post->author->name} mentioned you in {$project->title}",
            'body' => Markup::excerpt($this->post->body),
            'url' => $this->post->isThread()
                ? route('projects.discussion.show', [$project, $thread])
                : route('projects.discussion.show', [$project, $thread]).'#reply-'.$this->post->id,
            'project_id' => $project->id,
            'thread_id' => $thread->id,
            'post_id' => $this->post->id,
        ];
    }
}
