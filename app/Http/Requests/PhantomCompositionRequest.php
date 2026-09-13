<?php

namespace App\Http\Requests;

use App\Models\Group;
use App\Models\PhantomComposition;
use App\Models\PhantomJob;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PhantomCompositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $group = $this->route('group');

        if (! $group instanceof Group) {
            return false;
        }

        $group->loadMissing('memberships');

        return $group->hasModeratorAccess($this->user()?->id);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_default' => $this->boolean('is_default'),
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : true,
            'sort_order' => $this->input('sort_order', 0),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_default' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'rules' => ['present', 'array', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $rules = $this->input('rules');

            if (! is_array($rules)) {
                return;
            }

            $this->validateRuleCollection($rules, 'rules', $validator);
        });
    }

    /**
     * @param  array<mixed>  $rules
     */
    private function validateRuleCollection(array $rules, string $attribute, Validator $validator, bool $isPackageChild = false): void
    {
        foreach ($rules as $index => $rule) {
            $ruleAttribute = "{$attribute}.{$index}";

            if (! is_array($rule)) {
                $validator->errors()->add($ruleAttribute, __('errors.each_phantom_composition_rule_must_be_an_object'));

                continue;
            }

            $type = $rule['type'] ?? null;

            if (! is_string($type) || ! in_array($type, PhantomComposition::ruleTypes(), true)) {
                $validator->errors()->add("{$ruleAttribute}.type", __('errors.choose_a_supported_phantom_composition_rule_type'));

                continue;
            }

            if ($isPackageChild && $type === PhantomComposition::RULE_PACKAGE) {
                $validator->errors()->add("{$ruleAttribute}.type", __('errors.packages_cannot_contain_nested_package_rules'));

                continue;
            }

            $this->validateRuleLabel($rule['label'] ?? null, "{$ruleAttribute}.label", $validator);
            $this->validateRuleSeverity($rule['severity'] ?? null, "{$ruleAttribute}.severity", $validator);

            if ($type === PhantomComposition::RULE_PACKAGE) {
                $this->validatePackageRule($rule, $ruleAttribute, $validator);

                continue;
            }

            $this->validateCountRule($rule, $ruleAttribute, $type, $validator);
        }
    }

    private function validateRuleLabel(mixed $label, string $attribute, Validator $validator): void
    {
        if ($label === null || $label === '') {
            return;
        }

        if (! is_string($label) || mb_strlen($label) > 120) {
            $validator->errors()->add($attribute, __('errors.rule_labels_must_be_120_characters_or_fewer'));
        }
    }

    private function validateRuleSeverity(mixed $severity, string $attribute, Validator $validator): void
    {
        if (! is_string($severity) || ! in_array($severity, PhantomComposition::severities(), true)) {
            $validator->errors()->add($attribute, __('errors.choose_a_supported_phantom_composition_severity'));
        }
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function validatePackageRule(array $rule, string $attribute, Validator $validator): void
    {
        $children = $rule['children'] ?? null;

        if (! is_array($children) || $children === []) {
            $validator->errors()->add("{$attribute}.children", __('errors.package_rules_must_contain_at_least_one_child_rule'));

            return;
        }

        if (count($children) > 25) {
            $validator->errors()->add("{$attribute}.children", __('errors.package_rules_can_contain_up_to_25_child_rules'));

            return;
        }

        $this->validateRuleCollection($children, "{$attribute}.children", $validator, true);
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function validateCountRule(array $rule, string $attribute, string $type, Validator $validator): void
    {
        $comparison = $rule['comparison'] ?? null;
        $targetCount = $rule['target_count'] ?? null;

        if (! is_string($comparison) || ! in_array($comparison, PhantomComposition::comparisons(), true)) {
            $validator->errors()->add("{$attribute}.comparison", __('errors.choose_a_supported_phantom_composition_comparison'));
        }

        if (! $this->isWholeNumber($targetCount)) {
            $validator->errors()->add("{$attribute}.target_count", __('errors.rule_target_counts_must_be_whole_numbers'));
        } else {
            $targetCount = (int) $targetCount;

            if ($targetCount < 0 || $targetCount > 48) {
                $validator->errors()->add("{$attribute}.target_count", __('errors.rule_target_counts_must_be_between_0_and_48'));
            }
        }

        if ($type === PhantomComposition::RULE_ANY_JOB_IN_SET && ($comparison !== PhantomComposition::COMPARISON_AT_LEAST || (int) $targetCount !== 1)) {
            $validator->errors()->add("{$attribute}.comparison", __('errors.any_job_rules_must_use_at_least_1'));
        }

        if ($type === PhantomComposition::RULE_DUPLICATE_LIMIT && $comparison !== PhantomComposition::COMPARISON_AT_MOST) {
            $validator->errors()->add("{$attribute}.comparison", __('errors.duplicate_limit_rules_must_use_at_most'));
        }

        $this->validateScope($rule['scope'] ?? null, "{$attribute}.scope", $validator);

        if (in_array($type, [PhantomComposition::RULE_SINGLE_JOB_COUNT, PhantomComposition::RULE_DUPLICATE_LIMIT], true)) {
            $this->validatePhantomJobId($rule['phantom_job_id'] ?? null, "{$attribute}.phantom_job_id", $validator);

            return;
        }

        $this->validatePhantomJobIds($rule['phantom_job_ids'] ?? null, "{$attribute}.phantom_job_ids", $validator);
    }

    private function validateScope(mixed $scope, string $attribute, Validator $validator): void
    {
        if (! is_array($scope)) {
            $validator->errors()->add($attribute, __('errors.each_non_package_rule_requires_a_scope'));

            return;
        }

        $type = $scope['type'] ?? null;

        if (! is_string($type) || ! in_array($type, PhantomComposition::scopeTypes(), true)) {
            $validator->errors()->add("{$attribute}.type", __('errors.choose_a_supported_phantom_composition_scope'));

            return;
        }

        if ($type === PhantomComposition::SCOPE_SLOT_GROUP) {
            $this->validateScopeGroupKeys($scope['group_keys'] ?? null, "{$attribute}.group_keys", $validator, 1, 1);

            return;
        }

        if ($type === PhantomComposition::SCOPE_SLOT_GROUP_SET) {
            $this->validateScopeGroupKeys($scope['group_keys'] ?? null, "{$attribute}.group_keys", $validator, 1);

            return;
        }

        if ($type === PhantomComposition::SCOPE_EACH_SLOT_GROUP_SET) {
            $this->validateScopeGroupSets($scope['group_sets'] ?? null, "{$attribute}.group_sets", $validator);
        }
    }

    private function validatePhantomJobId(mixed $phantomJobId, string $attribute, Validator $validator): void
    {
        if (! $this->isWholeNumber($phantomJobId)) {
            $validator->errors()->add($attribute, __('errors.choose_a_valid_phantom_job'));

            return;
        }

        if (! PhantomJob::query()->whereKey((int) $phantomJobId)->exists()) {
            $validator->errors()->add($attribute, __('errors.choose_a_valid_phantom_job'));
        }
    }

    private function validatePhantomJobIds(mixed $phantomJobIds, string $attribute, Validator $validator): void
    {
        if (! is_array($phantomJobIds) || $phantomJobIds === []) {
            $validator->errors()->add($attribute, __('errors.choose_at_least_one_phantom_job'));

            return;
        }

        if (count($phantomJobIds) > 20) {
            $validator->errors()->add($attribute, __('errors.choose_up_to_20_phantom_jobs'));

            return;
        }

        $seen = [];

        foreach ($phantomJobIds as $index => $phantomJobId) {
            $itemAttribute = "{$attribute}.{$index}";

            if (! $this->isWholeNumber($phantomJobId)) {
                $validator->errors()->add($itemAttribute, __('errors.choose_a_valid_phantom_job'));

                continue;
            }

            $phantomJobId = (int) $phantomJobId;

            if (in_array($phantomJobId, $seen, true)) {
                $validator->errors()->add($itemAttribute, __('errors.phantom_jobs_must_be_unique_within_a_rule'));

                continue;
            }

            $seen[] = $phantomJobId;

            if (! PhantomJob::query()->whereKey($phantomJobId)->exists()) {
                $validator->errors()->add($itemAttribute, __('errors.choose_a_valid_phantom_job'));
            }
        }
    }

    private function validateScopeGroupKeys(mixed $groupKeys, string $attribute, Validator $validator, int $min, ?int $max = null): void
    {
        if (! is_array($groupKeys)) {
            $validator->errors()->add($attribute, __('errors.choose_one_or_more_slot_groups_for_this_scope'));

            return;
        }

        $count = count($groupKeys);

        if ($count < $min || ($max !== null && $count > $max)) {
            $validator->errors()->add($attribute, $max === 1
                ? __('errors.choose_exactly_one_slot_group_for_this_scope')
                : __('errors.choose_at_least_one_slot_group_for_this_scope'));
        }

        $this->validateKnownGroupKeys($groupKeys, $attribute, $validator);
    }

    private function validateScopeGroupSets(mixed $groupSets, string $attribute, Validator $validator): void
    {
        if (! is_array($groupSets) || $groupSets === []) {
            $validator->errors()->add($attribute, __('errors.choose_at_least_one_slot_group_set_for_this_scope'));

            return;
        }

        if (count($groupSets) > 12) {
            $validator->errors()->add($attribute, __('errors.choose_up_to_12_slot_group_sets_for_this_scope'));

            return;
        }

        foreach ($groupSets as $index => $groupKeys) {
            $this->validateScopeGroupKeys($groupKeys, "{$attribute}.{$index}", $validator, 1);
        }
    }

    /**
     * @param  array<mixed>  $groupKeys
     */
    private function validateKnownGroupKeys(array $groupKeys, string $attribute, Validator $validator): void
    {
        $allowedGroupKeys = collect(PhantomComposition::slotGroupsForContent(PhantomComposition::CONTENT_FORKED_TOWER_BLOOD))
            ->pluck('key')
            ->all();
        $seen = [];

        foreach ($groupKeys as $index => $groupKey) {
            $itemAttribute = "{$attribute}.{$index}";

            if (! is_string($groupKey) || ! in_array($groupKey, $allowedGroupKeys, true)) {
                $validator->errors()->add($itemAttribute, __('errors.choose_a_supported_forked_tower_party'));

                continue;
            }

            if (in_array($groupKey, $seen, true)) {
                $validator->errors()->add($itemAttribute, __('errors.slot_groups_must_be_unique_within_a_scope'));

                continue;
            }

            $seen[] = $groupKey;
        }
    }

    private function isWholeNumber(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && ctype_digit($value));
    }
}
