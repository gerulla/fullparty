<?php

namespace App\Listeners;

use App\Events\DiscordGuildDisconnected;
use App\Models\IntegrationClient;
use App\Services\Integrations\IntegrationWebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendDiscordGuildDisconnectedWebhook implements ShouldQueue
{
    public function __construct(
        private readonly IntegrationWebhookDispatcher $webhooks,
    ) {}

    public function handle(DiscordGuildDisconnected $event): void
    {
        $this->webhooks->dispatchDiscordBotEvent(
            IntegrationClient::EVENT_DISCORD_GUILD_DISCONNECTED,
            [
                'discord_guild_id' => $event->discordGuildId,
                'group_id' => $event->groupId,
                'group_slug' => $event->groupSlug,
                'disconnected_at' => $event->disconnectedAt,
            ],
            IntegrationClient::EVENT_DISCORD_GUILD_SETTINGS_UPDATED,
        );
    }
}
