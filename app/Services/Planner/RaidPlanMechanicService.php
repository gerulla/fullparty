<?php

namespace App\Services\Planner;

use App\Models\RaidPlan;
use App\Models\RaidPlanMechanic;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RaidPlanMechanicService
{
    /**
     * @param  array<int, array<string, mixed>>  $mechanics
     * @return Collection<int, RaidPlanMechanic>
     */
    public function sync(RaidPlan $raidPlan, array $mechanics): Collection
    {
        return DB::transaction(function () use ($raidPlan, $mechanics): Collection {
            RaidPlan::query()
                ->whereKey($raidPlan->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var Collection<int, RaidPlanMechanic> $existing */
            $existing = RaidPlanMechanic::query()
                ->where('raid_plan_id', $raidPlan->id)
                ->get()
                ->keyBy('id');
            $keptIds = [];

            foreach ($mechanics as $rootOrder => $attributes) {
                $root = $this->resolveExistingForSync(
                    $existing,
                    $attributes['id'] ?? null,
                    null,
                    $keptIds,
                );
                $type = $attributes['type'] ?? RaidPlanMechanic::TYPE_FIXED;

                $this->validateStructure($type, null);
                $this->fillMechanic(
                    $root,
                    $raidPlan,
                    null,
                    $attributes,
                    $type,
                    $rootOrder,
                );
                $keptIds[] = $root->id;

                if ($type !== RaidPlanMechanic::TYPE_RANDOM_SET) {
                    continue;
                }

                foreach ($attributes['variants'] ?? [] as $variantOrder => $variantAttributes) {
                    $variant = $this->resolveExistingForSync(
                        $existing,
                        $variantAttributes['id'] ?? null,
                        $root->id,
                        $keptIds,
                    );

                    $this->fillMechanic(
                        $variant,
                        $raidPlan,
                        $root,
                        $variantAttributes,
                        RaidPlanMechanic::TYPE_FIXED,
                        $variantOrder,
                    );
                    $keptIds[] = $variant->id;
                }
            }

            RaidPlanMechanic::query()
                ->where('raid_plan_id', $raidPlan->id)
                ->when(
                    $keptIds !== [],
                    fn ($query) => $query->whereNotIn('id', $keptIds),
                )
                ->delete();

            return $raidPlan->rootMechanics()
                ->with('children')
                ->get();
        });
    }

    /**
     * @param  array{
     *     name: string,
     *     type?: string,
     *     parent_id?: int|null,
     *     sort_order?: int,
     *     duration_ms?: int,
     *     selection_weight?: int,
     *     is_enabled?: bool,
     *     timeline?: array<string, mixed>,
     *     timeline_schema_version?: int
     * }  $attributes
     */
    public function create(RaidPlan $raidPlan, array $attributes): RaidPlanMechanic
    {
        return DB::transaction(function () use ($raidPlan, $attributes): RaidPlanMechanic {
            RaidPlan::query()
                ->whereKey($raidPlan->id)
                ->lockForUpdate()
                ->firstOrFail();

            $type = $attributes['type'] ?? RaidPlanMechanic::TYPE_FIXED;
            $parent = $this->resolveParent($raidPlan, $attributes['parent_id'] ?? null);

            $this->validateStructure($type, $parent);

            $sortOrder = array_key_exists('sort_order', $attributes)
                ? max(0, (int) $attributes['sort_order'])
                : $this->nextSortOrder($raidPlan, $parent);

            return $raidPlan->mechanics()->create([
                'parent_id' => $parent?->id,
                'name' => trim($attributes['name']),
                'type' => $type,
                'sort_order' => $sortOrder,
                'duration_ms' => $type === RaidPlanMechanic::TYPE_RANDOM_SET
                    ? 0
                    : max(0, (int) ($attributes['duration_ms'] ?? 0)),
                'selection_weight' => $parent
                    ? max(1, (int) ($attributes['selection_weight'] ?? 1))
                    : 1,
                'is_enabled' => $attributes['is_enabled'] ?? true,
                'timeline' => $type === RaidPlanMechanic::TYPE_RANDOM_SET
                    ? []
                    : ($attributes['timeline'] ?? []),
                'timeline_schema_version' => max(
                    1,
                    (int) ($attributes['timeline_schema_version']
                        ?? RaidPlanMechanic::CURRENT_TIMELINE_SCHEMA_VERSION)
                ),
            ]);
        });
    }

    private function resolveParent(RaidPlan $raidPlan, ?int $parentId): ?RaidPlanMechanic
    {
        if ($parentId === null) {
            return null;
        }

        $parent = RaidPlanMechanic::query()->find($parentId);

        if (! $parent || $parent->raid_plan_id !== $raidPlan->id) {
            throw ValidationException::withMessages([
                'parent_id' => 'The selected parent mechanic does not belong to this raid plan.',
            ]);
        }

        return $parent;
    }

    private function validateStructure(string $type, ?RaidPlanMechanic $parent): void
    {
        if (! in_array($type, RaidPlanMechanic::TYPES, true)) {
            throw ValidationException::withMessages([
                'type' => 'The selected mechanic type is invalid.',
            ]);
        }

        if ($parent && ! $parent->isRandomSet()) {
            throw ValidationException::withMessages([
                'parent_id' => 'Mechanic options can only belong to a random mechanic.',
            ]);
        }

        if ($parent && $type !== RaidPlanMechanic::TYPE_FIXED) {
            throw ValidationException::withMessages([
                'type' => 'Random mechanics cannot be nested.',
            ]);
        }
    }

    private function nextSortOrder(RaidPlan $raidPlan, ?RaidPlanMechanic $parent): int
    {
        $maximum = $raidPlan->mechanics()
            ->where('parent_id', $parent?->id)
            ->max('sort_order');

        return $maximum === null ? 0 : ((int) $maximum) + 1;
    }

    /**
     * @param  Collection<int, RaidPlanMechanic>  $existing
     * @param  array<int, int>  $keptIds
     */
    private function resolveExistingForSync(
        Collection $existing,
        mixed $id,
        ?int $expectedParentId,
        array $keptIds,
    ): RaidPlanMechanic {
        if ($id === null) {
            return new RaidPlanMechanic;
        }

        $mechanicId = (int) $id;
        $mechanic = $existing->get($mechanicId);

        if (
            ! $mechanic
            || $mechanic->parent_id !== $expectedParentId
            || in_array($mechanicId, $keptIds, true)
        ) {
            throw ValidationException::withMessages([
                'mechanics' => 'The submitted mechanic structure is invalid.',
            ]);
        }

        return $mechanic;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function fillMechanic(
        RaidPlanMechanic $mechanic,
        RaidPlan $raidPlan,
        ?RaidPlanMechanic $parent,
        array $attributes,
        string $type,
        int $sortOrder,
    ): void {
        $mechanic->fill([
            'raid_plan_id' => $raidPlan->id,
            'parent_id' => $parent?->id,
            'name' => trim((string) $attributes['name']),
            'type' => $type,
            'sort_order' => $sortOrder,
            'duration_ms' => $type === RaidPlanMechanic::TYPE_RANDOM_SET
                ? 0
                : max(0, (int) ($attributes['duration_ms'] ?? 0)),
            'selection_weight' => $parent
                ? max(1, (int) ($attributes['selection_weight'] ?? 1))
                : 1,
            'is_enabled' => $attributes['is_enabled'] ?? true,
            'timeline' => $type === RaidPlanMechanic::TYPE_RANDOM_SET
                ? []
                : ($attributes['timeline'] ?? []),
            'timeline_schema_version' => max(
                1,
                (int) ($attributes['timeline_schema_version']
                    ?? RaidPlanMechanic::CURRENT_TIMELINE_SCHEMA_VERSION)
            ),
        ]);
        $mechanic->save();
    }
}
