<?php

namespace App\Services\ActivityTypes;

use App\Models\ActivityType;
use App\Models\CharacterClass;
use App\Models\PhantomJob;
use App\Support\ActivityCompositionPresets;
use App\Support\Bozja\BozjaItemCategory;
use Illuminate\Validation\ValidationException;

final class ActivityTypeDraftValidator
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function validate(array $validated): void
    {
        $name = $validated['draft_name'] ?? null;
        $layoutSchema = $validated['draft_layout_schema'] ?? null;
        $slotSchema = $validated['draft_slot_schema'] ?? null;
        $applicationSchema = $validated['draft_application_schema'] ?? null;
        $rosterSummaryPresets = $validated['draft_roster_summary_presets'] ?? [];
        $progressSchema = $validated['draft_progress_schema'] ?? null;
        $benchSize = $validated['draft_bench_size'] ?? 0;
        $progPoints = $validated['draft_prog_points'] ?? null;
        $tags = $validated['tags'] ?? null;
        $difficulty = $validated['draft_difficulty'] ?? ActivityType::DIFFICULTY_NORMAL;
        $defaultMinItemLevel = $validated['draft_default_min_item_level'] ?? null;

        if (! is_array($name) || ! array_key_exists('en', $name) || blank($name['en'])) {
            throw ValidationException::withMessages([
                'draft_name.en' => __('errors.an_english_activity_type_name_is_required'),
            ]);
        }

        if (! is_array($layoutSchema) || ! isset($layoutSchema['groups']) || ! is_array($layoutSchema['groups']) || $layoutSchema['groups'] === []) {
            throw ValidationException::withMessages([
                'draft_layout_schema.groups' => __('errors.at_least_one_slot_group_is_required'),
            ]);
        }

        foreach ($layoutSchema['groups'] as $index => $group) {
            if (! is_array($group)) {
                throw ValidationException::withMessages([
                    "draft_layout_schema.groups.$index" => __('errors.each_slot_group_must_be_an_object'),
                ]);
            }

            if (blank($group['key'] ?? null) || blank($group['size'] ?? null)) {
                throw ValidationException::withMessages([
                    "draft_layout_schema.groups.$index" => __('errors.each_slot_group_requires_a_key_and_size'),
                ]);
            }

            if (! is_numeric($group['size']) || (int) $group['size'] < 1) {
                throw ValidationException::withMessages([
                    "draft_layout_schema.groups.$index.size" => __('errors.each_slot_group_size_must_be_at_least_1'),
                ]);
            }

            $this->assertLocalizedValue($group['label'] ?? null, "draft_layout_schema.groups.$index.label");
            $this->validateCompositionHints(
                $group['composition_hints'] ?? null,
                (int) $group['size'],
                "draft_layout_schema.groups.$index.composition_hints",
            );
        }

        $this->validateSchemaFields($slotSchema, 'draft_slot_schema', schemaKind: 'slot');
        $this->validateSchemaFields($applicationSchema, 'draft_application_schema', supportsAnySelection: true, schemaKind: 'application');
        $layoutGroupKeys = collect($layoutSchema['groups'])
            ->pluck('key')
            ->filter(fn (mixed $key) => filled($key))
            ->map(fn (mixed $key) => (string) $key)
            ->values()
            ->all();

        $this->validateRosterSummaryPresets($rosterSummaryPresets, 'draft_roster_summary_presets', $layoutGroupKeys);
        $this->validateProgressSchema($progressSchema, 'draft_progress_schema');
        $this->validateBenchSize($benchSize, 'draft_bench_size');
        $this->validateProgPoints($progPoints, 'draft_prog_points');
        $this->validateTags($tags, 'tags');
        $this->validateDiscoveryMetadata($difficulty, $defaultMinItemLevel);
    }

    private function validateCompositionHints(mixed $hints, int $groupSize, string $attribute): void
    {
        if ($hints === null) {
            return;
        }

        if (! is_array($hints)) {
            throw ValidationException::withMessages([
                $attribute => __('errors.composition_hints_must_be_an_array'),
            ]);
        }

        $positions = [];

        foreach ($hints as $index => $hint) {
            if (! is_array($hint)) {
                throw ValidationException::withMessages([
                    "$attribute.$index" => __('errors.each_composition_hint_must_be_an_object'),
                ]);
            }

            $position = $hint['position'] ?? null;

            if (! is_numeric($position) || (int) $position < 1 || (int) $position > $groupSize) {
                throw ValidationException::withMessages([
                    "$attribute.$index.position" => __('errors.composition_hint_positions_must_point_to_an_existing_slot'),
                ]);
            }

            $position = (int) $position;

            if (in_array($position, $positions, true)) {
                throw ValidationException::withMessages([
                    "$attribute.$index.position" => __('errors.each_slot_position_can_only_have_one_composition_hint_object'),
                ]);
            }

            $positions[] = $position;

            if (! isset($hint['accepts']) || ! is_array($hint['accepts']) || $hint['accepts'] === []) {
                throw ValidationException::withMessages([
                    "$attribute.$index.accepts" => __('errors.each_composition_hint_requires_at_least_one_accepted_role_or_class'),
                ]);
            }

            $acceptKeys = [];

            foreach ($hint['accepts'] as $acceptIndex => $accept) {
                if (! is_array($accept)) {
                    throw ValidationException::withMessages([
                        "$attribute.$index.accepts.$acceptIndex" => __('errors.each_accepted_composition_value_must_be_an_object'),
                    ]);
                }

                $type = (string) ($accept['type'] ?? '');
                $key = (string) ($accept['key'] ?? '');

                if (! in_array($type, ['role', 'class'], true)) {
                    throw ValidationException::withMessages([
                        "$attribute.$index.accepts.$acceptIndex.type" => __('errors.composition_hint_types_must_be_role_or_class'),
                    ]);
                }

                if (blank($key) || mb_strlen($key) > 50) {
                    throw ValidationException::withMessages([
                        "$attribute.$index.accepts.$acceptIndex.key" => __('errors.composition_hint_keys_are_required_and_must_stay_short'),
                    ]);
                }

                if ($type === 'role' && ! in_array($key, ActivityCompositionPresets::validRoleKeys(), true)) {
                    throw ValidationException::withMessages([
                        "$attribute.$index.accepts.$acceptIndex.key" => __('errors.unsupported_composition_role_key'),
                    ]);
                }

                $acceptKey = "{$type}:{$key}";

                if (in_array($acceptKey, $acceptKeys, true)) {
                    throw ValidationException::withMessages([
                        "$attribute.$index.accepts.$acceptIndex.key" => __('errors.accepted_composition_values_must_be_unique_per_slot'),
                    ]);
                }

                $acceptKeys[] = $acceptKey;
            }
        }
    }

    private function validateDiscoveryMetadata(mixed $difficulty, mixed $defaultMinItemLevel): void
    {
        if (! in_array($difficulty, ActivityType::DIFFICULTIES, true)) {
            throw ValidationException::withMessages([
                'draft_difficulty' => __('errors.unsupported_activity_difficulty'),
            ]);
        }

        if (is_null($defaultMinItemLevel)) {
            return;
        }

        if (! is_numeric($defaultMinItemLevel) || (int) $defaultMinItemLevel < 1 || (int) $defaultMinItemLevel > 9999) {
            throw ValidationException::withMessages([
                'draft_default_min_item_level' => __('errors.default_minimum_item_level_must_be_a_valid_positive_number'),
            ]);
        }
    }

    private function validateBenchSize(mixed $benchSize, string $attribute): void
    {
        if (! is_numeric($benchSize) || (int) $benchSize < 0) {
            throw ValidationException::withMessages([
                $attribute => __('errors.bench_size_must_be_a_valid_non_negative_number'),
            ]);
        }
    }

    private function validateTags(mixed $tags, string $attribute): void
    {
        if (is_null($tags)) {
            return;
        }

        if (! is_array($tags)) {
            throw ValidationException::withMessages([
                $attribute => __('errors.tags_must_be_an_array'),
            ]);
        }

        $normalizedTags = collect($tags)
            ->map(fn (mixed $tag) => is_string($tag) ? trim($tag) : null)
            ->filter(fn (?string $tag) => filled($tag))
            ->values();

        if ($normalizedTags->count() !== count($tags)) {
            throw ValidationException::withMessages([
                $attribute => __('errors.tags_must_only_contain_non_empty_strings'),
            ]);
        }

        if ($normalizedTags->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                $attribute => __('errors.tags_must_be_unique'),
            ]);
        }
    }

    private function validateProgPoints(mixed $progPoints, string $attribute): void
    {
        if (is_null($progPoints)) {
            return;
        }

        if (! is_array($progPoints)) {
            throw ValidationException::withMessages([
                $attribute => __('errors.prog_points_must_be_an_array'),
            ]);
        }

        foreach ($progPoints as $index => $progPoint) {
            if (! is_array($progPoint)) {
                throw ValidationException::withMessages([
                    "$attribute.$index" => __('errors.each_prog_point_must_be_an_object'),
                ]);
            }

            if (blank($progPoint['key'] ?? null)) {
                throw ValidationException::withMessages([
                    "$attribute.$index.key" => __('errors.each_prog_point_requires_a_key'),
                ]);
            }

            $this->assertLocalizedValue($progPoint['label'] ?? null, "$attribute.$index.label");
        }
    }

    private function validateProgressSchema(mixed $progressSchema, string $attribute): void
    {
        if (! is_array($progressSchema)) {
            throw ValidationException::withMessages([
                $attribute => __('errors.progress_schema_must_be_an_object'),
            ]);
        }

        $milestones = $progressSchema['milestones'] ?? null;

        if (! is_array($milestones)) {
            throw ValidationException::withMessages([
                "$attribute.milestones" => __('errors.progress_milestones_must_be_an_array'),
            ]);
        }

        foreach ($milestones as $index => $milestone) {
            if (! is_array($milestone)) {
                throw ValidationException::withMessages([
                    "$attribute.milestones.$index" => __('errors.each_milestone_must_be_an_object'),
                ]);
            }

            if (blank($milestone['key'] ?? null)) {
                throw ValidationException::withMessages([
                    "$attribute.milestones.$index.key" => __('errors.each_milestone_requires_a_key'),
                ]);
            }

            if (! is_numeric($milestone['order'] ?? null) || (int) $milestone['order'] < 1) {
                throw ValidationException::withMessages([
                    "$attribute.milestones.$index.order" => __('errors.each_milestone_requires_a_valid_order'),
                ]);
            }

            $matcher = $milestone['fflogs_matcher'] ?? null;

            if (! is_array($matcher)) {
                throw ValidationException::withMessages([
                    "$attribute.milestones.$index.fflogs_matcher" => __('errors.each_milestone_requires_an_ff_logs_matcher'),
                ]);
            }

            $matcherType = $matcher['type'] ?? null;

            if (! in_array($matcherType, ['encounter', 'phase'], true)) {
                throw ValidationException::withMessages([
                    "$attribute.milestones.$index.fflogs_matcher.type" => __('errors.unsupported_ff_logs_matcher_type'),
                ]);
            }

            if (! is_numeric($matcher['encounter_id'] ?? null) || (int) $matcher['encounter_id'] < 1) {
                throw ValidationException::withMessages([
                    "$attribute.milestones.$index.fflogs_matcher.encounter_id" => __('errors.each_milestone_requires_a_valid_ff_logs_encounter_id'),
                ]);
            }

            if ($matcherType === 'phase' && (! is_numeric($matcher['phase_id'] ?? null) || (int) $matcher['phase_id'] < 1)) {
                throw ValidationException::withMessages([
                    "$attribute.milestones.$index.fflogs_matcher.phase_id" => __('errors.phase_milestones_require_a_valid_ff_logs_phase_id'),
                ]);
            }

            $this->assertLocalizedValue($milestone['label'] ?? null, "$attribute.milestones.$index.label");
        }
    }

    /**
     * @param  array<int, string>  $layoutGroupKeys
     */
    private function validateRosterSummaryPresets(mixed $presets, string $attribute, array $layoutGroupKeys): void
    {
        if (is_null($presets)) {
            return;
        }

        if (! is_array($presets)) {
            throw ValidationException::withMessages([
                $attribute => __('errors.roster_summary_presets_must_be_an_array'),
            ]);
        }

        $seenPresetKeys = [];

        foreach ($presets as $presetIndex => $preset) {
            if (! is_array($preset)) {
                throw ValidationException::withMessages([
                    "$attribute.$presetIndex" => __('errors.each_roster_summary_preset_must_be_an_object'),
                ]);
            }

            $presetKey = $preset['key'] ?? null;

            if (blank($presetKey)) {
                throw ValidationException::withMessages([
                    "$attribute.$presetIndex.key" => __('errors.each_roster_summary_preset_requires_a_key'),
                ]);
            }

            if (in_array($presetKey, $seenPresetKeys, true)) {
                throw ValidationException::withMessages([
                    "$attribute.$presetIndex.key" => __('errors.roster_summary_preset_keys_must_be_unique'),
                ]);
            }

            $seenPresetKeys[] = $presetKey;

            $this->assertLocalizedValue($preset['label'] ?? null, "$attribute.$presetIndex.label");

            if (isset($preset['description'])) {
                $this->assertLocalizedValue($preset['description'], "$attribute.$presetIndex.description", false);
            }

            $requirements = $preset['requirements'] ?? null;

            if (! is_array($requirements) || $requirements === []) {
                throw ValidationException::withMessages([
                    "$attribute.$presetIndex.requirements" => __('errors.each_roster_summary_preset_requires_at_least_one_requirement'),
                ]);
            }

            $seenRequirementKeys = [];

            foreach ($requirements as $requirementIndex => $requirement) {
                if (! is_array($requirement)) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex" => __('errors.each_roster_summary_requirement_must_be_an_object'),
                    ]);
                }

                $source = $requirement['source'] ?? null;
                $sourceId = $requirement['source_id'] ?? null;
                $comparison = $requirement['comparison'] ?? null;
                $targetCount = $requirement['target_count'] ?? null;
                $scopeType = $requirement['scope_type'] ?? null;
                $scopeGroupKeys = $requirement['scope_group_keys'] ?? [];

                if (! in_array($source, ['character_classes', 'phantom_jobs'], true)) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex.source" => __('errors.unsupported_roster_summary_requirement_source'),
                    ]);
                }

                if (! is_numeric($sourceId) || (int) $sourceId < 1) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex.source_id" => __('errors.each_roster_summary_requirement_requires_a_valid_source_option'),
                    ]);
                }

                $sourceExists = match ($source) {
                    'character_classes' => CharacterClass::query()->whereKey((int) $sourceId)->exists(),
                    'phantom_jobs' => PhantomJob::query()->whereKey((int) $sourceId)->exists(),
                    default => false,
                };

                if (! $sourceExists) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex.source_id" => __('errors.roster_source_missing'),
                    ]);
                }

                if (! in_array($comparison, ['at_least', 'exactly', 'at_most'], true)) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex.comparison" => __('errors.unsupported_roster_summary_comparison_mode'),
                    ]);
                }

                if (! is_numeric($targetCount) || (int) $targetCount < 1) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex.target_count" => __('errors.each_roster_summary_requirement_needs_a_target_count_of_at_least_1'),
                    ]);
                }

                if (! in_array($scopeType, ['all_slots', 'slot_group', 'slot_group_set'], true)) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex.scope_type" => __('errors.unsupported_roster_summary_scope_type'),
                    ]);
                }

                if (! is_array($scopeGroupKeys)) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex.scope_group_keys" => __('errors.roster_summary_scope_group_keys_must_be_an_array'),
                    ]);
                }

                $normalizedScopeGroupKeys = collect($scopeGroupKeys)
                    ->map(fn (mixed $key) => is_string($key) ? trim($key) : null)
                    ->filter(fn (?string $key) => filled($key))
                    ->values()
                    ->all();

                if (count($normalizedScopeGroupKeys) !== count($scopeGroupKeys)) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex.scope_group_keys" => __('errors.roster_summary_scope_group_keys_must_only_contain_non_empty_strings'),
                    ]);
                }

                if (collect($normalizedScopeGroupKeys)->duplicates()->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex.scope_group_keys" => __('errors.roster_summary_scope_group_keys_must_be_unique'),
                    ]);
                }

                $unknownScopeGroupKeys = collect($normalizedScopeGroupKeys)
                    ->reject(fn (string $groupKey) => in_array($groupKey, $layoutGroupKeys, true))
                    ->values()
                    ->all();

                if ($unknownScopeGroupKeys !== []) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex.scope_group_keys" => __('errors.roster_summary_requirements_can_only_reference_groups_defined_in_the_activity_layout'),
                    ]);
                }

                if ($scopeType === 'all_slots' && $normalizedScopeGroupKeys !== []) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex.scope_group_keys" => __('errors.all_roster_requirements_cannot_target_specific_groups'),
                    ]);
                }

                if ($scopeType === 'slot_group' && count($normalizedScopeGroupKeys) !== 1) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex.scope_group_keys" => __('errors.single_group_requirements_must_target_exactly_one_group'),
                    ]);
                }

                if ($scopeType === 'slot_group_set' && count($normalizedScopeGroupKeys) < 1) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex.scope_group_keys" => __('errors.group_set_requirements_must_target_at_least_one_group'),
                    ]);
                }

                $normalizedScopeGroupSetKey = collect($normalizedScopeGroupKeys)
                    ->sort()
                    ->values()
                    ->implode('|');

                $requirementKey = sprintf(
                    '%s:%s:%s:%s',
                    $source,
                    (int) $sourceId,
                    $scopeType,
                    $normalizedScopeGroupSetKey,
                );

                if (in_array($requirementKey, $seenRequirementKeys, true)) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex.source_id" => __('errors.each_roster_summary_requirement_must_be_unique_within_its_scope'),
                    ]);
                }

                $seenRequirementKeys[] = $requirementKey;
            }
        }
    }

    private function validateSchemaFields(
        mixed $fields,
        string $attribute,
        bool $supportsAnySelection = false,
        string $schemaKind = 'slot',
    ): void {
        if (! is_array($fields)) {
            throw ValidationException::withMessages([
                $attribute => __('errors.schema_fields_must_be_an_array'),
            ]);
        }

        foreach ($fields as $index => $field) {
            if (! is_array($field)) {
                throw ValidationException::withMessages([
                    "$attribute.$index" => __('errors.each_schema_field_must_be_an_object'),
                ]);
            }

            if (blank($field['key'] ?? null)) {
                throw ValidationException::withMessages([
                    "$attribute.$index.key" => __('errors.each_schema_field_requires_a_key'),
                ]);
            }

            if (! in_array($field['type'] ?? null, [
                'text',
                'textarea',
                'number',
                'boolean',
                'single_select',
                'multi_select',
                'holster_pair',
                'holster_pair_list',
                'url',
            ], true)) {
                throw ValidationException::withMessages([
                    "$attribute.$index.type" => __('errors.unsupported_schema_field_type'),
                ]);
            }

            $this->assertLocalizedValue($field['label'] ?? null, "$attribute.$index.label");

            if (isset($field['help_text'])) {
                $this->assertLocalizedValue($field['help_text'], "$attribute.$index.help_text", false);
            }

            $fieldType = (string) ($field['type'] ?? '');
            $source = $field['source'] ?? null;
            $acceptsAny = (bool) ($field['accepts_any'] ?? false);

            if (($fieldType === 'holster_pair' && $schemaKind !== 'slot')
                || ($fieldType === 'holster_pair_list' && $schemaKind !== 'application')
                || (in_array($fieldType, ['holster_pair', 'holster_pair_list'], true) && $source !== 'bozja_holsters')) {
                throw ValidationException::withMessages([
                    "$attribute.$index.type" => __('errors.holster_pair_fields_must_use_the_bozja_holster_source_in_the_correct_schema'),
                ]);
            }

            if (in_array($fieldType, ['single_select', 'multi_select'], true)
                && ! in_array($source, $this->supportedOptionSources(), true)) {
                throw ValidationException::withMessages([
                    "$attribute.$index.source" => __('errors.select_fields_require_a_supported_option_source'),
                ]);
            }

            if ($acceptsAny) {
                if (! $supportsAnySelection || ! in_array($fieldType, ['single_select', 'multi_select'], true)) {
                    throw ValidationException::withMessages([
                        "$attribute.$index.accepts_any" => __('errors.any_selection_application_only'),
                    ]);
                }

                $this->assertLocalizedValue($field['any_label'] ?? null, "$attribute.$index.any_label");
            }

            if (($field['source'] ?? null) === 'static_options') {
                if (! isset($field['options']) || ! is_array($field['options']) || $field['options'] === []) {
                    throw ValidationException::withMessages([
                        "$attribute.$index.options" => __('errors.static_option_fields_require_at_least_one_option'),
                    ]);
                }

                foreach ($field['options'] as $optionIndex => $option) {
                    if (! is_array($option) || blank($option['value'] ?? null)) {
                        throw ValidationException::withMessages([
                            "$attribute.$index.options.$optionIndex" => __('errors.each_static_option_requires_a_value'),
                        ]);
                    }

                    $this->assertLocalizedValue($option['label'] ?? null, "$attribute.$index.options.$optionIndex.label");
                }
            }
        }
    }

    private function assertLocalizedValue(mixed $value, string $attribute, bool $requireEnglish = true): void
    {
        if (! is_array($value) || $value === []) {
            throw ValidationException::withMessages([
                $attribute => __('errors.this_field_must_be_a_localized_object'),
            ]);
        }

        if ($requireEnglish && (! array_key_exists('en', $value) || blank($value['en']))) {
            throw ValidationException::withMessages([
                "$attribute.en" => __('errors.an_english_translation_is_required'),
            ]);
        }

        foreach ($value as $locale => $translation) {
            if (! is_string($locale) || (! is_string($translation) && ! is_null($translation))) {
                throw ValidationException::withMessages([
                    $attribute => __('errors.localized_values_must_be_keyed_by_locale_and_contain_strings'),
                ]);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    public function supportedOptionSources(): array
    {
        return [
            'character_classes',
            'phantom_jobs',
            'bozja_holsters',
            'raid_positions',
            ...array_keys(BozjaItemCategory::sourceCategoryMap()),
            'static_options',
        ];
    }
}
