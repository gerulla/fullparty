<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\ActivityTypeVersion;
use App\Models\CharacterClass;
use App\Models\PhantomComposition;
use App\Models\PhantomJob;

class ActivityRosterSummaryPresetBuilder
{
    private const FORKED_TOWER_ACTIVITY_TYPE_SLUGS = [
        'forked-tower',
        'forked-tower-magic',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function build(?ActivityTypeVersion $activityTypeVersion): array
    {
        return $this->buildFromActivityTypeVersion($activityTypeVersion);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildForActivity(Activity $activity): array
    {
        $activity->loadMissing(['activityType', 'activityTypeVersion']);

        if (! $activity->activityTypeVersion) {
            return [];
        }

        $groupLabelsByKey = $this->groupLabelsByKey($activity->activityTypeVersion);
        $useGroupPhantomCompositions = $this->usesGroupPhantomCompositions($activity);

        return [
            ...$this->buildFromActivityTypeVersion(
                $activity->activityTypeVersion,
                omitPhantomJobRequirements: $useGroupPhantomCompositions,
                groupLabelsByKey: $groupLabelsByKey,
            ),
            ...($useGroupPhantomCompositions
                ? $this->buildPhantomCompositionPresets($activity, $groupLabelsByKey)
                : []),
        ];
    }

    /**
     * @param  array<string, array<string, string>>|null  $groupLabelsByKey
     * @return array<int, array<string, mixed>>
     */
    private function buildFromActivityTypeVersion(
        ?ActivityTypeVersion $activityTypeVersion,
        bool $omitPhantomJobRequirements = false,
        ?array $groupLabelsByKey = null,
    ): array {
        if (! $activityTypeVersion) {
            return [];
        }

        $groupLabelsByKey ??= $this->groupLabelsByKey($activityTypeVersion);

        return collect($activityTypeVersion->roster_summary_presets ?? [])
            ->filter(fn (mixed $preset) => is_array($preset) && filled($preset['key'] ?? null))
            ->map(function (array $preset) use ($groupLabelsByKey, $omitPhantomJobRequirements) {
                $requirements = collect($preset['requirements'] ?? [])
                    ->filter(fn (mixed $requirement) => is_array($requirement))
                    ->reject(fn (array $requirement) => $omitPhantomJobRequirements
                        && ($requirement['source'] ?? null) === 'phantom_jobs')
                    ->map(fn (array $requirement) => $this->buildRequirementFromLegacyPreset($requirement, $groupLabelsByKey))
                    ->values()
                    ->all();

                if ($omitPhantomJobRequirements && $requirements === []) {
                    return null;
                }

                return [
                    'key' => (string) $preset['key'],
                    'label' => is_array($preset['label'] ?? null)
                        ? $preset['label']
                        : ['en' => (string) $preset['key']],
                    'description' => is_array($preset['description'] ?? null)
                        ? $preset['description']
                        : ['en' => ''],
                    'requirements' => $requirements,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function groupLabelsByKey(ActivityTypeVersion $activityTypeVersion): array
    {
        return collect($activityTypeVersion->layout_schema['groups'] ?? [])
            ->filter(fn (mixed $group) => is_array($group) && filled($group['key'] ?? null))
            ->mapWithKeys(fn (array $group) => [
                (string) $group['key'] => is_array($group['label'] ?? null)
                    ? $group['label']
                    : ['en' => (string) $group['key']],
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $requirement
     * @param  array<string, array<string, string>>  $groupLabelsByKey
     * @return array<string, mixed>
     */
    private function buildRequirementFromLegacyPreset(array $requirement, array $groupLabelsByKey): array
    {
        $source = (string) ($requirement['source'] ?? '');
        $sourceId = (int) ($requirement['source_id'] ?? 0);
        $scopeGroupKeys = $this->normalizeScopeGroupKeys($requirement['scope_group_keys'] ?? []);

        return $this->buildRequirement(
            source: $source,
            sourceId: $sourceId,
            comparison: (string) ($requirement['comparison'] ?? 'at_least'),
            targetCount: (int) ($requirement['target_count'] ?? 1),
            scopeType: (string) ($requirement['scope_type'] ?? 'all_slots'),
            scopeGroupKeys: $scopeGroupKeys,
            groupLabelsByKey: $groupLabelsByKey,
        );
    }

    private function usesGroupPhantomCompositions(Activity $activity): bool
    {
        return in_array($activity->activityType?->slug, self::FORKED_TOWER_ACTIVITY_TYPE_SLUGS, true);
    }

    /**
     * @param  array<string, array<string, string>>  $groupLabelsByKey
     * @return array<int, array<string, mixed>>
     */
    private function buildPhantomCompositionPresets(Activity $activity, array $groupLabelsByKey): array
    {
        return PhantomComposition::query()
            ->where('group_id', $activity->group_id)
            ->where('content_key', PhantomComposition::CONTENT_FORKED_TOWER)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (PhantomComposition $composition) => [
                'key' => sprintf('phantom-composition-%d', $composition->id),
                'label' => ['en' => $composition->name],
                'description' => ['en' => (string) ($composition->description ?? '')],
                'requirements' => $this->buildPhantomCompositionRequirements($composition, $groupLabelsByKey),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array<string, string>>  $groupLabelsByKey
     * @return array<int, array<string, mixed>>
     */
    private function buildPhantomCompositionRequirements(PhantomComposition $composition, array $groupLabelsByKey): array
    {
        return collect($composition->rules ?? [])
            ->filter(fn (mixed $rule) => is_array($rule))
            ->flatMap(fn (array $rule) => $this->buildPhantomCompositionRuleRequirements($rule, $groupLabelsByKey))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, array<string, string>>  $groupLabelsByKey
     * @return array<int, array<string, mixed>>
     */
    private function buildPhantomCompositionRuleRequirements(array $rule, array $groupLabelsByKey): array
    {
        $type = (string) ($rule['type'] ?? '');

        if (! in_array($type, [PhantomComposition::RULE_SINGLE_JOB_COUNT, PhantomComposition::RULE_DUPLICATE_LIMIT], true)) {
            return [];
        }

        $sourceId = (int) ($rule['phantom_job_id'] ?? 0);

        if ($sourceId <= 0) {
            return [];
        }

        $comparison = (string) ($rule['comparison'] ?? ($type === PhantomComposition::RULE_DUPLICATE_LIMIT ? 'at_most' : 'at_least'));
        $targetCount = max(0, (int) ($rule['target_count'] ?? 1));
        $severity = is_string($rule['severity'] ?? null) ? (string) $rule['severity'] : PhantomComposition::SEVERITY_REQUIRED;

        return collect($this->summaryScopesForPhantomRule($rule['scope'] ?? null, $groupLabelsByKey))
            ->map(fn (array $scope) => $this->buildRequirement(
                source: 'phantom_jobs',
                sourceId: $sourceId,
                comparison: $comparison,
                targetCount: $targetCount,
                scopeType: $scope['scope_type'],
                scopeGroupKeys: $scope['scope_group_keys'],
                groupLabelsByKey: $groupLabelsByKey,
                severity: $severity,
            ))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array<string, string>>  $groupLabelsByKey
     * @return array<int, array{scope_type: string, scope_group_keys: array<int, string>}>
     */
    private function summaryScopesForPhantomRule(mixed $scope, array $groupLabelsByKey): array
    {
        if (! is_array($scope)) {
            return [['scope_type' => 'all_slots', 'scope_group_keys' => []]];
        }

        $type = (string) ($scope['type'] ?? PhantomComposition::SCOPE_ALL_SLOTS);

        if ($type === PhantomComposition::SCOPE_SLOT_GROUP) {
            $groupKeys = $this->normalizeScopeGroupKeys($scope['group_keys'] ?? []);

            return $groupKeys === []
                ? [['scope_type' => 'all_slots', 'scope_group_keys' => []]]
                : [['scope_type' => 'slot_group', 'scope_group_keys' => [$groupKeys[0]]]];
        }

        if ($type === PhantomComposition::SCOPE_SLOT_GROUP_SET) {
            $groupKeys = $this->normalizeScopeGroupKeys($scope['group_keys'] ?? []);

            return $groupKeys === []
                ? [['scope_type' => 'all_slots', 'scope_group_keys' => []]]
                : [['scope_type' => count($groupKeys) === 1 ? 'slot_group' : 'slot_group_set', 'scope_group_keys' => $groupKeys]];
        }

        if ($type === PhantomComposition::SCOPE_EACH_SLOT_GROUP) {
            $groupKeys = $this->normalizeScopeGroupKeys($scope['group_keys'] ?? array_keys($groupLabelsByKey));

            return collect($groupKeys)
                ->map(fn (string $groupKey) => ['scope_type' => 'slot_group', 'scope_group_keys' => [$groupKey]])
                ->values()
                ->all();
        }

        if ($type === PhantomComposition::SCOPE_EACH_SLOT_GROUP_SET) {
            return collect($scope['group_sets'] ?? [])
                ->filter(fn (mixed $groupKeys) => is_array($groupKeys))
                ->map(fn (array $groupKeys) => $this->normalizeScopeGroupKeys($groupKeys))
                ->filter(fn (array $groupKeys) => $groupKeys !== [])
                ->map(fn (array $groupKeys) => [
                    'scope_type' => count($groupKeys) === 1 ? 'slot_group' : 'slot_group_set',
                    'scope_group_keys' => $groupKeys,
                ])
                ->values()
                ->all();
        }

        return [['scope_type' => 'all_slots', 'scope_group_keys' => []]];
    }

    /**
     * @param  array<mixed>  $scopeGroupKeys
     * @return array<int, string>
     */
    private function normalizeScopeGroupKeys(mixed $scopeGroupKeys): array
    {
        return collect(is_array($scopeGroupKeys) ? $scopeGroupKeys : [])
            ->filter(fn (mixed $groupKey) => is_string($groupKey) && filled($groupKey))
            ->map(fn (string $groupKey) => trim($groupKey))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $scopeGroupKeys
     * @param  array<string, array<string, string>>  $groupLabelsByKey
     * @return array<string, mixed>
     */
    private function buildRequirement(
        string $source,
        int $sourceId,
        string $comparison,
        int $targetCount,
        string $scopeType,
        array $scopeGroupKeys,
        array $groupLabelsByKey,
        ?string $severity = null,
    ): array {
        return [
            'source' => $source,
            'source_id' => $sourceId,
            'comparison' => $comparison,
            'target_count' => $targetCount,
            'scope_type' => $scopeType,
            'scope_group_keys' => $scopeGroupKeys,
            'scope_groups' => collect($scopeGroupKeys)
                ->map(fn (string $groupKey) => [
                    'key' => $groupKey,
                    'label' => $groupLabelsByKey[$groupKey] ?? ['en' => $groupKey],
                ])
                ->values()
                ->all(),
            'item' => $this->resolveItem($source, $sourceId),
            ...($severity !== null ? ['severity' => $severity] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveItem(string $source, int $sourceId): array
    {
        return match ($source) {
            'character_classes' => $this->resolveCharacterClassItem($sourceId),
            'phantom_jobs' => $this->resolvePhantomJobItem($sourceId),
            default => [
                'id' => $sourceId,
                'label' => ['en' => (string) $sourceId],
                'meta' => null,
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveCharacterClassItem(int $sourceId): array
    {
        $characterClass = CharacterClass::query()->find($sourceId);

        if (! $characterClass) {
            return [
                'id' => $sourceId,
                'label' => ['en' => (string) $sourceId],
                'meta' => null,
            ];
        }

        return [
            'id' => $characterClass->id,
            'label' => ['en' => $characterClass->name],
            'meta' => [
                'role' => $characterClass->role,
                'shorthand' => $characterClass->shorthand,
                'icon_url' => $characterClass->icon_url,
                'flaticon_url' => $characterClass->flaticon_url,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvePhantomJobItem(int $sourceId): array
    {
        $phantomJob = PhantomJob::query()->find($sourceId);

        if (! $phantomJob) {
            return [
                'id' => $sourceId,
                'label' => ['en' => (string) $sourceId],
                'meta' => null,
            ];
        }

        return [
            'id' => $phantomJob->id,
            'label' => ['en' => $phantomJob->name],
            'meta' => [
                'icon_url' => $phantomJob->icon_url,
                'black_icon_url' => $phantomJob->black_icon_url,
                'transparent_icon_url' => $phantomJob->transparent_icon_url,
                'sprite_url' => $phantomJob->sprite_url,
            ],
        ];
    }
}
