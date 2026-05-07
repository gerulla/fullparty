<?php

namespace App\Services\Notifications;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\User;
use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\NotificationChannel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class RunNotificationService
{
    private const STARTING_SOON_FLAG = 'run_notification_starting_soon_sent_at';
    private const STARTING_NOW_FLAG = 'run_notification_starting_now_sent_at';
    private const STARTING_SOON_MINUTES = 60;

    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * @param  Collection<int, ActivityApplication>|null  $applications
     */
    public function notifyCancelled(Activity $activity, mixed $actor, ?Collection $applications = null): void
    {
        $recipients = $applications instanceof Collection
            ? $this->recipientsFromApplications($applications)
            : $this->activeApplicantRecipients($activity);

        $this->sendRunNotification(
            activity: $activity,
            recipients: $recipients,
            type: 'runs.cancelled',
            titleKey: 'notifications.runs.cancelled.title',
            bodyKey: 'notifications.runs.cancelled.body',
            actor: $actor,
        );
    }

    public function notifyCompleted(Activity $activity, mixed $actor): void
    {
        $this->sendRunNotification(
            activity: $activity,
            recipients: $this->placedApplicantRecipients($activity),
            type: 'runs.completed',
            titleKey: 'notifications.runs.completed.title',
            bodyKey: 'notifications.runs.completed.body',
            actor: $actor,
        );
    }

    /**
     * @return array{starting_soon: int, starting_now: int}
     */
    public function dispatchDueReminders(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $soonCutoff = $now->addMinutes(self::STARTING_SOON_MINUTES);

        $startingSoonCount = 0;

        Activity::query()
            ->with(['group', 'applications.user', 'applications.selectedCharacter'])
            ->whereIn('status', [
                Activity::STATUS_ASSIGNED,
                Activity::STATUS_UPCOMING,
                Activity::STATUS_ONGOING,
            ])
            ->whereNotNull('starts_at')
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $soonCutoff)
            ->orderBy('starts_at')
            ->get()
            ->each(function (Activity $activity) use ($now, &$startingSoonCount): void {
                if ($this->reminderAlreadySent($activity, self::STARTING_SOON_FLAG)) {
                    return;
                }

                $this->notifyStartingSoon($activity);
                $this->markReminderSent($activity, self::STARTING_SOON_FLAG, $now);
                $startingSoonCount++;
            });

        $startingNowCount = 0;

        Activity::query()
            ->with(['group', 'applications.user', 'applications.selectedCharacter'])
            ->whereIn('status', [
                Activity::STATUS_ASSIGNED,
                Activity::STATUS_UPCOMING,
                Activity::STATUS_ONGOING,
            ])
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', $now)
            ->orderBy('starts_at')
            ->get()
            ->each(function (Activity $activity) use ($now, &$startingNowCount): void {
                if ($this->reminderAlreadySent($activity, self::STARTING_NOW_FLAG)) {
                    return;
                }

                $this->notifyStartingNow($activity);
                $this->markReminderSent($activity, self::STARTING_NOW_FLAG, $now);
                $startingNowCount++;
            });

        return [
            'starting_soon' => $startingSoonCount,
            'starting_now' => $startingNowCount,
        ];
    }

    private function notifyStartingSoon(Activity $activity): void
    {
        $this->sendRunNotification(
            activity: $activity,
            recipients: $this->placedApplicantRecipients($activity),
            type: 'runs.starting_soon',
            titleKey: 'notifications.runs.starting_soon.title',
            bodyKey: 'notifications.runs.starting_soon.body',
        );
    }

    private function notifyStartingNow(Activity $activity): void
    {
        $this->sendRunNotification(
            activity: $activity,
            recipients: $this->placedApplicantRecipients($activity),
            type: 'runs.starting_now',
            titleKey: 'notifications.runs.starting_now.title',
            bodyKey: 'notifications.runs.starting_now.body',
        );
    }

    private function createEvent(
        Activity $activity,
        string $type,
        string $titleKey,
        string $bodyKey,
        mixed $actor = null,
    ): \App\Models\NotificationEvent {
        $activity->loadMissing('group');

        return $this->notificationService->createEvent(
            type: $type,
            category: NotificationCategory::RUNS_AND_REMINDERS,
            titleKey: $titleKey,
            bodyKey: $bodyKey,
            messageParams: [
                'activity' => $this->activityTitle($activity),
                'group' => $activity->group?->name,
            ],
            actionUrl: route('account.applications'),
            actor: $actor instanceof User ? $actor : null,
            subject: $activity,
            payload: [
                'activity_id' => $activity->id,
                'group_id' => $activity->group?->id,
                'group_slug' => $activity->group?->slug,
                'activity_title' => $this->activityTitle($activity),
                'status' => $activity->status,
                'starts_at' => $activity->starts_at?->toIso8601String(),
            ],
        );
    }

    /**
     * @param  EloquentCollection<int, User>  $recipients
     */
    private function sendRunNotification(
        Activity $activity,
        EloquentCollection $recipients,
        string $type,
        string $titleKey,
        string $bodyKey,
        mixed $actor = null,
    ): void {
        if ($recipients->isEmpty()) {
            return;
        }

        $event = $this->createEvent(
            activity: $activity,
            type: $type,
            titleKey: $titleKey,
            bodyKey: $bodyKey,
            actor: $actor,
        );

        $this->notificationService->sendInAppNotifications($event, $recipients);
        $this->notificationService->sendOffSiteNotifications($event, $recipients, [
            NotificationChannel::EMAIL,
            NotificationChannel::DISCORD,
        ]);
    }

    /**
     * @return EloquentCollection<int, User>
     */
    private function activeApplicantRecipients(Activity $activity): EloquentCollection
    {
        return $this->applicantRecipientsForStatuses($activity, [
            ActivityApplication::STATUS_PENDING,
            ActivityApplication::STATUS_APPROVED,
            ActivityApplication::STATUS_ON_BENCH,
        ]);
    }

    /**
     * @return EloquentCollection<int, User>
     */
    private function placedApplicantRecipients(Activity $activity): EloquentCollection
    {
        return $this->applicantRecipientsForStatuses($activity, [
            ActivityApplication::STATUS_APPROVED,
            ActivityApplication::STATUS_ON_BENCH,
        ]);
    }

    /**
     * @param  Collection<int, ActivityApplication>  $applications
     * @return EloquentCollection<int, User>
     */
    private function recipientsFromApplications(Collection $applications): EloquentCollection
    {
        return new EloquentCollection(
            $applications
                ->map(function (ActivityApplication $application) {
                    $application->loadMissing('user');

                    return $application->user;
                })
                ->filter(fn ($user) => $user instanceof User && $user->run_and_reminder_notifications)
                ->unique('id')
                ->values()
                ->all()
        );
    }

    /**
     * @param  array<int, string>  $statuses
     * @return EloquentCollection<int, User>
     */
    private function applicantRecipientsForStatuses(Activity $activity, array $statuses): EloquentCollection
    {
        $activity->loadMissing('applications.user');

        return new EloquentCollection(
            $activity->applications
                ->filter(fn (ActivityApplication $application) => in_array($application->status, $statuses, true))
                ->map(fn (ActivityApplication $application) => $application->user)
                ->filter(fn ($user) => $user instanceof User && $user->run_and_reminder_notifications)
                ->unique('id')
                ->values()
                ->all()
        );
    }

    private function activityTitle(Activity $activity): string
    {
        if (filled($activity->title)) {
            return (string) $activity->title;
        }

        return sprintf('Activity #%d', $activity->id);
    }

    private function reminderAlreadySent(Activity $activity, string $key): bool
    {
        return filled(($activity->settings ?? [])[$key] ?? null);
    }

    private function markReminderSent(Activity $activity, string $key, CarbonImmutable $timestamp): void
    {
        $settings = $activity->settings ?? [];
        $settings[$key] = $timestamp->toIso8601String();

        $activity->forceFill([
            'settings' => $settings,
        ])->save();
    }
}
