<?php

namespace App\Jobs;

use App\Models\IntegrationClient;
use App\Models\User;
use App\Services\Integrations\IntegrationWebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use RuntimeException;

final class SendDiscordLoginWelcomeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public string $deliveryId;

    public function __construct(
        public readonly int $userId,
        public readonly string $discordUserId,
        public readonly string $loggedInAt,
        public readonly string $locale,
        ?string $deliveryId = null,
    ) {
        $this->deliveryId = $deliveryId ?? (string) Str::uuid();
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(IntegrationWebhookDispatcher $webhooks): void
    {
        $user = User::query()->find($this->userId);
        if (! $user || $user->banned_at !== null || ! $user->hasVerifiedEmail()) {
            return;
        }

        $account = $user->socialAccounts()->safeSummary()->where('provider', 'discord')
            ->where('provider_user_id', $this->discordUserId)->first();
        if (! $account) {
            return;
        }

        $result = $webhooks->dispatchDiscordBotEvent(IntegrationClient::EVENT_USER_DISCORD_LOGIN, [
            'user' => ['id' => $user->id, 'name' => $user->name],
            'discord_user_id' => $this->discordUserId,
            'discord_user' => [
                'id' => $this->discordUserId,
                'username' => $account->provider_data['nickname'] ?? null,
                'global_name' => $account->provider_name,
                'avatar_url' => $account->avatar_url,
            ],
            'logged_in_at' => $this->loggedInAt,
            'locale' => $this->locale,
            'dashboard_url' => route('dashboard', ['locale' => $this->locale]),
            'settings_url' => route('settings', ['locale' => $this->locale]),
            // A durable website URL starts fresh, session-bound OAuth when clicked.
            'discord_app_install_url' => route('discord-app.user.redirect'),
            'discord_app_installed' => $user->discordUserIntegration()
                ->where('discord_user_id', $this->discordUserId)->whereNotNull('user_app_installed_at')->exists(),
        ], deliveryId: $this->deliveryId);

        if ($result['failed'] > 0) {
            throw new RuntimeException('Discord login welcome delivery failed.');
        }
    }
}
