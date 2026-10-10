<?php

namespace App\Services\Notifications;

use App\Models\Activity;
use App\Models\IntegrationClient;
use App\Models\NotificationDelivery;
use App\Services\Integrations\IntegrationWebhookDispatcher;
use App\Support\Notifications\NotificationChannel;

class DiscordNotificationDeliveryService
{
    public function __construct(
        private readonly IntegrationWebhookDispatcher $webhookDispatcher,
        private readonly NotificationActionUrlService $notificationActionUrlService,
        private readonly RunReminderContactPayloadBuilder $reminderContacts,
    ) {}

    public function send(NotificationDelivery $delivery): void
    {
        if ($delivery->channel !== NotificationChannel::DISCORD) {
            return;
        }

        if ($delivery->status !== NotificationDelivery::STATUS_PENDING) {
            return;
        }

        $delivery->loadMissing(['notificationEvent', 'user']);

        if (! $delivery->notificationEvent || ! $delivery->user || ! filled($delivery->target)) {
            $delivery->update([
                'status' => NotificationDelivery::STATUS_SKIPPED,
                'status_reason' => 'missing_discord_account',
                'skipped_at' => now(),
                'failed_at' => null,
                'sent_at' => null,
            ]);

            return;
        }

        $result = $this->webhookDispatcher->dispatchDiscordBotEvent(
            IntegrationClient::EVENT_DISCORD_NOTIFICATION_DELIVERY,
            [
                'notification_delivery_id' => $delivery->id,
                'notification_event_id' => $delivery->notification_event_id,
                'type' => $delivery->notificationEvent->type,
                'category' => $delivery->notificationEvent->category,
                'user' => [
                    'id' => $delivery->user->id,
                    'name' => $delivery->user->name,
                ],
                'discord_user' => [
                    'id' => $delivery->target,
                ],
                'notification' => [
                    'type' => $delivery->notificationEvent->type,
                    'category' => $delivery->notificationEvent->category,
                    'params' => $delivery->notificationEvent->message_params ?? [],
                    'action_url' => $this->notificationActionUrlService->forBrowserLocalePreference($delivery->notificationEvent->action_url),
                    'payload' => $this->notificationPayload($delivery),
                ],
            ],
        );

        if ($result['sent'] > 0) {
            $delivery->update([
                'status' => NotificationDelivery::STATUS_SENT,
                'status_reason' => null,
                'sent_at' => now(),
                'failed_at' => null,
                'skipped_at' => null,
                'response_payload' => $result,
            ]);

            return;
        }

        if ($result['attempted'] > 0) {
            $delivery->update([
                'status' => NotificationDelivery::STATUS_FAILED,
                'status_reason' => 'discord_delivery_failed',
                'failed_at' => now(),
                'sent_at' => null,
                'skipped_at' => null,
                'response_payload' => $result,
            ]);

            return;
        }

        $delivery->update([
            'status' => NotificationDelivery::STATUS_SKIPPED,
            'status_reason' => 'discord_transport_unavailable',
            'skipped_at' => now(),
            'failed_at' => null,
            'sent_at' => null,
            'response_payload' => $result,
        ]);
    }

    private function notificationPayload(NotificationDelivery $delivery): ?array
    {
        $event = $delivery->notificationEvent;
        if (! in_array($event->type, ['runs.starting_soon', 'runs.starting_now'], true)) {
            return $event->payload;
        }

        $activity = $event->subject;

        return array_merge($event->payload ?? [], $activity instanceof Activity
            ? $this->reminderContacts->forRecipient($activity, $delivery->user_id)
            : ['party_leads' => [], 'run_host' => null]);
    }
}
