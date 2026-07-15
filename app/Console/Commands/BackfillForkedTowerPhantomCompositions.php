<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\PhantomComposition;
use App\Models\PhantomJob;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class BackfillForkedTowerPhantomCompositions extends Command
{
    protected $signature = 'phantom-compositions:backfill-forked-tower
                            {--dry-run : Show how many compositions would be created without writing records}';

    protected $description = 'Backfill the default Forked Tower: Blood PhantomComposition presets for existing groups';

    public function handle(): int
    {
        $phantomJobIds = $this->resolvePhantomJobIds();

        if ($phantomJobIds === null) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $presets = $this->presets($phantomJobIds);
        $stats = [
            'groups' => 0,
            'created' => 0,
            'skipped' => 0,
        ];

        Group::query()
            ->select(['id', 'name'])
            ->orderBy('id')
            ->chunkById(100, function (Collection $groups) use ($dryRun, $presets, &$stats): void {
                foreach ($groups as $group) {
                    $stats['groups']++;
                    $this->backfillGroup($group, $presets, $dryRun, $stats);
                }
            });

        if ($dryRun) {
            $this->warn('Dry run only. No PhantomComposition records were written.');
        }

        $this->table(
            ['Groups scanned', $dryRun ? 'Would create' : 'Created', 'Skipped'],
            [[$stats['groups'], $stats['created'], $stats['skipped']]],
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $phantomJobIds
     * @param  array<int, array{name: string, description: string, is_default: bool, rules: array<int, array<string, mixed>>}>  $presets
     * @param  array{groups: int, created: int, skipped: int}  $stats
     */
    private function backfillGroup(Group $group, array $presets, bool $dryRun, array &$stats): void
    {
        $query = PhantomComposition::query()
            ->where('group_id', $group->id)
            ->where('content_key', PhantomComposition::CONTENT_FORKED_TOWER_BLOOD);

        $existingNames = $query
            ->clone()
            ->pluck('name')
            ->all();
        $existingNameSet = array_fill_keys($existingNames, true);
        $hasDefault = $query
            ->clone()
            ->where('is_default', true)
            ->exists();
        $maxSortOrder = $query
            ->clone()
            ->max('sort_order');
        $nextSortOrder = $maxSortOrder === null ? 0 : (int) $maxSortOrder + 1;

        foreach ($presets as $preset) {
            if (isset($existingNameSet[$preset['name']])) {
                $stats['skipped']++;

                continue;
            }

            $shouldBeDefault = ! $hasDefault && $preset['is_default'];
            $stats['created']++;

            if (! $dryRun) {
                PhantomComposition::query()->create([
                    'group_id' => $group->id,
                    'content_key' => PhantomComposition::CONTENT_FORKED_TOWER_BLOOD,
                    'name' => $preset['name'],
                    'description' => $preset['description'],
                    'is_default' => $shouldBeDefault,
                    'is_active' => true,
                    'sort_order' => $nextSortOrder,
                    'rules' => $preset['rules'],
                ]);
            }

            if ($shouldBeDefault) {
                $hasDefault = true;
            }

            $nextSortOrder++;
        }
    }

    /**
     * @return array<string, int>|null
     */
    private function resolvePhantomJobIds(): ?array
    {
        $requiredNames = $this->requiredPhantomJobNames();
        $phantomJobIds = PhantomJob::query()
            ->whereIn('name', $requiredNames)
            ->pluck('id', 'name')
            ->map(fn ($id) => (int) $id)
            ->all();

        $missingNames = array_values(array_diff($requiredNames, array_keys($phantomJobIds)));

        if ($missingNames !== []) {
            $this->error('Missing required Phantom Jobs: '.implode(', ', $missingNames));

            return null;
        }

        return $phantomJobIds;
    }

    /**
     * @return array<int, string>
     */
    private function requiredPhantomJobNames(): array
    {
        return [
            'Phantom Bard',
            'Phantom Ranger',
            'Phantom Thief',
            'Phantom Geomancer',
            'Phantom Time Mage',
            'Phantom Oracle',
            'Phantom Berserker',
            'Phantom Cannoneer',
            'Phantom Mystic Knight',
        ];
    }

    /**
     * @param  array<string, int>  $phantomJobIds
     * @return array<int, array{name: string, description: string, is_default: bool, rules: array<int, array<string, mixed>>}>
     */
    private function presets(array $phantomJobIds): array
    {
        return [
            [
                'name' => 'Minimal Composition',
                'description' => 'This is the bare minimum set of jobs needed to clear.',
                'is_default' => true,
                'rules' => [
                    ...$this->sideRules($phantomJobIds, ['Phantom Bard', 'Phantom Ranger', 'Phantom Thief', 'Phantom Geomancer', 'Phantom Time Mage'], 1),
                    $this->allSlotsRule($phantomJobIds, 'Phantom Oracle', 1),
                    $this->allSlotsRule($phantomJobIds, 'Phantom Berserker', 1),
                ],
            ],
            [
                'name' => 'Recommended Composition',
                'description' => 'A safer, more rounded composition with the most useful support coverage on each side.',
                'is_default' => false,
                'rules' => [
                    ...$this->sideRules($phantomJobIds, ['Phantom Bard', 'Phantom Ranger', 'Phantom Thief', 'Phantom Geomancer', 'Phantom Time Mage', 'Phantom Cannoneer', 'Phantom Mystic Knight'], 1),
                    $this->allSlotsRule($phantomJobIds, 'Phantom Berserker', 1),
                    $this->allSlotsRule($phantomJobIds, 'Phantom Oracle', 1),
                ],
            ],
            [
                'name' => 'Risky Min-Maxing Composition',
                'description' => 'A greedier setup that trims safety tools in favor of more aggressive damage output.',
                'is_default' => false,
                'rules' => [
                    ...$this->sideRules($phantomJobIds, ['Phantom Bard', 'Phantom Ranger'], 1),
                    ...$this->sideRules($phantomJobIds, ['Phantom Geomancer'], 2),
                    ...$this->sideRules($phantomJobIds, ['Phantom Time Mage', 'Phantom Cannoneer', 'Phantom Mystic Knight'], 1),
                    $this->allSlotsRule($phantomJobIds, 'Phantom Berserker', 6),
                    $this->allSlotsRule($phantomJobIds, 'Phantom Oracle', 3),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, int>  $phantomJobIds
     * @param  array<int, string>  $jobNames
     * @return array<int, array<string, mixed>>
     */
    private function sideRules(array $phantomJobIds, array $jobNames, int $targetCount): array
    {
        $rules = [];

        foreach ([
            ['party-a', 'party-b', 'party-c'],
            ['party-d', 'party-e', 'party-f'],
        ] as $groupKeys) {
            foreach ($jobNames as $jobName) {
                $rules[] = $this->slotGroupSetRule($phantomJobIds, $jobName, $targetCount, $groupKeys);
            }
        }

        return $rules;
    }

    /**
     * @param  array<string, int>  $phantomJobIds
     * @param  array<int, string>  $groupKeys
     * @return array<string, mixed>
     */
    private function slotGroupSetRule(array $phantomJobIds, string $jobName, int $targetCount, array $groupKeys): array
    {
        return $this->singleJobRule($phantomJobIds, $jobName, $targetCount, [
            'type' => PhantomComposition::SCOPE_SLOT_GROUP_SET,
            'group_keys' => $groupKeys,
        ]);
    }

    /**
     * @param  array<string, int>  $phantomJobIds
     * @return array<string, mixed>
     */
    private function allSlotsRule(array $phantomJobIds, string $jobName, int $targetCount): array
    {
        return $this->singleJobRule($phantomJobIds, $jobName, $targetCount, [
            'type' => PhantomComposition::SCOPE_ALL_SLOTS,
        ]);
    }

    /**
     * @param  array<string, int>  $phantomJobIds
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private function singleJobRule(array $phantomJobIds, string $jobName, int $targetCount, array $scope): array
    {
        return [
            'type' => PhantomComposition::RULE_SINGLE_JOB_COUNT,
            'label' => $jobName,
            'severity' => PhantomComposition::SEVERITY_REQUIRED,
            'comparison' => PhantomComposition::COMPARISON_AT_LEAST,
            'target_count' => $targetCount,
            'scope' => $scope,
            'phantom_job_id' => $phantomJobIds[$jobName],
        ];
    }
}
