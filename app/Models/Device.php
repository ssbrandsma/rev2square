<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    use HasFactory;

    protected $fillable = [
        'manufacturer',
        'model',
        'category',
        'description',
    ];

    /** @return HasMany<DeviceVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(DeviceVariant::class);
    }

    /** @return BelongsToMany<Project, $this> */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_devices')
            ->withPivot('device_variant_id');
    }

    public function displayName(): string
    {
        return trim(($this->manufacturer ? $this->manufacturer.' ' : '').$this->model);
    }
}
