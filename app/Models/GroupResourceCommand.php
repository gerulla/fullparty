<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupResourceCommand extends Model
{
    public const MAX_LINK_BUTTONS = 5;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['embed' => 'array', 'enabled' => 'boolean'];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(GroupResource::class, 'resource_id');
    }
}
