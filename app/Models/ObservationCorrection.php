<?php

namespace App\Models;

use App\Enums\CorrectionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObservationCorrection extends Model
{
    use HasFactory;

    protected $fillable = [
        'observation_id',
        'user_id',
        'body',
        'status',
    ];

    protected function casts(): array
    {
        return ['status' => CorrectionStatus::class];
    }

    /** @return BelongsTo<Observation, $this> */
    public function observation(): BelongsTo
    {
        return $this->belongsTo(Observation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
