<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SurveyForm extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['draft' => 'array', 'revision' => 'integer', 'is_open' => 'boolean', 'is_published' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(SurveyFormVersion::class, 'published_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SurveyFormVersion::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class);
    }
}
