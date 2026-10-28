<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscussionMute extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'discussion_post_id',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<DiscussionPost, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(DiscussionPost::class, 'discussion_post_id');
    }
}
