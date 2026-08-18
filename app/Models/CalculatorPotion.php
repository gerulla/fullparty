<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CalculatorPotion extends Model
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
        'icon_id',
        'icon_file',
        'icon_url',
        'item_level',
        'can_be_high_quality',
        'stack_size',
        'rarity',
        'category_id',
        'category_name',
        'category_translations',
        'use_item_action_id',
        'use_action_id',
        'use_usable_in_battle',
        'use_minimum_level',
        'use_duration_seconds',
        'use_effect_row_id',
        'use_raw_data',
        'use_raw_data_high_quality',
        'stats',
        'stats_translations',
        'primary_stat_id',
        'primary_stat_name',
        'primary_stat_is_percentage',
        'primary_stat_normal_value',
        'primary_stat_normal_cap',
        'primary_stat_high_quality_value',
        'primary_stat_high_quality_cap',
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
        'icon_id' => 'integer',
        'item_level' => 'integer',
        'can_be_high_quality' => 'boolean',
        'stack_size' => 'integer',
        'rarity' => 'integer',
        'category_id' => 'integer',
        'category_translations' => 'array',
        'use_item_action_id' => 'integer',
        'use_action_id' => 'integer',
        'use_usable_in_battle' => 'boolean',
        'use_minimum_level' => 'integer',
        'use_duration_seconds' => 'integer',
        'use_effect_row_id' => 'integer',
        'use_raw_data' => 'array',
        'use_raw_data_high_quality' => 'array',
        'stats' => 'array',
        'stats_translations' => 'array',
        'primary_stat_id' => 'integer',
        'primary_stat_is_percentage' => 'boolean',
        'primary_stat_normal_value' => 'integer',
        'primary_stat_normal_cap' => 'integer',
        'primary_stat_high_quality_value' => 'integer',
        'primary_stat_high_quality_cap' => 'integer',
        'source_payload' => 'array',
        'localized_payloads' => 'array',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function localizedName(?string $locale = null): string
    {
        return $this->localizedString('name_translations', $locale) ?? $this->name;
    }

    public function localizedDescription(?string $locale = null): ?string
    {
        return $this->localizedString('description_translations', $locale) ?? $this->description;
    }

    public function localizedCategoryName(?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();
        $translations = $this->category_translations ?? [];

        return data_get($translations, "{$locale}.name")
            ?? data_get($translations, 'en.name')
            ?? $this->category_name;
    }

    public function localizedPrimaryStatName(?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();
        $translations = $this->stats_translations ?? [];

        return data_get($translations, "{$locale}.0.name")
            ?? data_get($translations, 'en.0.name')
            ?? $this->primary_stat_name;
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
