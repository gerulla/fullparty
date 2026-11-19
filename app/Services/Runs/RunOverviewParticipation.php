<?php

namespace App\Services\Runs;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use App\Models\Group;
use App\Services\Groups\ActivitySlotKind;
use App\Services\Groups\ActivitySlotStateTokenService;

final class RunOverviewParticipation
{
    public function __construct(
        private readonly ActivitySlotKind $slotKind,
        private readonly ActivitySlotStateTokenService $slotState,
    ) {}

    /** @return array<string, mixed>|null */
    public function forViewer(Group $group, Activity $activity, ?int $userId): ?array
    {
        if ($userId === null) {
            return null;
        }

        $application = $activity->applications()
            ->where('user_id', $userId)
            ->with('selectedCharacter:id,user_id,name,avatar_url,world,datacenter')
            ->orderByRaw('CASE WHEN status = ? THEN 1 ELSE 0 END', [ActivityApplication::STATUS_WITHDRAWN])
            ->orderByDesc('id')
            ->first();

        $ownedSlots = $activity->slots->filter(function (ActivitySlot $slot) use ($userId, $application): bool {
            if ((int) $slot->assignedCharacter?->user_id !== $userId) {
                return false;
            }

            $assignment = $this->slotState->resolveActiveAssignment($slot);
            if ($assignment?->application_id !== null) {
                return $application && (int) $assignment->application_id === (int) $application->id
                    && in_array($application->status, ActivityApplication::ACTIVE_STATUSES, true);
            }

            // Older manual placements can have an occupant without assignment history.
            return $assignment !== null || ! $slot->assignments->contains('character_id', $slot->assigned_character_id);
        });
        $slot = $application ? $ownedSlots->firstWhere('application_review_required_application_id', $application->id)
            ?? $ownedSlots->firstWhere('assigned_character_id', $application->selected_character_id) : null;
        $slot ??= $ownedSlots->first();

        if (! $application && ! $slot) {
            return null;
        }

        $character = $application?->selectedCharacter;
        if ((int) $character?->user_id !== $userId) {
            $character = null;
        }
        $canOpenApplication = $application && ! $activity->isArchived() && $application->status !== ActivityApplication::STATUS_WITHDRAWN;
        // Marking someone missing clears the slot but retains the application decision.
        $markedMissing = ! $slot && $character && in_array($application->status, [ActivityApplication::STATUS_APPROVED, ActivityApplication::STATUS_ON_BENCH], true)
            && $activity->slotAssignments()->where('character_id', $character->id)
                ->where(fn ($query) => $query->where('application_id', $application->id)->orWhereNull('application_id'))
                ->orderByDesc('id')->value('attendance_status') === ActivitySlotAssignment::STATUS_MISSING;

        return [
            'application' => $application ? [
                'status' => $application->status,
                'marked_missing' => $markedMissing,
                'character_name' => $application->applicant_character_name ?? $character?->name,
                'avatar_url' => $application->applicant_avatar_url ?? $character?->avatar_url,
                'world' => $application->applicant_world ?? $character?->world,
                'datacenter' => $application->applicant_datacenter ?? $character?->datacenter,
                'can_edit' => $application->isEditableFor($activity),
                'url' => $canOpenApplication ? route('groups.activities.application', [$group, $activity]) : null,
                'view_url' => $canOpenApplication
                    ? route('groups.activities.application', ['group' => $group, 'activity' => $activity, 'view' => 'status'])
                    : null,
            ] : null,
            'placement' => $slot ? [
                'slot_id' => $slot->id,
                'slot_kind' => $this->slotKind->isBench($slot) ? ActivitySlot::SLOT_KIND_BENCH : ($slot->slot_kind ?? ActivitySlot::SLOT_KIND_ROSTER),
                'group_label' => $slot->filled_group_label ?? $slot->group_label ?? ['en' => $slot->group_key],
                'slot_label' => $slot->slot_label ?? ['en' => $slot->slot_key],
                'position_in_group' => $slot->position_in_group,
                'character_name' => $slot->assignedCharacter->name,
            ] : null,
        ];
    }
}
