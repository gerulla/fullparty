<?php

namespace App\Services\Notifications;

use App\Jobs\SendNotificationEmailDeliveryJob;
use App\Models\NotificationDelivery;
use App\Support\Notifications\NotificationChannel;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class NotificationDeliveryDispatcher
{
    public function __construct(
        private readonly DiscordNotificationDeliveryService $discordDeliveryService,
    ) {}

    public function dispatch(NotificationDelivery $delivery): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => $this->send($delivery));

            return;
        }

        $this->send($delivery);
    }

    private function send(NotificationDelivery $delivery): void
    {
        match ($delivery->channel) {
            NotificationChannel::EMAIL => SendNotificationEmailDeliveryJob::dispatch($delivery->id),
            NotificationChannel::DISCORD => $this->discordDeliveryService->send($delivery),
            default => throw new InvalidArgumentException("Invalid notification channel [{$delivery->channel}] supplied."),
        };
    }
}
