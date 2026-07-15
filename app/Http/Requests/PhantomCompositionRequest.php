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
                $validator->errors()->add($ruleAttribute, 'Each phantom composition rule must be an object.');

                continue;
            }

            $type = $rule['type'] ?? null;

            if (! is_string($type) || ! in_array($type, PhantomComposition::ruleTypes(), true)) {
                $validator->errors()->add("{$ruleAttribute}.type", 'Choose a supported phantom composition rule type.');

                continue;
            }

            if ($isPackageChild && $type === PhantomComposition::RULE_PACKAGE) {
                $validator->errors()->add("{$ruleAttribute}.type", 'Packages cannot contain nested package rules.');

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
            $validator->errors()->add($attribute, 'Rule labels must be 120 characters or fewer.');
        }
    }

    private function validateRuleSeverity(mixed $severity, string $attribute, Validator $validator): void
    {
        if (! is_string($severity) || ! in_array($severity, PhantomComposition::severities(), true)) {
            $validator->errors()->add($attribute, 'Choose a supported phantom composition severity.');
        }
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function validatePackageRule(array $rule, string $attribute, Validator $validator): void
    {
        $children = $rule['children'] ?? null;

        if (! is_array($children) || $children === []) {
            $validator->errors()->add("{$attribute}.children", 'Package rules must contain at least one child rule.');

            return;
        }

        if (count($children) > 25) {
            $validator->errors()->add("{$attribute}.children", 'Package rules can contain up to 25 child rules.');

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
            $validator->errors()->add("{$attribute}.comparison", 'Choose a supported phantom composition comparison.');
        }

        if (! $this->isWholeNumber($targetCount)) {
            $validator->errors()->add("{$attribute}.target_count", 'Rule target counts must be whole numbers.');
        } else {
            $targetCount = (int) $targetCount;

            if ($targetCount < 0 || $targetCount > 48) {
                $validator->errors()->add("{$attribute}.target_count", 'Rule target counts must be between 0 and 48.');
            }
        }

        if ($type === PhantomComposition::RULE_ANY_JOB_IN_SET && ($comparison !== PhantomComposition::COMPARISON_AT_LEAST || (int) $targetCount !== 1)) {
            $validator->errors()->add("{$attribute}.comparison", 'Any-job rules must use at least 1.');
        }

        if ($type === PhantomComposition::RULE_DUPLICATE_LIMIT && $comparison !== PhantomComposition::COMPARISON_AT_MOST) {
            $validator->errors()->add("{$attribute}.comparison", 'Duplicate-limit rules must use at most.');
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
            $validator->errors()->add($attribute, 'Each non-package rule requires a scope.');

            return;
        }

        $type = $scope['type'] ?? null;

        if (! is_string($type) || ! in_array($type, PhantomComposition::scopeTypes(), true)) {
            $validator->errors()->add("{$attribute}.type", 'Choose a supported phantom composition scope.');

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
            $validator->errors()->add($attribute, 'Choose a valid Phantom Job.');

            return;
        }

        if (! PhantomJob::query()->whereKey((int) $phantomJobId)->exists()) {
            $validator->errors()->add($attribute, 'Choose a valid Phantom Job.');
        }
    }

    private function validatePhantomJobIds(mixed $phantomJobIds, string $attribute, Validator $validator): void
    {
        if (! is_array($phantomJobIds) || $phantomJobIds === []) {
            $validator->errors()->add($attribute, 'Choose at least one Phantom Job.');

            return;
        }

        if (count($phantomJobIds) > 20) {
            $validator->errors()->add($attribute, 'Choose up to 20 Phantom Jobs.');

            return;
        }

        $seen = [];

        foreach ($phantomJobIds as $index => $phantomJobId) {
            $itemAttribute = "{$attribute}.{$index}";

            if (! $this->isWholeNumber($phantomJobId)) {
                $validator->errors()->add($itemAttribute, 'Choose a valid Phantom Job.');

                continue;
            }

            $phantomJobId = (int) $phantomJobId;

            if (in_array($phantomJobId, $seen, true)) {
                $validator->errors()->add($itemAttribute, 'Phantom Jobs must be unique within a rule.');

                continue;
            }

            $seen[] = $phantomJobId;

            if (! PhantomJob::query()->whereKey($phantomJobId)->exists()) {
                $validator->errors()->add($itemAttribute, 'Choose a valid Phantom Job.');
            }
        }
    }

    private function validateScopeGroupKeys(mixed $groupKeys, string $attribute, Validator $validator, int $min, ?int $max = null): void
    {
        if (! is_array($groupKeys)) {
            $validator->errors()->add($attribute, 'Choose one or more slot groups for this scope.');

            return;
        }

        $count = count($groupKeys);

        if ($count < $min || ($max !== null && $count > $max)) {
            $validator->errors()->add($attribute, $max === 1
                ? 'Choose exactly one slot group for this scope.'
                : 'Choose at least one slot group for this scope.');
        }

        $this->validateKnownGroupKeys($groupKeys, $attribute, $validator);
    }

    private function validateScopeGroupSets(mixed $groupSets, string $attribute, Validator $validator): void
    {
        if (! is_array($groupSets) || $groupSets === []) {
            $validator->errors()->add($attribute, 'Choose at least one slot group set for this scope.');

            return;
        }

        if (count($groupSets) > 12) {
            $validator->errors()->add($attribute, 'Choose up to 12 slot group sets for this scope.');

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
                $validator->errors()->add($itemAttribute, 'Choose a supported Forked Tower party.');

                continue;
            }

            if (in_array($groupKey, $seen, true)) {
                $validator->errors()->add($itemAttribute, 'Slot groups must be unique within a scope.');

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
