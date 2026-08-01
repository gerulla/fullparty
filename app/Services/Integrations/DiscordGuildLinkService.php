<?php

namespace App\Services\Integrations;

use App\Events\DiscordGuildDisconnected;
use App\Models\DiscordGuildIntegration;
use App\Models\Group;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DiscordGuildLinkService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function unlink(DiscordGuildIntegration $integration, User $actor, bool $forcedByAdmin = false): bool
    {
        $cacheKey = "discord-guild-membership-coverage:v3:{$integration->id}";
        $disconnection = DB::transaction(function () use ($integration, $actor, $forcedByAdmin): ?array {
            $lockedIntegration = DiscordGuildIntegration::query()
                ->lockForUpdate()
                ->find($integration->id);

            if (! $lockedIntegration || $lockedIntegration->group_id === null || $lockedIntegration->removed_at !== null) {
                return null;
            }

            $group = Group::query()
                ->lockForUpdate()
                ->find($lockedIntegration->group_id);

            if (! $group) {
                $lockedIntegration->update(['group_id' => null]);

                return null;
            }

            $lockedIntegration->update(['group_id' => null]);
            $group->forceFill([
                'discord_link_token_hash' => null,
                'discord_link_token_expires_at' => null,
            ])->save();

            $this->auditLogger->log(
                action: $forcedByAdmin
                    ? 'group.discord_guild.force_unlinked'
                    : 'group.discord_guild.unlinked',
                severity: $forcedByAdmin
                    ? AuditSeverity::MODERATION_CHANGE
                    : AuditSeverity::INFO,
                scopeType: AuditScope::GROUP,
                scopeId: $group->id,
                message: $forcedByAdmin
                    ? 'audit_log.activity.group.discord_guild.force_unlinked'
                    : 'audit_log.activity.group.discord_guild.unlinked',
                actor: $actor,
                subject: $lockedIntegration,
                metadata: [
                    'discord_guild_id' => $lockedIntegration->discord_guild_id,
                    'discord_guild_name' => $lockedIntegration->name,
                    'forced_by_admin' => $forcedByAdmin,
                ],
            );

            return [
                'discord_guild_id' => $lockedIntegration->discord_guild_id,
                'group_id' => $group->id,
                'group_slug' => $group->slug,
                'disconnected_at' => now('UTC')->toIso8601String(),
            ];
        });

        if (! $disconnection) {
            return false;
        }

        Cache::forget($cacheKey);
        DiscordGuildDisconnected::dispatch(
            discordGuildId: $disconnection['discord_guild_id'],
            groupId: $disconnection['group_id'],
            groupSlug: $disconnection['group_slug'],
            disconnectedAt: $disconnection['disconnected_at'],
        );

        return true;
    }
}
