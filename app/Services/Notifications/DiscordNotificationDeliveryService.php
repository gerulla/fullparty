<?php

namespace App\Services\Notifications;

use App\Models\NotificationDelivery;
use App\Support\Notifications\NotificationChannel;

class DiscordNotificationDeliveryService
{
    public function send(NotificationDelivery $delivery): void
    {
        if ($delivery->channel !== NotificationChannel::DISCORD) {
            return;
        }

        $delivery->update([
            'status' => NotificationDelivery::STATUS_SKIPPED,
            'status_reason' => 'discord_transport_unavailable',
            'skipped_at' => now(),
            'failed_at' => null,
            'sent_at' => null,
        ]);

        // TODO: Replace this skip path with a real Discord transport once the bot / webhook delivery flow exists.
    }
}
