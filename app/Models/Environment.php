<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Environment extends Model
{
    protected $fillable = [
        'project_id',
        'order_index',
        'name',
        'type',
        'time_of_day',
        'description',
        'appearance',
        'lighting',
        'mood',
        'importance',
        'status',
        'image_status',
        'prompt',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(EnvironmentAsset::class);
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(Scene::class);
    }

    public function shots(): HasMany
    {
        return $this->hasMany(Shot::class);
    }
}
