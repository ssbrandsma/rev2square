<?php

namespace App\Events;

use App\Models\ChatMessage;
use App\Services\Discussion\Markup;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class NewChatMessage implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly ChatMessage $message,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->message->channel());
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $this->message->loadMissing('author');

        return [
            'id' => $this->message->id,
            'room' => $this->message->room,
            'client_key' => $this->message->client_key,
            'body_html' => Markup::render($this->message->body),
            'author' => [
                'id' => $this->message->author->id,
                'name' => $this->message->author->name,
                'initials' => $this->message->author->initials(),
                'profile_url' => route('users.show', $this->message->author),
                'is_maintainer' => $this->message->project !== null
                    && $this->message->project->managedBy($this->message->author),
            ],
            'created_at' => $this->message->created_at->toIso8601String(),
            'created_human' => $this->message->created_at->diffForHumans(),
        ];
    }
}
