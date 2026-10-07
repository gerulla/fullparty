<?php

namespace App\Jobs;

use App\Models\IntegrationClient;
use App\Services\Integrations\IntegrationWebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendDiscordAdminReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $title,
        public readonly string $message,
        public readonly string $severity = 'error',
    ) {}

    public function handle(IntegrationWebhookDispatcher $webhooks): array
    {
        // The bot resolves the website admins; this is not a per-user delivery.
        return $webhooks->dispatchDiscordBotEvent(IntegrationClient::EVENT_DISCORD_ADMIN_REPORT, [
            'title' => $this->title,
            'message' => $this->message,
            'severity' => $this->severity,
        ]);
    }
}
