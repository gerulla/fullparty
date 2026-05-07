<?php

namespace App\Jobs;

use App\Services\Notifications\EmailNotificationDeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendNotificationEmailDeliveryJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $deliveryId,
    ) {}

    public function handle(EmailNotificationDeliveryService $emailDeliveryService): void
    {
        $emailDeliveryService->send($this->deliveryId);
    }
}
