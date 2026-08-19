<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CalculatorTrait extends Model
{
    protected $fillable = [
        'key',
        'source_path',
        'source_hash',
        'source_id',
        'kind',
        'name',
        'name_translations',
        'description',
        'description_translations',
        'description_macro',
        'description_macro_translations',
        'effects',
        'effects_translations',
        'role',
        'job_id',
        'job_name',
        'job_abbreviation',
        'unlock_level',
        'value',
        'icon_id',
        'icon_file',
        'icon_url',
        'class_job_category_id',
        'source_class_job_id',
        'is_phantom_trait',
        'source_payload',
        'localized_payloads',
        'is_active',
    ];

    protected $casts = [
        'source_id' => 'integer',
        'name_translations' => 'array',
        'description_translations' => 'array',
        'description_macro_translations' => 'array',
        'effects' => 'array',
        'effects_translations' => 'array',
        'job_id' => 'integer',
        'unlock_level' => 'integer',
        'value' => 'integer',
        'icon_id' => 'integer',
        'class_job_category_id' => 'integer',
        'source_class_job_id' => 'integer',
        'is_phantom_trait' => 'boolean',
        'source_payload' => 'array',
        'localized_payloads' => 'array',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForJob(Builder $query, string $job): Builder
    {
        return $query->where('job_abbreviation', strtoupper($job));
    }

    public function scopeUnlockedAt(Builder $query, int $level): Builder
    {
        return $query->where('unlock_level', '<=', $level);
    }

    public function scopePhantom(Builder $query): Builder
    {
        return $query->where('is_phantom_trait', true);
    }

    public function localizedName(?string $locale = null): string
    {
        return $this->localizedString('name_translations', $locale) ?? $this->name;
    }

    public function localizedDescription(?string $locale = null): ?string
    {
        return $this->localizedString('description_translations', $locale) ?? $this->description;
    }

    /**
     * @return array<int, string>
     */
    public function localizedEffects(?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $translations = $this->effects_translations ?? [];

        return $translations[$locale] ?? $translations['en'] ?? $this->effects ?? [];
    }

    private function localizedString(string $attribute, ?string $locale): ?string
    {
        $locale ??= app()->getLocale();
        $values = $this->{$attribute} ?? [];

        return $values[$locale] ?? $values['en'] ?? null;
    }
}
