<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProjectObject extends Model
{
    protected $table = 'objects';

    protected $fillable = [
        'project_id',
        'order_index',
        'name',
        'category',
        'description',
        'material',
        'color',
        'condition',
        'importance',
        'used_by',
        'notes',
        'status',
        'reference_image_url',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function shots(): BelongsToMany
    {
        return $this->belongsToMany(Shot::class, 'shot_object', 'object_id', 'shot_id')
            ->withTimestamps();
    }
}
