<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\Group;
use App\Models\IntegrationClient;
use App\Models\User;
use App\Services\Integrations\IntegrationWebhookDispatcher;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class ActivityDiscordParticipantSyncService
{
    public function __construct(
        private readonly ActivityRosterLock $rosterLock,
        private readonly ActivitySlotStateTokenService $stateTokens,
        private readonly IntegrationWebhookDispatcher $dispatcher,
        private readonly GroupActivityAuditService $audit,
    ) {}

    public function availableFrom(Group $group, Activity $activity): ?CarbonInterface
    {
        $group->loadMissing('activeDiscordGuildIntegration');

        if (! $group->activeDiscordGuildIntegration?->guild_installed_at
            || ! filled($group->activeDiscordGuildIntegration?->discord_guild_id)
            || ! in_array($activity->status, [Activity::STATUS_ASSIGNED, Activity::STATUS_UPCOMING, Activity::STATUS_ONGOING], true)
            || $activity->completed_at !== null
            || $activity->is_completed
            || $activity->starts_at === null) {
            return null;
        }

        return $activity->starts_at->copy()->subHour();
    }

    public function sync(Group $group, Activity $activity, ActivitySlot $slot, User $actor, string $discordUserId, string $expectedStateToken): void
    {
        // Keep the participant stable until the bot responds, using the same lock as roster edits.
        $sent = $this->rosterLock->run($activity->id, function () use ($group, $activity, $slot, $actor, $discordUserId, $expectedStateToken): bool {
            $activity->refresh();
            $slot->refresh()->load(['assignedCharacter', 'fieldValues', 'assignments']);
            $group->load('activeDiscordGuildIntegration');
            $from = $this->availableFrom($group, $activity);
            if ($from === null || now()->lt($from)) {
                throw ValidationException::withMessages(['discord_user_id' => __('groups.activities.management.discord_sync.unavailable')]);
            }

            $this->stateTokens->assertMatches($slot, $expectedStateToken);
            $character = $slot->assignedCharacter;
            if (! $character || ! filled($character->name) || ! filled($character->world)) {
                throw ValidationException::withMessages(['discord_user_id' => __('groups.activities.management.discord_sync.character_required')]);
            }

            $response = $this->dispatcher->requestDiscordBotEvent(
                IntegrationClient::EVENT_DISCORD_GUILD_RUN_PARTICIPANT_SYNC,
                [
                    'discord_guild_id' => $group->activeDiscordGuildIntegration->discord_guild_id,
                    'discord_user_id' => $discordUserId,
                    'nickname' => $character->name.' ['.$character->world.']',
                    'run_id' => $activity->id,
                ],
            );

            if ($response === null || ($response['success'] ?? true) === false || ($response['ok'] ?? true) === false
                || filled($response['error'] ?? null) || filled($response['errors'] ?? null)
                || in_array($response['status'] ?? null, ['error', 'failed'], true)) {
                return false;
            }

            // Record the moderator action, never the temporary Discord ID or bot response.
            $slot->setRelation('activity', $activity);
            $this->audit->logRosterEvent('discord_participant_synced', $slot, $actor);

            return true;
        });

        // Keep transport failure diagnostics committed while returning a retryable form error.
        if (! $sent) {
            throw ValidationException::withMessages(['discord_user_id' => __('groups.activities.management.discord_sync.failed')]);
        }
    }
}
