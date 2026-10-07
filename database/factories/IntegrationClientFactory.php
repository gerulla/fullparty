<?php

namespace Database\Factories;

use App\Models\IntegrationClient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationClient>
 */
class IntegrationClientFactory extends Factory
{
    protected $model = IntegrationClient::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'created_by_user_id' => User::factory(),
            'name' => fake()->company().' Integration',
            'type' => IntegrationClient::TYPE_DISCORD_BOT,
            'status' => IntegrationClient::STATUS_ACTIVE,
            'outbound_events_url' => 'https://integration.fullparty.test/events',
            'healthcheck_url' => 'https://integration.fullparty.test/health',
            'webhook_signing_secret' => fake()->sha256(),
            'api_token_hash' => null,
            'scopes' => [
                IntegrationClient::SCOPE_RUNS_READ,
                IntegrationClient::SCOPE_USERS_READ,
                IntegrationClient::SCOPE_USERS_WRITE,
                IntegrationClient::SCOPE_GUILDS_WRITE,
            ],
            'allowed_events' => [
                IntegrationClient::EVENT_DISCORD_USER_APP_INSTALLED,
                IntegrationClient::EVENT_DISCORD_USER_APP_DISCONNECTED,
                IntegrationClient::EVENT_DISCORD_NOTIFICATION_DELIVERY,
                IntegrationClient::EVENT_DISCORD_ADMIN_REPORT,
                IntegrationClient::EVENT_DISCORD_GUILD_RUN_STARTING_SOON,
                IntegrationClient::EVENT_DISCORD_GUILD_RUN_STARTING_NOW,
                IntegrationClient::EVENT_DISCORD_GUILD_RUN_PARTICIPANT_SYNC,
                IntegrationClient::EVENT_DISCORD_GUILD_RUN_COMPLETED,
                IntegrationClient::EVENT_DISCORD_GUILD_RUN_CANCELLED,
                IntegrationClient::EVENT_DISCORD_GUILD_SNAPSHOT_REQUESTED,
                IntegrationClient::EVENT_DISCORD_GUILD_MEMBERSHIP_SNAPSHOT_REQUESTED,
                IntegrationClient::EVENT_DISCORD_GUILD_SETTINGS_UPDATED,
            ],
        ];
    }

    public function withApiToken(string $plainToken): static
    {
        return $this->state(fn (array $attributes): array => [
            'api_token_hash' => IntegrationClient::hashApiToken($plainToken),
        ]);
    }
}
