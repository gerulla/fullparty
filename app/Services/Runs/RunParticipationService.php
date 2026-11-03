<?php

namespace App\Services\Runs;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use App\Models\Character;
use Illuminate\Database\Eloquent\Collection;

final class RunParticipationService
{
    public const ACTIVE_STATUSES = [
        ...ActivityApplication::ACTIVE_STATUSES,
        ActivitySlotAssignment::STATUS_ASSIGNED,
        ActivitySlotAssignment::STATUS_CHECKED_IN,
        ActivitySlotAssignment::STATUS_LATE,
        'filled_in',
    ];

    /**
     * Load only the viewer's participation, independently of public applicant previews.
     *
     * @param  Collection<int, Activity>  $activities
     * @return array<int, string>
     */
    public function statusesFor(Collection $activities, ?int $userId): array
    {
        if ($userId === null || $activities->isEmpty()) {
            return [];
        }

        $applications = ActivityApplication::query()
            ->whereIn('activity_id', $activities->modelKeys())
            ->where('user_id', $userId)
            ->where('id', ActivityApplication::query()
                ->from('activity_applications as viewer_application')
                ->select('viewer_application.id')
                ->whereColumn('viewer_application.activity_id', 'activity_applications.activity_id')
                ->where('viewer_application.user_id', $userId)
                // Reapplications supersede withdrawn attempts, regardless of edit time.
                ->orderByRaw('CASE WHEN viewer_application.status = ? THEN 1 ELSE 0 END', [ActivityApplication::STATUS_WITHDRAWN])
                ->orderByDesc('viewer_application.id')->limit(1))
            ->get(['id', 'activity_id', 'selected_character_id', 'status']);

        if ($applications->isEmpty()) {
            return [];
        }

        $participatingActivities = $activities->whereIn('id', $applications->pluck('activity_id'));
        $characterIds = Character::query()->select('id')->where('user_id', $userId)
            ->whereIn('id', $applications->pluck('selected_character_id')->filter());
        $participatingActivities->load([
            'slots' => fn ($query) => $query->whereIn('assigned_character_id', $characterIds)
                ->select('id', 'activity_id', 'assigned_character_id', 'slot_kind'),
            'slotAssignments' => fn ($query) => $query->whereIn('character_id', $characterIds)
                ->where('id', ActivitySlotAssignment::query()
                    ->from('activity_slot_assignments as latest_assignment')->select('latest_assignment.id')
                    ->whereColumn('latest_assignment.activity_id', 'activity_slot_assignments.activity_id')
                    ->whereColumn('latest_assignment.character_id', 'activity_slot_assignments.character_id')
                    ->orderByDesc('latest_assignment.assigned_at')->orderByDesc('latest_assignment.id')->limit(1))
                ->select('id', 'activity_id', 'character_id', 'activity_slot_id', 'attendance_status', 'ended_at'),
            'slotAssignments.slot:id,slot_kind',
        ]);
        $byId = $participatingActivities->keyBy('id');

        return $applications->mapWithKeys(function (ActivityApplication $application) use ($byId): array {
            $activity = $byId->get($application->activity_id);
            $application->setRelation('activity', $activity);

            return [$activity->id => $this->applicationStatus($application, $activity->starts_at?->isPast() ?? false)];
        })->all();
    }

    public function applicationStatus(ActivityApplication $application, bool $history): string
    {
        $activity = $application->activity;
        if ($activity->status === Activity::STATUS_CANCELLED) {
            return ActivityApplication::STATUS_CANCELLED;
        }

        $assignment = $activity->slotAssignments->firstWhere('character_id', $application->selected_character_id);
        $slot = $activity->slots->firstWhere('assigned_character_id', $application->selected_character_id);

        // Missing players leave the roster, but their ended assignment retains attendance.
        if ($history && $assignment) {
            if (in_array($assignment->attendance_status, [ActivitySlotAssignment::STATUS_MISSING, ActivitySlotAssignment::STATUS_LATE], true)) {
                return $assignment->attendance_status;
            }
            if ($assignment->attendance_status === ActivitySlotAssignment::STATUS_CHECKED_IN) {
                return $assignment->slot?->slot_kind === ActivitySlot::SLOT_KIND_FILL_IN
                    ? 'filled_in'
                    : ActivitySlotAssignment::STATUS_CHECKED_IN;
            }
        }

        if (! in_array($application->status, ActivityApplication::ACTIVE_STATUSES, true)) {
            return $application->status;
        }
        if ($slot && (! $assignment || $assignment->ended_at === null)) {
            return match ($slot->slot_kind) {
                ActivitySlot::SLOT_KIND_BENCH => ActivityApplication::STATUS_ON_BENCH,
                ActivitySlot::SLOT_KIND_FILL_IN => 'filled_in',
                default => ActivitySlotAssignment::STATUS_ASSIGNED,
            };
        }

        return $application->status;
    }
}
