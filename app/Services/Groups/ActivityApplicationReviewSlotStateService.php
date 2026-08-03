<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActivityApplicationReviewSlotStateService
{
    public function __construct(
        private readonly ActivityFillInSlotService $fillInSlotService,
        private readonly ActivitySlotAttendanceService $attendanceService,
        private readonly ActivitySlotDesignationService $slotDesignationService,
        private readonly ActivitySlotSerializer $slotSerializer,
    ) {}

    /**
     * @return array<int, int>
     */
    public function markAssignedSlotsForReview(Activity $activity, ActivityApplication $application): array
    {
        $slots = $this->assignedSlotsForApplication($activity, $application, lockForUpdate: true);

        if ($slots->isEmpty()) {
            return [];
        }

        $reviewRequiredAt = now();

        foreach ($slots as $slot) {
            $slot->update([
                'application_review_required_application_id' => $application->id,
                'application_review_required_at' => $reviewRequiredAt,
            ]);
        }

        return $slots
            ->map(fn (ActivitySlot $slot) => (int) $slot->id)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $slotIds
     * @return array<int, array<string, mixed>>
     */
    public function serializeSlotsByIds(Activity $activity, array $slotIds): array
    {
        if ($slotIds === []) {
            return [];
        }

        return $this->serializeSlots(
            $this->slotQuery($activity)
                ->whereIn('id', array_values(array_unique($slotIds)))
                ->get()
        );
    }

    /**
     * @param  array<int, int>  $exceptSlotIds
     * @return array{updated_slots: array<int, array<string, mixed>>, removed_slot_ids: array<int, int>}
     */
    public function releaseFlaggedAssignmentsForApplication(
        Activity $activity,
        ActivityApplication $application,
        mixed $actor = null,
        array $exceptSlotIds = [],
    ): array {
        $slots = $this->flaggedSlotsForApplication($activity, $application, $exceptSlotIds, lockForUpdate: true);

        if ($slots->isEmpty()) {
            return [
                'updated_slots' => [],
                'removed_slot_ids' => [],
            ];
        }

        $updatedSlots = [];
        $removedSlotIds = [];

        foreach ($slots as $slot) {
            $characterId = $slot->assigned_character_id ? (int) $slot->assigned_character_id : null;

            $slot->update([
                'assigned_character_id' => null,
                'assigned_by_user_id' => null,
                'application_review_required_application_id' => null,
                'application_review_required_at' => null,
            ]);

            foreach ($slot->fieldValues as $fieldValue) {
                $fieldValue->update([
                    'value' => null,
                ]);
            }

            if ($characterId !== null) {
                $this->attendanceService->endActiveAssignment($activity, $characterId);
            }

            $this->slotDesignationService->clearInvalidDesignations([$slot], $actor);

            if ($slot->slot_kind === ActivitySlot::SLOT_KIND_FILL_IN) {
                $removedSlotIds[] = (int) $slot->id;
                $this->fillInSlotService->delete($activity, $slot);

                continue;
            }

            $slot->load(['assignedCharacter', 'fieldValues', 'assignments']);
            $updatedSlots[] = $this->slotSerializer->serialize($slot);
        }

        return [
            'updated_slots' => $updatedSlots,
            'removed_slot_ids' => array_values(array_unique($removedSlotIds)),
        ];
    }

    /**
     * @return EloquentCollection<int, ActivitySlot>
     */
    private function assignedSlotsForApplication(
        Activity $activity,
        ActivityApplication $application,
        bool $lockForUpdate = false,
    ): EloquentCollection {
        $slotIds = ActivitySlotAssignment::query()
            ->where('activity_id', $activity->id)
            ->where('application_id', $application->id)
            ->whereNull('ended_at')
            ->pluck('activity_slot_id')
            ->map(fn ($slotId) => (int) $slotId)
            ->all();

        if ($slotIds !== []) {
            return $this->slotQuery($activity, $lockForUpdate)
                ->whereIn('id', $slotIds)
                ->get();
        }

        if (! $application->selected_character_id || ! in_array($application->status, [
            ActivityApplication::STATUS_APPROVED,
            ActivityApplication::STATUS_ON_BENCH,
        ], true)) {
            return new EloquentCollection;
        }

        return $this->slotQuery($activity, $lockForUpdate)
            ->where('assigned_character_id', $application->selected_character_id)
            ->get();
    }

    /**
     * @param  array<int, int>  $exceptSlotIds
     * @return EloquentCollection<int, ActivitySlot>
     */
    private function flaggedSlotsForApplication(
        Activity $activity,
        ActivityApplication $application,
        array $exceptSlotIds = [],
        bool $lockForUpdate = false,
    ): EloquentCollection {
        return $this->slotQuery($activity, $lockForUpdate)
            ->where('application_review_required_application_id', $application->id)
            ->when($exceptSlotIds !== [], fn ($query) => $query->whereNotIn('id', $exceptSlotIds))
            ->get();
    }

    private function slotQuery(Activity $activity, bool $lockForUpdate = false): HasMany
    {
        return $activity->slots()
            ->with(['assignedCharacter', 'fieldValues', 'assignments'])
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate());
    }

    /**
     * @param  EloquentCollection<int, ActivitySlot>  $slots
     * @return array<int, array<string, mixed>>
     */
    private function serializeSlots(EloquentCollection $slots): array
    {
        return $slots
            ->map(function (ActivitySlot $slot): array {
                $slot->load(['assignedCharacter', 'fieldValues', 'assignments']);

                return $this->slotSerializer->serialize($slot);
            })
            ->values()
            ->all();
    }
}
