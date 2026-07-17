<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotCompositionHint;
use App\Models\CharacterClass;
use App\Support\ActivityCompositionPresets;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivityPartyCompositionHintService
{
    public function __construct(
        private readonly ActivitySlotKind $slotKind,
    ) {}

    /**
     * @return Collection<int, ActivitySlot>
     */
    public function applyPreset(Activity $activity, string $groupKey, string $compositionPresetKey): Collection
    {
        $slots = $activity->slots()
            ->where('group_key', $groupKey)
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (ActivitySlot $slot): bool => $this->slotKind->isMainRoster($slot))
            ->values();

        if ($slots->isEmpty()) {
            throw ValidationException::withMessages([
                'group_key' => 'The selected party could not be found.',
            ]);
        }

        $this->assertPresetMatchesPartySize($compositionPresetKey, $slots->count());

        DB::transaction(fn () => $this->applyPresetToSlotCollection($slots, $compositionPresetKey));

        return $this->loadUpdatedSlots($slots);
    }

    /**
     * @return Collection<int, ActivitySlot>
     */
    public function copyCompositionHintsToCompatibleGroups(Activity $activity, string $sourceGroupKey): Collection
    {
        $slotGroups = $activity->slots()
            ->with('compositionHints')
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (ActivitySlot $slot): bool => $this->slotKind->isMainRoster($slot))
            ->groupBy('group_key');

        $sourceSlots = $slotGroups->get($sourceGroupKey, collect())->values();

        if ($sourceSlots->isEmpty()) {
            throw ValidationException::withMessages([
                'source_group_key' => 'The selected source party could not be found.',
            ]);
        }

        $partySize = $sourceSlots->count();
        $sourceHintsByPosition = $sourceSlots->mapWithKeys(fn (ActivitySlot $slot): array => [
            (int) $slot->position_in_group => $slot->compositionHints
                ->map(fn (ActivitySlotCompositionHint $hint) => [
                    'hint_type' => $hint->hint_type,
                    'hint_key' => $hint->hint_key,
                    'role_key' => $hint->role_key,
                    'character_class_id' => $hint->character_class_id,
                    'sort_order' => $hint->sort_order,
                ])
                ->values()
                ->all(),
        ]);

        $targetSlotGroups = $slotGroups
            ->reject(fn (Collection $slots, string $groupKey): bool => $groupKey === $sourceGroupKey)
            ->filter(fn (Collection $slots): bool => $slots->count() === $partySize);

        if ($targetSlotGroups->isEmpty()) {
            throw ValidationException::withMessages([
                'source_group_key' => 'No compatible parties are available for these composition hints.',
            ]);
        }

        DB::transaction(function () use ($targetSlotGroups, $sourceHintsByPosition): void {
            $targetSlotGroups->each(function (Collection $slots) use ($sourceHintsByPosition): void {
                $slots->values()->each(function (ActivitySlot $slot) use ($sourceHintsByPosition): void {
                    $slot->compositionHints()->delete();

                    foreach ($sourceHintsByPosition->get((int) $slot->position_in_group, []) as $hint) {
                        $slot->compositionHints()->create($hint);
                    }
                });
            });
        });

        return $this->loadUpdatedSlots($targetSlotGroups->flatten()->values());
    }

    /**
     * @param  array<int, array{type?: string, key?: string}>  $compositionHints
     */
    public function replaceSlotHints(Activity $activity, ActivitySlot $slot, array $compositionHints): ActivitySlot
    {
        if ((int) $slot->activity_id !== (int) $activity->id) {
            abort(404);
        }

        if (! $this->slotKind->isMainRoster($slot)) {
            throw ValidationException::withMessages([
                'slot' => 'Only main roster slots can have composition hints.',
            ]);
        }

        if ($slot->assigned_character_id !== null) {
            throw ValidationException::withMessages([
                'slot' => 'Composition hints can only be changed on empty slots.',
            ]);
        }

        DB::transaction(function () use ($slot, $compositionHints): void {
            $slot->compositionHints()->delete();

            foreach ($compositionHints as $index => $hint) {
                $this->createHint($slot, $hint, $index + 1);
            }
        });

        $slot->load([
            'assignedCharacter',
            'assignments',
            'compositionHints.characterClass',
            'fieldValues',
        ]);

        return $slot;
    }

    private function assertPresetMatchesPartySize(string $compositionPresetKey, int $partySize): void
    {
        if (! ActivityCompositionPresets::isCompositionKeyValidForPartySize($compositionPresetKey, $partySize)) {
            throw ValidationException::withMessages([
                'composition_preset_key' => 'The selected composition preset is not valid for this party size.',
            ]);
        }
    }

    /**
     * @param  Collection<int, ActivitySlot>  $slots
     */
    private function applyPresetToSlotCollection(Collection $slots, string $compositionPresetKey): void
    {
        $hintsByPosition = collect(ActivityCompositionPresets::compositionHintsForKey($compositionPresetKey))
            ->keyBy(fn (array $hint): int => (int) ($hint['position'] ?? 0));

        foreach ($slots as $slot) {
            $slot->compositionHints()->delete();

            foreach ($hintsByPosition->get((int) $slot->position_in_group)['accepts'] ?? [] as $index => $accept) {
                if (! is_array($accept)) {
                    continue;
                }

                $this->createHint($slot, $accept, $index + 1);
            }
        }
    }

    /**
     * @param  Collection<int, ActivitySlot>  $slots
     * @return Collection<int, ActivitySlot>
     */
    private function loadUpdatedSlots(Collection $slots): Collection
    {
        return $slots->each->load([
            'assignedCharacter',
            'assignments',
            'compositionHints.characterClass',
            'fieldValues',
        ]);
    }

    /**
     * @param  array<string, mixed>  $accept
     */
    private function createHint(ActivitySlot $slot, array $accept, int $sortOrder): void
    {
        $type = (string) ($accept['type'] ?? '');
        $key = (string) ($accept['key'] ?? '');

        if ($key === '' || ! in_array($type, [ActivitySlotCompositionHint::TYPE_ROLE, ActivitySlotCompositionHint::TYPE_CLASS], true)) {
            return;
        }

        $characterClass = $type === ActivitySlotCompositionHint::TYPE_CLASS
            ? CharacterClass::query()->where('shorthand', $key)->first()
            : null;

        $slot->compositionHints()->create([
            'hint_type' => $type,
            'hint_key' => $key,
            'role_key' => $type === ActivitySlotCompositionHint::TYPE_ROLE
                ? $key
                : $this->roleKeyForClass($characterClass),
            'character_class_id' => $characterClass?->id,
            'sort_order' => $sortOrder,
        ]);
    }

    private function roleKeyForClass(?CharacterClass $characterClass): ?string
    {
        if (! $characterClass) {
            return null;
        }

        return match (strtolower($characterClass->role)) {
            ActivityCompositionPresets::ROLE_TANK => ActivityCompositionPresets::ROLE_TANK,
            ActivityCompositionPresets::ROLE_HEALER => ActivityCompositionPresets::ROLE_HEALER,
            default => ActivityCompositionPresets::ROLE_DPS,
        };
    }
}
