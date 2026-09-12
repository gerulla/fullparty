<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupResourceLibrary extends Model
{
    public const DEFAULT_VISIBILITY = 'public';

    protected $attributes = ['visibility' => self::DEFAULT_VISIBILITY];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['customization' => 'array', 'storage_used_bytes' => 'integer'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
