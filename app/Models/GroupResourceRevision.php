<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupResourceRevision extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'editor' => 'array', 'published_at' => 'datetime'];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(GroupResource::class, 'resource_id');
    }
}
