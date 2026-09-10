<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupResourceImage extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['path'];

    public function resource(): BelongsTo
    {
        return $this->belongsTo(GroupResource::class, 'resource_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
