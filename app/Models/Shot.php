<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shot extends Model
{
    protected $fillable = [
        'project_id',
        'scene_id',
        'environment_id',
        'environment_asset_id',
        'shot_number',
        'order_index',
        'title',
        'description',
        'action',
        'dialogue',
        'shot_size',
        'camera_angle',
        'camera_movement',
        'composition',
        'lens',
        'lighting',
        'mood',
        'duration_seconds',
        'prompt',
        'composition_preset',
        'cinematography_preset',
        'lighting_preset',
        'review_status',
        'image_status',
        'generation_error',
        'storyboard_settings',
    ];

    protected function casts(): array
    {
        return [
            'storyboard_settings' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(Scene::class);
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function environmentAsset(): BelongsTo
    {
        return $this->belongsTo(EnvironmentAsset::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ShotImage::class)->orderBy('version_number');
    }

    public function characters(): BelongsToMany
    {
        return $this->belongsToMany(Character::class, 'shot_character')
            ->withPivot('character_asset_id')
            ->withTimestamps();
    }

    public function objects(): BelongsToMany
    {
        return $this->belongsToMany(ProjectObject::class, 'shot_object', 'shot_id', 'object_id')
            ->withTimestamps();
    }
}
