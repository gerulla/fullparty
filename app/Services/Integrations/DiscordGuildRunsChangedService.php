<?php

namespace App\Services\Integrations;

use App\Jobs\SendDiscordGuildRunsChangedJob;
use App\Models\Activity;
use App\Models\DiscordGuildIntegration;

final class DiscordGuildRunsChangedService
{
    public function notifyChanged(Activity $activity): void
    {
        if ($activity->status === Activity::STATUS_DRAFT) {
            return;
        }

        $integration = DiscordGuildIntegration::query()->where('group_id', $activity->group_id)
            ->whereNull('removed_at')->first();

        if ($integration && filled($integration->discord_guild_id)) {
            SendDiscordGuildRunsChangedJob::dispatch(
                $integration->id,
                (int) $activity->group_id,
                $integration->discord_guild_id,
            )->afterCommit();
        }
    }
}
