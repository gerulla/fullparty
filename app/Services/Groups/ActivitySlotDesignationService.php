<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\User;
use App\Services\Notifications\AssignmentNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ActivitySlotDesignationService
{
    public function __construct(
        private readonly ActivitySlotBench $slotBench,
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
        ?int $expectedCurrentDesignationSlotId,
    ): array {
        $activity = $slot->activity;

        if (!$activity instanceof Activity) {
            throw ValidationException::withMessages([
                'slot' => 'The selected slot is not attached to an activity.',
            ]);
        }

        if (!$slot->assigned_character_id) {
            throw ValidationException::withMessages([
                'slot' => 'Only assigned roster slots can be marked with run designations.',
            ]);
        }

        if ($this->slotBench->isBench($slot)) {
            throw ValidationException::withMessages([
                'slot' => 'Bench slots cannot be marked as host or raid leader.',
            ]);
        }

        $column = ActivitySlot::designationColumn($designation);
        $oppositeDesignation = $designation === ActivitySlot::DESIGNATION_HOST
            ? ActivitySlot::DESIGNATION_RAID_LEADER
            : ActivitySlot::DESIGNATION_HOST;
        $oppositeColumn = ActivitySlot::designationColumn($oppositeDesignation);
        $actor = User::query()->find($actorUserId);

        /** @var array{updated_slots: array<int, ActivitySlot>, notifications: array<int, array{slot: ActivitySlot, designation: string, assigned: bool}>} $result */
        $result = DB::transaction(function () use ($activity, $slot, $column, $designation, $oppositeColumn, $oppositeDesignation, $expectedCurrentDesignationSlotId, $actorUserId, $actor) {
            $targetSlot = ActivitySlot::query()
                ->with(['activity.group', 'assignedCharacter', 'fieldValues', 'assignments'])
                ->lockForUpdate()
                ->findOrFail($slot->id);

            $currentDesignationSlot = ActivitySlot::query()
                ->with(['activity.group', 'assignedCharacter', 'fieldValues', 'assignments'])
                ->where('activity_id', $activity->id)
                ->where($column, true)
                ->lockForUpdate()
                ->first();

            $currentDesignationSlotId = $currentDesignationSlot?->id;

            if ($currentDesignationSlotId !== $expectedCurrentDesignationSlotId) {
                throw new ConflictHttpException('This slot changed while you were editing it. Refresh and try again.');
            }

            $shouldAssignDesignation = !($currentDesignationSlot
                && (int) $currentDesignationSlot->id === (int) $targetSlot->id
                && (bool) $targetSlot->{$column});

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

            if ($currentDesignationSlot && (int) $currentDesignationSlot->id !== (int) $targetSlot->id) {
                $currentDesignationSlot->update([$column => false]);
                $updatedPreviousSlot = $currentDesignationSlot->fresh(['activity.group', 'assignedCharacter', 'fieldValues', 'assignments']);

                if ($updatedPreviousSlot) {
                    $updatedSlots[$updatedPreviousSlot->id] = $updatedPreviousSlot;

                    $this->activityAuditService->logRosterEvent(
                        sprintf('%s_cleared', $designation),
                        $updatedPreviousSlot,
                        $actor ?? $actorUserId,
                        [
                            'designation' => $designation,
                            'moved_to_slot_label' => $targetSlot->slot_label['en'] ?? $targetSlot->slot_key,
                            'moved_to_group_label' => $targetSlot->group_label['en'] ?? $targetSlot->group_key,
                        ],
                    );

                    if ($updatedPreviousSlot->assignedCharacter) {
                        $pendingNotifications[] = [
                            'slot' => $updatedPreviousSlot,
                            'designation' => $designation,
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

            if (!$character) {
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
                && !$this->slotBench->isBench($slot)
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

            if (!$updatedSlot) {
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
