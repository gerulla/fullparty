<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ActivityCancellationService
{
    public const DEFAULT_REVIEW_REASON = 'Run cancelled.';

    public function __construct(
        private readonly GroupActivityAuditService $activityAuditService,
    ) {}

    /**
     * @return Collection<int, ActivityApplication>
     */
    public function cancel(Activity $activity, mixed $actor): Collection
    {
        $cancelledByUserId = is_int($actor) ? $actor : (int) $actor->id;

        $activity->loadMissing([
            'group',
            'slots.fieldValues',
            'applications.selectedCharacter',
            'applications.user',
        ]);

        /** @var Collection<int, ActivityApplication> $cancelledApplications */
        $cancelledApplications = DB::transaction(function () use ($activity, $cancelledByUserId) {
            $cancelledAt = now();

            $applicationsToCancel = $activity->applications
                ->filter(fn (ActivityApplication $application) => in_array($application->status, [
                    ActivityApplication::STATUS_PENDING,
                    ActivityApplication::STATUS_APPROVED,
                    ActivityApplication::STATUS_ON_BENCH,
                ], true))
                ->values();

            $applicationsToCancel->each(function (ActivityApplication $application) use ($cancelledByUserId, $cancelledAt): void {
                $application->update([
                    'status' => ActivityApplication::STATUS_CANCELLED,
                    'reviewed_by_user_id' => $cancelledByUserId,
                    'reviewed_at' => $cancelledAt,
                    'review_reason' => self::DEFAULT_REVIEW_REASON,
                ]);
            });

            $activity->slotAssignments()
                ->whereNull('ended_at')
                ->update([
                    'ended_at' => $cancelledAt,
                ]);

            $activity->slots->each(function (ActivitySlot $slot): void {
                if ($slot->assigned_character_id !== null || $slot->assigned_by_user_id !== null) {
                    $slot->update([
                        'assigned_character_id' => null,
                        'assigned_by_user_id' => null,
                    ]);
                }

                foreach ($slot->fieldValues as $fieldValue) {
                    if ($fieldValue->value !== null) {
                        $fieldValue->update([
                            'value' => null,
                        ]);
                    }
                }
            });

            $activity->update([
                'status' => Activity::STATUS_CANCELLED,
            ]);

            return $applicationsToCancel;
        });

        // TODO: Notify affected applicants and rostered characters that this run was cancelled.
        $cancelledApplications->each(function (ActivityApplication $application) use ($actor): void {
            $application->loadMissing(['activity.group', 'selectedCharacter', 'user']);
            $this->activityAuditService->logApplicationCancelled($application, $actor);
        });

        return $cancelledApplications;
    }
}
