<?php

namespace App\Services\Notifications;

use App\Models\SystemNotificationBroadcast;
use App\Models\User;
use App\Support\Notifications\NotificationCategory;
use App\Jobs\DispatchSystemNotificationBroadcastJob;
use Carbon\CarbonInterface;

class SystemNotificationService
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function sendUpcomingMaintenance(
        User $actor,
        string $headline,
        string $message,
        ?CarbonInterface $scheduledFor = null,
        ?string $actionUrl = null,
    ): SystemNotificationBroadcast {
        $messageParams = [
            'headline' => $headline,
            'message' => $message,
        ];

        $bodyKey = 'notifications.system.maintenance.body';

        if ($scheduledFor) {
            $messageParams['scheduled_for'] = $scheduledFor->setTimezone(config('app.timezone'))->format('Y-m-d H:i T');
            $bodyKey = 'notifications.system.maintenance.body_with_schedule';
        }

        $event = $this->notificationService->createEvent(
            type: 'system.maintenance.upcoming',
            category: NotificationCategory::SYSTEM_NOTICES,
            titleKey: 'notifications.system.maintenance.title',
            bodyKey: $bodyKey,
            messageParams: $messageParams,
            actionUrl: $actionUrl,
            actor: $actor,
            payload: [
                'kind' => 'maintenance',
                'headline' => $headline,
                'message' => $message,
                'scheduled_for' => $scheduledFor?->toIso8601String(),
            ],
            isMandatory: true,
        );

        $broadcast = SystemNotificationBroadcast::query()->create([
            'notification_event_id' => $event->id,
        ]);

        DispatchSystemNotificationBroadcastJob::dispatch($broadcast->id);

        return $broadcast;
    }

    public function sendAnnouncement(
        User $actor,
        string $headline,
        string $message,
        ?string $actionUrl = null,
    ): SystemNotificationBroadcast {
        $event = $this->notificationService->createEvent(
            type: 'system.announcement',
            category: NotificationCategory::SYSTEM_NOTICES,
            titleKey: 'notifications.system.announcement.title',
            bodyKey: 'notifications.system.announcement.body',
            messageParams: [
                'headline' => $headline,
                'message' => $message,
            ],
            actionUrl: $actionUrl,
            actor: $actor,
            payload: [
                'kind' => 'announcement',
                'headline' => $headline,
                'message' => $message,
            ],
            isMandatory: false,
        );

        $broadcast = SystemNotificationBroadcast::query()->create([
            'notification_event_id' => $event->id,
        ]);

        DispatchSystemNotificationBroadcastJob::dispatch($broadcast->id);

        return $broadcast;
    }
}
