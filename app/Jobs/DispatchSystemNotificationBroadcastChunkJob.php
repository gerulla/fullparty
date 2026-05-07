<?php

namespace App\Jobs;

use App\Models\NotificationDelivery;
use App\Models\SystemNotificationBroadcast;
use App\Services\Notifications\NotificationDeliveryDispatcher;
use App\Services\Notifications\NotificationService;
use App\Support\Notifications\NotificationChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchSystemNotificationBroadcastChunkJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $broadcastId,
        public readonly int $minUserId,
        public readonly int $maxUserId,
    ) {}

    public function handle(
        NotificationService $notificationService,
        NotificationDeliveryDispatcher $deliveryDispatcher,
    ): void {
        $broadcast = SystemNotificationBroadcast::query()
            ->with('notificationEvent')
            ->find($this->broadcastId);

        $event = $broadcast?->notificationEvent;

        if (!$broadcast || !$event) {
            return;
        }

        $recipients = $notificationService->normalizeRecipientsForBroadcast(
            $notificationService->eligibleBroadcastRecipients(
                $event,
                $this->minUserId,
                $this->maxUserId,
            )
        );

        if ($recipients->isEmpty()) {
            return;
        }

        $existingDeliveryKeys = NotificationDelivery::query()
            ->where('notification_event_id', $event->id)
            ->whereIn('user_id', $recipients->modelKeys())
            ->get(['user_id', 'channel'])
            ->map(fn (NotificationDelivery $delivery) => sprintf('%d:%s', $delivery->user_id, $delivery->channel))
            ->all();

        $existingDeliveryKeys = array_flip($existingDeliveryKeys);
        $deliveryRows = [];
        $deliveryKeysToDispatch = [];
        $timestamp = now();

        foreach ($recipients as $recipient) {
            foreach ([NotificationChannel::EMAIL, NotificationChannel::DISCORD] as $channel) {
                $deliveryKey = sprintf('%d:%s', $recipient->id, $channel);

                if (isset($existingDeliveryKeys[$deliveryKey])) {
                    continue;
                }

                $outcome = $notificationService->determineDeliveryOutcome($event, $recipient, $channel);

                $deliveryRows[] = [
                    'notification_event_id' => $event->id,
                    'user_id' => $recipient->id,
                    'channel' => $channel,
                    'status' => $outcome['status'],
                    'target' => $outcome['target'],
                    'queued_at' => $outcome['status'] === NotificationDelivery::STATUS_PENDING ? $timestamp : null,
                    'sent_at' => null,
                    'failed_at' => null,
                    'skipped_at' => $outcome['status'] === NotificationDelivery::STATUS_SKIPPED ? $timestamp : null,
                    'status_reason' => $outcome['reason'],
                    'response_payload' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];

                if ($outcome['status'] === NotificationDelivery::STATUS_PENDING) {
                    $deliveryKeysToDispatch[$deliveryKey] = true;
                }
            }
        }

        if ($deliveryRows === []) {
            return;
        }

        NotificationDelivery::query()->insert($deliveryRows);

        if ($deliveryKeysToDispatch === []) {
            return;
        }

        NotificationDelivery::query()
            ->where('notification_event_id', $event->id)
            ->whereIn('user_id', $recipients->modelKeys())
            ->whereIn('channel', [NotificationChannel::EMAIL, NotificationChannel::DISCORD])
            ->where('status', NotificationDelivery::STATUS_PENDING)
            ->get()
            ->filter(fn (NotificationDelivery $delivery) => isset($deliveryKeysToDispatch[sprintf('%d:%s', $delivery->user_id, $delivery->channel)]))
            ->each(fn (NotificationDelivery $delivery) => $deliveryDispatcher->dispatch($delivery));
    }
}
