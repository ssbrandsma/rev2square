<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One database-managed application setting. Secrets are stored
 * encrypted and are never rendered back to any client.
 */
class AppSetting extends Model
{
    protected $fillable = ['key', 'value', 'is_secret', 'updated_by'];

    protected function casts(): array
    {
        return ['is_secret' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected static function booted(): void
    {
        // Defense in depth: a secret must never leak through accidental
        // serialization, even if a caller forgets to exclude it.
        static::retrieved(function (self $setting): void {
            if ($setting->is_secret) {
                $setting->makeHidden('value');
            }
        });
    }
}
