<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShotImage extends Model
{
    protected $fillable = [
        'shot_id',
        'version_number',
        'image_url',
        'thumbnail_url',
        'prompt',
        'is_approved',
        'status',
        'generation_time_ms',
    ];

    protected function casts(): array
    {
        return [
            'is_approved' => 'boolean',
        ];
    }

    public function shot(): BelongsTo
    {
        return $this->belongsTo(Shot::class);
    }
}
