<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiBudgetCounter extends Model
{
    protected $fillable = ['user_id', 'day', 'requests', 'tokens'];

    protected function casts(): array
    {
        return ['day' => 'date'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
