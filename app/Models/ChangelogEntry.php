<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ChangelogEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['translations' => 'array', 'is_published' => 'boolean', 'published_at' => 'datetime', 'revision' => 'integer'];
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true)->whereNotNull('published_at');
    }
}
