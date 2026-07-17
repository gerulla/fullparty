<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\User;
use App\Services\Notifications\AssignmentNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivitySlotDesignationService
{
    public function __construct(
        private readonly ActivitySlotKind $slotKind,
        private readonly GroupActivityAuditService $activityAuditService,
        private readonly AssignmentNotificationService $assignmentNotificationService,
    ) {}

    /**
     * @return array<int, ActivitySlot>
     */
    public function toggleDesignation(
        ActivitySlot $slot,
        string $designation,
        int $actorUserId,
    ): array {
        $activity = $slot->activity;

        if (! $activity instanceof Activity) {
            throw ValidationException::withMessages([
                'slot' => 'The selected slot is not attached to an activity.',
            ]);
        }

        if (! $slot->assigned_character_id) {
            throw ValidationException::withMessages([
                'slot' => 'Only assigned roster slots can be marked with run designations.',
            ]);
        }

        if (! $this->slotKind->isMainRoster($slot)) {
            throw ValidationException::withMessages([
                'slot' => 'Only main roster slots can be marked as host or raid leader.',
            ]);
        }

        $column = ActivitySlot::designationColumn($designation);
        $oppositeDesignation = $designation === ActivitySlot::DESIGNATION_HOST
            ? ActivitySlot::DESIGNATION_RAID_LEADER
            : ActivitySlot::DESIGNATION_HOST;
        $oppositeColumn = ActivitySlot::designationColumn($oppositeDesignation);
        $actor = User::query()->find($actorUserId);

        /** @var array{updated_slots: array<int, ActivitySlot>, notifications: array<int, array{slot: ActivitySlot, designation: string, assigned: bool}>} $result */
        $result = DB::transaction(function () use ($slot, $column, $designation, $oppositeColumn, $oppositeDesignation, $actorUserId, $actor) {
            $targetSlot = ActivitySlot::query()
                ->with(['activity.group', 'assignedCharacter', 'fieldValues', 'assignments'])
                ->lockForUpdate()
                ->findOrFail($slot->id);

            $shouldAssignDesignation = ! (bool) $targetSlot->{$column};

            $updatedSlots = [];
            $pendingNotifications = [];

            if ((bool) $targetSlot->{$oppositeColumn} && $shouldAssignDesignation) {
                $targetSlot->update([$oppositeColumn => false]);
                $updatedTargetSlot = $targetSlot->fresh(['activity.group', 'assignedCharacter', 'fieldValues', 'assignments']);

                if ($updatedTargetSlot) {
                    $targetSlot = $updatedTargetSlot;
                    $updatedSlots[$updatedTargetSlot->id] = $updatedTargetSlot;

                    $this->activityAuditService->logRosterEvent(
                        sprintf('%s_cleared', $oppositeDesignation),
                        $updatedTargetSlot,
                        $actor ?? $actorUserId,
                        [
                            'designation' => $oppositeDesignation,
                            'replaced_by_designation' => $designation,
                        ],
                    );

                    if ($updatedTargetSlot->assignedCharacter) {
                        $pendingNotifications[] = [
                            'slot' => $updatedTargetSlot,
                            'designation' => $oppositeDesignation,
                            'assigned' => false,
                        ];
                    }
                }
            }

            $targetSlot->update([$column => $shouldAssignDesignation]);
            $updatedTargetSlot = $targetSlot->fresh(['activity.group', 'assignedCharacter', 'fieldValues', 'assignments']);

            if ($updatedTargetSlot) {
                $updatedSlots[$updatedTargetSlot->id] = $updatedTargetSlot;

                $this->activityAuditService->logRosterEvent(
                    sprintf('%s_%s', $designation, $shouldAssignDesignation ? 'marked' : 'cleared'),
                    $updatedTargetSlot,
                    $actor ?? $actorUserId,
                    [
                        'designation' => $designation,
                    ],
                );

                if ($updatedTargetSlot->assignedCharacter) {
                    $pendingNotifications[] = [
                        'slot' => $updatedTargetSlot,
                        'designation' => $designation,
                        'assigned' => $shouldAssignDesignation,
                    ];
                }
            }

            return [
                'updated_slots' => array_values($updatedSlots),
                'notifications' => $pendingNotifications,
            ];
        });

        foreach ($result['notifications'] as $notification) {
            $slotForNotification = $notification['slot'];
            $character = $slotForNotification->assignedCharacter;

            if (! $character) {
                continue;
            }

            $this->assignmentNotificationService->notifyDesignationChanged(
                $activity,
                $character,
                $slotForNotification,
                $notification['designation'],
                $notification['assigned'],
                $actor ?? $actorUserId,
            );
        }

        return $result['updated_slots'];
    }

    /**
     * @param  iterable<int, ActivitySlot>  $slots
     * @return array<int, ActivitySlot>
     */
    public function clearInvalidDesignations(iterable $slots, mixed $actor = null): array
    {
        $updatedSlots = [];

        foreach ($slots as $slot) {
            $slot->loadMissing(['activity.group', 'assignedCharacter', 'fieldValues', 'assignments']);

            if (
                $slot->assigned_character_id !== null
                && $this->slotKind->isMainRoster($slot)
            ) {
                continue;
            }

            $removedDesignations = collect(ActivitySlot::DESIGNATION_COLUMN_MAP)
                ->filter(fn (string $column) => (bool) $slot->{$column})
                ->keys()
                ->values();

            if ($removedDesignations->isEmpty()) {
                continue;
            }

            $slot->update([
                'is_host' => false,
                'is_raid_leader' => false,
            ]);

            $updatedSlot = $slot->fresh(['activity.group', 'assignedCharacter', 'fieldValues', 'assignments']);

            if (! $updatedSlot) {
                continue;
            }

            foreach ($removedDesignations as $designation) {
                $this->activityAuditService->logRosterEvent(
                    sprintf('%s_cleared', $designation),
                    $updatedSlot,
                    $actor,
                    [
                        'designation' => $designation,
                    ],
                );
            }

            $updatedSlots[$updatedSlot->id] = $updatedSlot;
        }

        return array_values($updatedSlots);
    }
}
