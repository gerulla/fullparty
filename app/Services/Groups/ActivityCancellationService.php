<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Services\Integrations\DiscordGuildRunsChangedService;
use App\Services\Notifications\ApplicationNotificationService;
use App\Services\Notifications\RunNotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ActivityCancellationService
{
    public const DEFAULT_REVIEW_REASON = 'Run cancelled.';

    public function __construct(
        private readonly GroupActivityAuditService $activityAuditService,
        private readonly ApplicationNotificationService $applicationNotificationService,
        private readonly RunNotificationService $runNotificationService,
        private readonly DiscordGuildRunsChangedService $discordRunsChanged,
    ) {}

    /**
     * @return Collection<int, ActivityApplication>
     */
    public function cancel(Activity $activity, mixed $actor, ?string $reason = null): Collection
    {
        $cancelledByUserId = is_int($actor) ? $actor : (int) $actor->id;
        $cancellationReason = filled($reason) ? trim((string) $reason) : null;
        $reviewReason = filled($reason)
            ? trim((string) $reason)
            : self::DEFAULT_REVIEW_REASON;

        $activity->loadMissing([
            'group',
            'slots.fieldValues',
            'applications.selectedCharacter',
            'applications.user',
        ]);

        $placedApplications = $activity->applications
            ->filter(fn (ActivityApplication $application) => in_array($application->status, [
                ActivityApplication::STATUS_APPROVED,
                ActivityApplication::STATUS_ON_BENCH,
            ], true))
            ->values();

        /** @var Collection<int, ActivityApplication> $cancelledApplications */
        $cancelledApplications = DB::transaction(function () use ($activity, $cancelledByUserId, $reviewReason, $cancellationReason) {
            $cancelledAt = now();

            $applicationsToCancel = $activity->applications
                ->filter(fn (ActivityApplication $application) => in_array($application->status, [
                    ActivityApplication::STATUS_PENDING,
                    ActivityApplication::STATUS_APPROVED,
                    ActivityApplication::STATUS_ON_BENCH,
                ], true))
                ->values();

            $applicationsToCancel->each(function (ActivityApplication $application) use ($cancelledByUserId, $cancelledAt, $reviewReason): void {
                $application->update([
                    'status' => ActivityApplication::STATUS_CANCELLED,
                    'guest_access_token' => null,
                    'reviewed_by_user_id' => $cancelledByUserId,
                    'reviewed_at' => $cancelledAt,
                    'review_reason' => $reviewReason,
                ]);
            });

            $activity->slotAssignments()
                ->whereNull('ended_at')
                ->update([
                    'ended_at' => $cancelledAt,
                ]);

            $activity->update([
                'status' => Activity::STATUS_CANCELLED,
                'settings' => array_merge(
                    $activity->settings ?? [],
                    [
                        Activity::SETTING_CANCELLATION_REASON => $cancellationReason,
                    ],
                ),
            ]);

            $this->discordRunsChanged->notifyChanged($activity);

            return $applicationsToCancel;
        });

        $cancelledApplications->each(function (ActivityApplication $application) use ($actor): void {
            $application->loadMissing(['activity.group', 'selectedCharacter', 'user']);
            $this->activityAuditService->logApplicationCancelled($application, $actor);
        });

        $this->runNotificationService->notifyCancelled(
            $activity->fresh(['group', 'applications.user', 'applications.selectedCharacter', 'slots.assignedCharacter.user']),
            $actor,
            $cancelledApplications,
            $cancellationReason,
            $placedApplications,
        );

        return $cancelledApplications;
    }
}
