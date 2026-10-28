<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentMessage extends Model
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    protected $fillable = [
        'agent_conversation_id',
        'role',
        'content',
        'citations',
        'tool_trace',
        'stopped_reason',
        'token_usage',
        'duration_ms',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'citations' => 'array',
            'tool_trace' => 'array',
            'token_usage' => 'array',
        ];
    }

    /** @return BelongsTo<AgentConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AgentConversation::class, 'agent_conversation_id');
    }

    public function isUser(): bool
    {
        return $this->role === self::ROLE_USER;
    }
}
