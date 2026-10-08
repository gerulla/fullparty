<?php

namespace App\Services\Integrations;

use App\Models\IntegrationClient;
use App\Models\User;
use App\Services\Notifications\AdminReportService;
use App\Services\Notifications\NotificationService;
use App\Support\Notifications\AdminReportDiagnostics;
use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\NotificationTopic;
use Illuminate\Support\Str;

class IntegrationAdminNotificationService
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly AdminReportService $adminReports,
    ) {}

    public function notifyEventDeliveryFailed(IntegrationClient $client, string $event, string $error, array $details = []): void
    {
        // Keep failed admin reports in-app only, avoiding an alert delivery loop.
        if ($event !== IntegrationClient::EVENT_DISCORD_ADMIN_REPORT) {
            $this->adminReports->report(
                key: 'integration.delivery.'.$client->id.'.'.$event,
                titleKey: 'admin_reports.integration_delivery_title',
                messageKey: 'admin_reports.integration_delivery_message',
                params: ['client' => $client->name, 'event' => $event, 'id' => $client->id],
                details: [...$details, 'admin_url' => AdminReportDiagnostics::adminUrl('admin.integrations.index')],
            );
        }

        $admins = User::query()
            ->where('is_admin', true)
            ->get();

        if ($admins->isEmpty()) {
            return;
        }

        $notificationEvent = $this->notificationService->createEvent(
            type: 'integration.event_delivery_failed',
            category: NotificationCategory::SYSTEM_NOTICES,
            titleKey: 'notifications.integrations.delivery_failed.title',
            bodyKey: 'notifications.integrations.delivery_failed.body',
            messageParams: [
                'client' => $client->name,
                'event' => $event,
                'error' => Str::limit($error, 240, '...'),
            ],
            actionUrl: route('admin.integrations.index'),
            subject: $client,
            payload: [
                'integration_client_id' => $client->id,
                'integration_client_type' => $client->type,
                'event' => $event,
            ],
            isMandatory: true,
            topic: NotificationTopic::SYSTEM_ADMIN_ALERTS,
        );

        $this->notificationService->sendInAppNotifications($notificationEvent, $admins);
    }
}
