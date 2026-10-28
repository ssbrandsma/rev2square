<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'hardware_revision',
        'firmware_version',
        'notes',
    ];

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function displayLabel(): string
    {
        return trim(collect([
            $this->device?->displayName(),
            $this->hardware_revision ? 'rev '.$this->hardware_revision : null,
            $this->firmware_version ? 'fw '.$this->firmware_version : null,
        ])->filter()->implode(' · ')) ?: 'Variant #'.$this->id;
    }
}
