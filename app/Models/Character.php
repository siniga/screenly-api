<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Character extends Model
{
    protected $fillable = [
        'project_id',
        'order_index',
        'name',
        'role',
        'gender',
        'age_range',
        'ethnicity',
        'description',
        'personality',
        'appearance',
        'wardrobe',
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
        return $this->hasMany(CharacterAsset::class);
    }

    public function scenes(): BelongsToMany
    {
        return $this->belongsToMany(Scene::class, 'scene_character')->withTimestamps();
    }

    public function shots(): BelongsToMany
    {
        return $this->belongsToMany(Shot::class, 'shot_character')
            ->withPivot('character_asset_id')
            ->withTimestamps();
    }
}
