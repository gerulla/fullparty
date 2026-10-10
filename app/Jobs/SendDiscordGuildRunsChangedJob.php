<?php

namespace App\Jobs;

use App\Models\DiscordGuildIntegration;
use App\Models\IntegrationClient;
use App\Services\Integrations\IntegrationWebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use RuntimeException;

final class SendDiscordGuildRunsChangedJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public string $deliveryId;

    public function __construct(
        public readonly int $integrationId,
        public readonly int $groupId,
        public readonly string $discordGuildId,
    ) {
        $this->deliveryId = (string) Str::uuid();
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(IntegrationWebhookDispatcher $webhooks): void
    {
        if (! DiscordGuildIntegration::query()->whereKey($this->integrationId)
            ->where('group_id', $this->groupId)->where('discord_guild_id', $this->discordGuildId)
            ->whereNull('removed_at')->exists()) {
            return;
        }

        $result = $webhooks->dispatchDiscordBotEvent(IntegrationClient::EVENT_DISCORD_GUILD_RUNS_CHANGED, [
            'discord_guild_id' => $this->discordGuildId,
        ], deliveryId: $this->deliveryId);

        if ($result['failed'] > 0) {
            throw new RuntimeException('Discord guild runs-changed delivery failed.');
        }
    }
}
