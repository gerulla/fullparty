<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CalculatorAction extends Model
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
        'is_phantom_action',
        'unlock_level',
        'icon_id',
        'icon_file',
        'icon_url',
        'action_category_id',
        'action_category_name',
        'attack_type_id',
        'attack_type_name',
        'timing_cast_seconds',
        'timing_recast_seconds',
        'timing_extra_cast_seconds',
        'timing_cooldown_group',
        'timing_additional_cooldown_group',
        'timing_max_charges',
        'cost_primary_type_id',
        'cost_primary_value',
        'cost_secondary_type_id',
        'cost_secondary_value',
        'range_target_yalms',
        'range_effect_yalms',
        'range_cast_type',
        'targeting_self',
        'targeting_party',
        'targeting_alliance',
        'targeting_hostile',
        'targeting_ally',
        'targeting_own_pet',
        'targeting_party_pet',
        'targeting_is_area',
        'targeting_dead_target_behavior',
        'targeting_requires_line_of_sight',
        'targeting_requires_facing_target',
        'combo_previous_action_id',
        'combo_preserves_combo',
        'status_gain_self_id',
        'status_gain_self_name',
        'status_gain_self_description',
        'status_gain_self_icon_id',
        'status_gain_self_max_stacks',
        'status_proc_id',
        'status_proc_status_id',
        'status_proc_status_name',
        'status_proc_status_description',
        'status_proc_status_icon_id',
        'status_proc_status_max_stacks',
        'metadata_aspect_id',
        'metadata_behavior_type',
        'metadata_class_job_category_id',
        'metadata_source_class_job_id',
        'metadata_is_role_action',
        'metadata_is_player_action',
        'metadata_is_derived_action',
        'metadata_equivalence_group',
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
        'is_phantom_action' => 'boolean',
        'unlock_level' => 'integer',
        'icon_id' => 'integer',
        'action_category_id' => 'integer',
        'attack_type_id' => 'integer',
        'timing_cast_seconds' => 'float',
        'timing_recast_seconds' => 'float',
        'timing_extra_cast_seconds' => 'float',
        'timing_cooldown_group' => 'integer',
        'timing_additional_cooldown_group' => 'integer',
        'timing_max_charges' => 'integer',
        'cost_primary_type_id' => 'integer',
        'cost_primary_value' => 'integer',
        'cost_secondary_type_id' => 'integer',
        'cost_secondary_value' => 'integer',
        'range_target_yalms' => 'integer',
        'range_effect_yalms' => 'integer',
        'range_cast_type' => 'integer',
        'targeting_self' => 'boolean',
        'targeting_party' => 'boolean',
        'targeting_alliance' => 'boolean',
        'targeting_hostile' => 'boolean',
        'targeting_ally' => 'boolean',
        'targeting_own_pet' => 'boolean',
        'targeting_party_pet' => 'boolean',
        'targeting_is_area' => 'boolean',
        'targeting_dead_target_behavior' => 'integer',
        'targeting_requires_line_of_sight' => 'boolean',
        'targeting_requires_facing_target' => 'boolean',
        'combo_previous_action_id' => 'integer',
        'combo_preserves_combo' => 'boolean',
        'status_gain_self_id' => 'integer',
        'status_gain_self_icon_id' => 'integer',
        'status_gain_self_max_stacks' => 'integer',
        'status_proc_id' => 'integer',
        'status_proc_status_id' => 'integer',
        'status_proc_status_icon_id' => 'integer',
        'status_proc_status_max_stacks' => 'integer',
        'metadata_aspect_id' => 'integer',
        'metadata_behavior_type' => 'integer',
        'metadata_class_job_category_id' => 'integer',
        'metadata_source_class_job_id' => 'integer',
        'metadata_is_role_action' => 'boolean',
        'metadata_is_player_action' => 'boolean',
        'metadata_is_derived_action' => 'boolean',
        'metadata_equivalence_group' => 'integer',
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

    public function scopeGcd(Builder $query): Builder
    {
        return $query->whereIn('action_category_name', ['Weaponskill', 'Spell']);
    }

    public function scopeOgcd(Builder $query): Builder
    {
        return $query->where('action_category_name', 'Ability');
    }

    public function scopePhantom(Builder $query): Builder
    {
        return $query->where('is_phantom_action', true);
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
