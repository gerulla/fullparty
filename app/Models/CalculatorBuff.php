<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CalculatorBuff extends Model
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
        'effects',
        'effects_translations',
        'classification',
        'icon_id',
        'icon_file',
        'icon_url',
        'max_stacks',
        'status_category_id',
        'target_type',
        'can_dispel',
        'can_remove_manually',
        'is_permanent',
        'inflicted_by_actor',
        'party_list_priority',
        'parameter_effect',
        'parameter_modifier',
        'class_job_category_id',
        'source_abilities',
        'source_abilities_translations',
        'source_payload',
        'localized_payloads',
        'is_active',
    ];

    protected $casts = [
        'source_id' => 'integer',
        'name_translations' => 'array',
        'description_translations' => 'array',
        'effects' => 'array',
        'effects_translations' => 'array',
        'icon_id' => 'integer',
        'max_stacks' => 'integer',
        'status_category_id' => 'integer',
        'target_type' => 'integer',
        'can_dispel' => 'boolean',
        'can_remove_manually' => 'boolean',
        'is_permanent' => 'boolean',
        'inflicted_by_actor' => 'boolean',
        'party_list_priority' => 'integer',
        'parameter_effect' => 'integer',
        'parameter_modifier' => 'integer',
        'class_job_category_id' => 'integer',
        'source_abilities' => 'array',
        'source_abilities_translations' => 'array',
        'source_payload' => 'array',
        'localized_payloads' => 'array',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeBuffs(Builder $query): Builder
    {
        return $query->where('classification', 'buff');
    }

    public function scopeDebuffs(Builder $query): Builder
    {
        return $query->where('classification', 'debuff');
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
