<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemErrorLog extends Model
{
    protected $fillable = [
        'user_id',
        'project_id',
        'source',
        'level',
        'exception_class',
        'message',
        'code',
        'file',
        'line',
        'trace',
        'http_method',
        'http_path',
        'status_code',
        'user_message',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'line' => 'integer',
            'status_code' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
