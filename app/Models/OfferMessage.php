<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfferMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'offer_id',
        'user_id',
        'body',
    ];

    /** @return BelongsTo<ContributionOffer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(ContributionOffer::class, 'offer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
