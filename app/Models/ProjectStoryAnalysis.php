<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectStoryAnalysis extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'project_id',
        'story_hash',
        'story_version',
        'status',
        'analysis',
        'model',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'analysis' => 'array',
            'story_version' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function matchesStoryHash(string $hash): bool
    {
        return hash_equals((string) $this->story_hash, $hash);
    }
}
