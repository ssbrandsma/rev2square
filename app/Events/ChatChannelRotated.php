<?php

namespace App\Events;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Control event broadcast on a superseded channel name, telling live
 * clients to rejoin under the current name. Carries no private content:
 * only the new channel name. Sent to every subscriber including the
 * actor (broadcast to all, never toOthers).
 */
class ChatChannelRotated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly string $oldChannel,
        public readonly string $newChannel,
        public readonly bool $presence = false,
    ) {}

    public function broadcastOn(): PrivateChannel|PresenceChannel
    {
        return $this->presence
            ? new PresenceChannel($this->oldChannel)
            : new PrivateChannel($this->oldChannel);
    }

    public function broadcastAs(): string
    {
        return 'channel.rotated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['channel' => $this->newChannel];
    }
}
