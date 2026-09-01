<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'style',
        'story',
        'script',
        'screenplay',
        'current_step',
        'status',
        'cover_image_url',
        'share_token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(Scene::class)->orderBy('order_index');
    }

    public function shots(): HasMany
    {
        return $this->hasMany(Shot::class)->orderBy('order_index');
    }

    public function characters(): HasMany
    {
        return $this->hasMany(Character::class)->orderBy('order_index');
    }

    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class)->orderBy('order_index');
    }

    public function objects(): HasMany
    {
        return $this->hasMany(ProjectObject::class)->orderBy('order_index');
    }

    public function generationJobs(): HasMany
    {
        return $this->hasMany(GenerationJob::class);
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class)->orderBy('episode_number');
    }

    public function systemErrorLogs(): HasMany
    {
        return $this->hasMany(SystemErrorLog::class);
    }
}
