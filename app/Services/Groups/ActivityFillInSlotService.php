<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\ActivitySlot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivityFillInSlotService
{
    public function __construct(
        private readonly ActivitySlotKind $slotKind,
    ) {}

    public function create(Activity $activity, ?string $filledGroupKey = null): ActivitySlot
    {
        return DB::transaction(function () use ($activity, $filledGroupKey): ActivitySlot {
            $activity->loadMissing(['activityTypeVersion', 'slots']);

            $position = $this->nextFillInPosition($activity);
            $slot = $activity->slots()->create([
                'slot_kind' => ActivitySlot::SLOT_KIND_FILL_IN,
                'group_key' => ActivitySlotKind::FILL_IN_GROUP_KEY,
                'group_label' => $this->fillInGroupLabel(),
                'slot_key' => $this->nextFillInSlotKey($activity, $position),
                'slot_label' => $this->fillInSlotLabel($position),
                'position_in_group' => $position,
                'sort_order' => ((int) $activity->slots->max('sort_order')) + 1,
            ]);

            foreach ($this->slotDefinitions($activity) as $fieldDefinition) {
                $slot->fieldValues()->create([
                    'field_key' => (string) ($fieldDefinition['key'] ?? ''),
                    'field_label' => is_array($fieldDefinition['label'] ?? null)
                        ? $fieldDefinition['label']
                        : ['en' => (string) ($fieldDefinition['key'] ?? '')],
                    'field_type' => (string) ($fieldDefinition['type'] ?? 'text'),
                    'source' => $fieldDefinition['source'] ?? null,
                    'value' => null,
                ]);
            }

            if (filled($filledGroupKey)) {
                $this->applyFilledGroup($activity, $slot, $filledGroupKey);
            }

            return $slot->fresh([
                'activity',
                'assignedCharacter',
                'assignments',
                'compositionHints.characterClass',
                'fieldValues',
            ]);
        });
    }

    public function updateFilledGroup(Activity $activity, ActivitySlot $slot, ?string $filledGroupKey): ActivitySlot
    {
        if ((int) $slot->activity_id !== (int) $activity->id) {
            abort(404);
        }

        if (! $this->slotKind->isFillIn($slot)) {
            throw ValidationException::withMessages([
                'slot' => 'Only fill-in slots can track a filled party.',
            ]);
        }

        DB::transaction(function () use ($activity, $slot, $filledGroupKey): void {
            $this->applyFilledGroup($activity, $slot, blank($filledGroupKey) ? null : (string) $filledGroupKey);
        });

        return $slot->fresh([
            'activity',
            'assignedCharacter',
            'assignments',
            'compositionHints.characterClass',
            'fieldValues',
        ]);
    }

    public function assertFilledGroupExists(Activity $activity, string $filledGroupKey): void
    {
        $this->filledGroup($activity, $filledGroupKey);
    }

    public function delete(Activity $activity, ActivitySlot $slot): void
    {
        if ((int) $slot->activity_id !== (int) $activity->id) {
            abort(404);
        }

        if (! $this->slotKind->isFillIn($slot)) {
            throw ValidationException::withMessages([
                'slot' => 'Only fill-in slots can be removed through this flow.',
            ]);
        }

        $slot->delete();
    }

    /**
     * @return Collection<string, array<string, mixed>>
     */
    public function mainRosterGroupLabels(Activity $activity): Collection
    {
        $activity->loadMissing('slots');

        return $activity->slots
            ->filter(fn (ActivitySlot $slot): bool => $this->slotKind->isMainRoster($slot))
            ->sortBy('sort_order')
            ->mapWithKeys(fn (ActivitySlot $slot): array => [
                $slot->group_key => [
                    'key' => $slot->group_key,
                    'label' => is_array($slot->group_label) ? $slot->group_label : ['en' => $slot->group_key],
                ],
            ]);
    }

    private function applyFilledGroup(Activity $activity, ActivitySlot $slot, ?string $filledGroupKey): void
    {
        if ($filledGroupKey === null) {
            $slot->update([
                'filled_group_key' => null,
                'filled_group_label' => null,
            ]);

            return;
        }

        $group = $this->filledGroup($activity, $filledGroupKey);

        $slot->update([
            'filled_group_key' => $group['key'],
            'filled_group_label' => $group['label'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function filledGroup(Activity $activity, string $filledGroupKey): array
    {
        $group = $this->mainRosterGroupLabels($activity)->get($filledGroupKey);

        if (! $group) {
            throw ValidationException::withMessages([
                'filled_group_key' => 'Choose a valid party for this fill-in.',
            ]);
        }

        return $group;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function slotDefinitions(Activity $activity): array
    {
        return is_array($activity->activityTypeVersion?->slot_schema)
            ? $activity->activityTypeVersion->slot_schema
            : [];
    }

    private function nextFillInPosition(Activity $activity): int
    {
        return ((int) $activity->slots
            ->filter(fn (ActivitySlot $slot): bool => $this->slotKind->isFillIn($slot))
            ->max('position_in_group')) + 1;
    }

    private function nextFillInSlotKey(Activity $activity, int $position): string
    {
        $slotKeys = $activity->slots->pluck('slot_key')->flip();
        $candidate = sprintf('fill-in-slot-%d', $position);

        while ($slotKeys->has($candidate)) {
            $position++;
            $candidate = sprintf('fill-in-slot-%d', $position);
        }

        return $candidate;
    }

    /**
     * @return array<string, string>
     */
    private function fillInGroupLabel(): array
    {
        return [
            'en' => 'Fill-ins',
            'de' => 'Fill-ins',
            'fr' => 'Fill-ins',
            'ja' => 'Fill-ins',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function fillInSlotLabel(int $position): array
    {
        return [
            'en' => sprintf('Fill in %d', $position),
            'de' => sprintf('Fill in %d', $position),
            'fr' => sprintf('Fill in %d', $position),
            'ja' => sprintf('Fill in %d', $position),
        ];
    }
}
