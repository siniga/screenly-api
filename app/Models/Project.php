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
        'style_prompt',
        'style_meta',
        'style_reference_url',
        'story',
        'script',
        'screenplay',
        'current_step',
        'status',
        'cover_image_url',
        'share_token',
    ];

    protected $casts = [
        'style_meta' => 'array',
    ];

    public function styleFamily(): string
    {
        $family = strtolower(trim((string) data_get($this->style_meta, 'family')));
        if ($family !== '') {
            return $family;
        }

        $style = strtolower((string) $this->style);
        if (str_contains($style, 'cartoon')) {
            return 'cartoon';
        }
        if (str_contains($style, 'anime')) {
            return 'anime';
        }
        if (str_contains($style, 'sketch')) {
            return 'storyboard_sketch';
        }
        if (str_contains($style, 'commercial') || str_contains($style, 'advertis')) {
            return 'commercial_advertising';
        }
        if (str_contains($style, 'music')) {
            return 'music_video';
        }
        if (str_contains($style, 'luxury')) {
            return 'luxury_brand';
        }
        if (str_contains($style, 'document')) {
            return 'documentary';
        }

        return 'cinematic_realistic';
    }

    public function isCartoonStyle(): bool
    {
        return $this->styleFamily() === 'cartoon';
    }

    public function isIllustratedStyle(): bool
    {
        return in_array($this->styleFamily(), ['cartoon', 'anime', 'storyboard_sketch'], true);
    }

    public function lockedLookPhrase(): string
    {
        return match ($this->styleFamily()) {
            'cartoon' => 'locked cartoon style',
            'anime' => 'locked anime style',
            'storyboard_sketch' => 'locked storyboard-sketch style',
            default => 'locked visual style',
        };
    }

    public function generationStylePrompt(?string $fallback = null): ?string
    {
        if (filled($this->style_prompt)) {
            return trim((string) $this->style_prompt);
        }

        $requestStyle = is_string($fallback) ? trim($fallback) : '';
        if ($requestStyle !== '') {
            return $requestStyle;
        }

        return filled($this->style) ? trim((string) $this->style) : null;
    }

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

    public function storyAnalyses(): HasMany
    {
        return $this->hasMany(ProjectStoryAnalysis::class);
    }

    public function systemErrorLogs(): HasMany
    {
        return $this->hasMany(SystemErrorLog::class);
    }
}
