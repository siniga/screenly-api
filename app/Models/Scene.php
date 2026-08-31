<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Scene extends Model
{
    protected $fillable = [
        'project_id',
        'environment_id',
        'scene_number',
        'order_index',
        'title',
        'location',
        'time_of_day',
        'description',
        'mood',
        'visual_style',
        'status',
        'generation_error',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function shots(): HasMany
    {
        return $this->hasMany(Shot::class)->orderBy('order_index');
    }

    public function characters(): BelongsToMany
    {
        return $this->belongsToMany(Character::class, 'scene_character')->withTimestamps();
    }
}
