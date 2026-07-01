<?php

namespace App\Http\Controllers;

use App\Models\ActivityType;
use App\Models\DiscordGuildIntegration;
use App\Models\DiscordUserIntegration;
use App\Models\Group;
use App\Models\IntegrationClient;
use App\Services\Integrations\IntegrationWebhookDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class GroupDiscordIntegrationController extends Controller
{
    public function show(Group $group, IntegrationWebhookDispatcher $webhooks): Response
    {
        $this->authorizeOwner($group);

        $group->loadMissing(['memberships', 'activeDiscordGuildIntegration']);
        $integration = $group->activeDiscordGuildIntegration;

        return Inertia::render('Dashboard/Groups/DiscordIntegration', [
            'group' => $this->serializeGroup($group),
            'integration' => $this->serializeIntegration($integration),
            'inviteUrl' => route('discord-app.guild.redirect'),
            'activityTypes' => $this->serializeActivityTypes(),
            'snapshot' => fn () => $this->fetchGuildSettingsSnapshot($integration, $webhooks),
            'membershipCoverage' => Inertia::defer(
                fn () => $this->fetchMembershipCoverage($integration, $webhooks),
                'discord-membership-coverage',
            ),
        ]);
    }

    public function generateToken(Request $request, Group $group): RedirectResponse
    {
        $this->authorizeOwner($group);

        $plainToken = Str::upper(Str::random(8)).'-'.Str::upper(Str::random(8));
        $expiresAt = now()->addMinutes(30);

        $group->forceFill([
            'discord_link_token_hash' => hash('sha256', $plainToken),
            'discord_link_token_expires_at' => $expiresAt,
        ])->save();

        return back()
            ->with('success', 'discord_guild_link_token_generated')
            ->with('flash_data', [
                'discord_guild_link_token' => [
                    'token' => $plainToken,
                    'expires_at' => $expiresAt->toIso8601String(),
                ],
            ]);
    }

    public function updateSettings(Request $request, Group $group, IntegrationWebhookDispatcher $webhooks): RedirectResponse
    {
        $this->authorizeOwner($group);
        $group->loadMissing('activeDiscordGuildIntegration');

        $integration = $group->activeDiscordGuildIntegration;

        if (! $integration) {
            return back()->withErrors([
                'integration' => 'discord_guild_not_linked',
            ]);
        }

        $validated = $request->validate([
            'bot_log_channel_id' => ['nullable', 'string', 'max:64'],
            'member_facing_channel_id' => ['nullable', 'string', 'max:64'],
            'run_announcement_channel_id' => ['nullable', 'string', 'max:64'],
            'template_role_id' => ['nullable', 'string', 'max:64'],
            'run_role_template_id' => ['nullable', 'string', 'max:64'],
            'run_role_template_role_id' => ['nullable', 'string', 'max:64'],
            'moderation_role_id' => ['nullable', 'string', 'max:64'],
            'bot_moderator_role_id' => ['nullable', 'string', 'max:64'],
            'name_sync_enabled' => ['required', 'boolean'],
            'enable_name_sync' => ['nullable', 'boolean'],
            'nickname_sync_enabled' => ['nullable', 'boolean'],
            'sync_discord_names_to_ff14' => ['nullable', 'boolean'],
            'run_role_template_overrides' => ['nullable', 'array'],
            'run_role_template_overrides.*.activity_id' => ['required', 'integer', 'exists:activity_types,id'],
            'run_role_template_overrides.*.role_id' => ['required', 'string', 'max:64'],
        ]);
        $memberFacingChannelId = $validated['member_facing_channel_id']
            ?? $validated['run_announcement_channel_id']
            ?? null;
        $templateRoleId = $validated['template_role_id']
            ?? $validated['run_role_template_id']
            ?? $validated['run_role_template_role_id']
            ?? null;
        $moderationRoleId = $validated['moderation_role_id']
            ?? $validated['bot_moderator_role_id']
            ?? null;
        $settings = [
            'bot_log_channel_id' => $validated['bot_log_channel_id'] ?? null,
            'member_facing_channel_id' => $memberFacingChannelId,
            'run_announcement_channel_id' => $memberFacingChannelId,
            'template_role_id' => $templateRoleId,
            'run_role_template_id' => $templateRoleId,
            'run_role_template_role_id' => $templateRoleId,
            'moderation_role_id' => $moderationRoleId,
            'bot_moderator_role_id' => $moderationRoleId,
            'name_sync_enabled' => (bool) $validated['name_sync_enabled'],
            'enable_name_sync' => (bool) $validated['name_sync_enabled'],
            'nickname_sync_enabled' => (bool) $validated['name_sync_enabled'],
            'sync_discord_names_to_ff14' => (bool) $validated['name_sync_enabled'],
            'run_role_template_overrides' => $this->roleTemplateOverridesForDispatch($validated['run_role_template_overrides'] ?? []),
        ];

        $webhooks->dispatchDiscordBotEvent(
            IntegrationClient::EVENT_DISCORD_GUILD_SETTINGS_UPDATED,
            [
                'discord_guild_id' => $integration->discord_guild_id,
                'group_id' => $group->id,
                'group_slug' => $group->slug,
                'settings' => $settings,
            ],
        );

        return back()->with('success', 'discord_guild_settings_updated');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeGroup(Group $group): array
    {
        $currentUserId = auth()->id();

        return [
            'id' => $group->id,
            'name' => $group->name,
            'slug' => $group->slug,
            'current_user_role' => $group->memberships
                ->firstWhere('user_id', $currentUserId)
                ?->role,
            'permissions' => [
                'can_manage_group' => $group->isOwnedBy($currentUserId),
                'can_manage_members' => $group->hasModeratorAccess($currentUserId),
                'can_manage_discovery' => $group->hasAdminAccess($currentUserId),
                'can_manage_activities' => $group->hasModeratorAccess($currentUserId),
                'can_view_members' => $group->hasMember($currentUserId),
                'can_review_membership_applications' => $group->usesMembershipApplications() && $group->hasModeratorAccess($currentUserId),
                'can_manage_membership_application_form' => $group->usesMembershipApplications() && $group->hasAdminAccess($currentUserId),
            ],
            'discord_link_token_expires_at' => $group->discord_link_token_expires_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array{id: int, name: string, difficulty: string|null}>
     */
    private function serializeActivityTypes(): array
    {
        return ActivityType::query()
            ->where('is_active', true)
            ->whereNotNull('current_published_version_id')
            ->with('currentPublishedVersion:id,activity_type_id,name,difficulty')
            ->orderBy('slug')
            ->get()
            ->map(fn (ActivityType $activityType): array => [
                'id' => $activityType->id,
                'name' => $this->localizedValue(
                    $activityType->currentPublishedVersion?->name,
                    $activityType->slug,
                ),
                'difficulty' => $activityType->currentPublishedVersion?->difficulty,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeIntegration(?DiscordGuildIntegration $integration): ?array
    {
        if (! $integration) {
            return null;
        }

        return [
            'id' => $integration->id,
            'discord_guild_id' => $integration->discord_guild_id,
            'name' => $integration->name,
            'icon_url' => $integration->icon_url,
            'permissions' => $integration->permissions,
            'guild_installed_at' => $integration->guild_installed_at?->toIso8601String(),
            'updated_at' => $integration->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchGuildSettingsSnapshot(?DiscordGuildIntegration $integration, IntegrationWebhookDispatcher $webhooks): ?array
    {
        if (! $integration) {
            return null;
        }

        $response = $webhooks->requestDiscordBotEvent(
            IntegrationClient::EVENT_DISCORD_GUILD_SNAPSHOT_REQUESTED,
            [
                'discord_guild_id' => $integration->discord_guild_id,
            ],
        );

        $result = $this->guildSettingsSnapshotResult($response);
        $snapshot = data_get($result, 'snapshot');

        return is_array($snapshot) ? $this->prepareSnapshot($snapshot) : null;
    }

    /**
     * @param  array<string, mixed>|null  $response
     * @return array<string, mixed>|null
     */
    private function guildSettingsSnapshotResult(?array $response): ?array
    {
        if (! is_array($response)) {
            return null;
        }

        if (($response['event'] ?? null) !== IntegrationClient::EVENT_DISCORD_GUILD_SNAPSHOT_REQUESTED) {
            return null;
        }

        if (($response['ok'] ?? false) !== true) {
            return null;
        }

        $result = $response['result'] ?? null;

        return is_array($result) ? $result : null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function prepareSnapshot(array $snapshot): array
    {
        return $this->withSettingAliases($snapshot);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function withSettingAliases(array $snapshot): array
    {
        if (! is_array($snapshot['settings'] ?? null)) {
            return $snapshot;
        }

        $settings = $snapshot['settings'];
        $settings['member_facing_channel_id'] = $this->settingValue($settings, [
            'run_announcement_channel_id',
            'member_facing_channel_id',
        ]);
        $settings['run_announcement_channel_id'] = $this->settingValue($settings, [
            'run_announcement_channel_id',
            'member_facing_channel_id',
        ]);
        $settings['template_role_id'] = $this->settingValue($settings, [
            'run_role_template_id',
            'run_role_template_role_id',
            'template_role_id',
        ]);
        $settings['run_role_template_id'] = $this->settingValue($settings, [
            'run_role_template_id',
            'run_role_template_role_id',
            'template_role_id',
        ]);
        $settings['run_role_template_role_id'] = $this->settingValue($settings, [
            'run_role_template_id',
            'run_role_template_role_id',
            'template_role_id',
        ]);
        $settings['moderation_role_id'] = $this->settingValue($settings, [
            'bot_moderator_role_id',
            'moderation_role_id',
        ]);
        $settings['bot_moderator_role_id'] = $this->settingValue($settings, [
            'bot_moderator_role_id',
            'moderation_role_id',
        ]);
        $settings['name_sync_enabled'] = $this->settingBooleanValue($settings, [
            'sync_discord_names_to_ff14',
            'name_sync_enabled',
            'enable_name_sync',
            'nickname_sync_enabled',
        ]);
        $settings['enable_name_sync'] = $settings['name_sync_enabled'];
        $settings['nickname_sync_enabled'] = $settings['name_sync_enabled'];
        $settings['sync_discord_names_to_ff14'] = $settings['name_sync_enabled'];
        $settings['run_role_template_overrides'] = $this->roleTemplateOverridesForDisplay($settings['run_role_template_overrides'] ?? []);

        $snapshot['settings'] = $settings;

        return $snapshot;
    }

    /**
     * @param  array<int, array<string, mixed>>  $overrides
     * @return array<int, array{activity_id: int, activity_name: string, role_id: string}>
     */
    private function roleTemplateOverridesForDispatch(array $overrides): array
    {
        $activityIds = collect($overrides)
            ->pluck('activity_id')
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($activityIds->isEmpty()) {
            return [];
        }

        $activityNames = ActivityType::query()
            ->whereIn('id', $activityIds)
            ->with('currentPublishedVersion:id,activity_type_id,name')
            ->get()
            ->mapWithKeys(fn (ActivityType $activityType): array => [
                $activityType->id => $this->localizedValue(
                    $activityType->currentPublishedVersion?->name,
                    $activityType->slug,
                ),
            ]);

        return collect($overrides)
            ->map(function (array $override) use ($activityNames): ?array {
                $activityId = (int) ($override['activity_id'] ?? 0);
                $roleId = (string) ($override['role_id'] ?? '');
                $activityName = $activityNames->get($activityId);

                if ($activityId <= 0 || blank($roleId) || blank($activityName)) {
                    return null;
                }

                return [
                    'activity_id' => $activityId,
                    'activity_name' => $activityName,
                    'role_id' => $roleId,
                ];
            })
            ->filter()
            ->keyBy('activity_id')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{activity_id: int, activity_name: string, role_id: string}>
     */
    private function roleTemplateOverridesForDisplay(mixed $overrides): array
    {
        if (! is_array($overrides)) {
            return [];
        }

        return collect($overrides)
            ->map(function ($override): ?array {
                if (! is_array($override)) {
                    return null;
                }

                $activityId = $override['activity_id'] ?? null;
                $roleId = $override['role_id'] ?? null;

                if (! is_numeric($activityId) || blank($roleId)) {
                    return null;
                }

                return [
                    'activity_id' => (int) $activityId,
                    'activity_name' => (string) ($override['activity_name'] ?? sprintf('Activity Type #%d', (int) $activityId)),
                    'role_id' => (string) $roleId,
                ];
            })
            ->filter()
            ->keyBy('activity_id')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, string>|null  $value
     */
    private function localizedValue(?array $value, string $fallback): string
    {
        if (! is_array($value)) {
            return $fallback;
        }

        $locale = app()->getLocale();

        foreach ([$locale, 'en'] as $key) {
            if (filled($value[$key] ?? null)) {
                return (string) $value[$key];
            }
        }

        foreach ($value as $translation) {
            if (filled($translation)) {
                return (string) $translation;
            }
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<int, string>  $keys
     */
    private function settingValue(array $settings, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $settings)) {
                continue;
            }

            $value = $settings[$key];

            if (is_string($value) && $value !== '') {
                return $value;
            }

            if (is_numeric($value)) {
                return (string) $value;
            }

            return null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<int, string>  $keys
     */
    private function settingBooleanValue(array $settings, array $keys): bool
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $settings)) {
                continue;
            }

            return filter_var($settings[$key], FILTER_VALIDATE_BOOL);
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchMembershipCoverage(?DiscordGuildIntegration $integration, IntegrationWebhookDispatcher $webhooks): array
    {
        if (! $integration) {
            return $this->unavailableMembershipCoverage();
        }

        $cacheKey = $this->membershipCoverageCacheKey($integration);
        $cachedCoverage = Cache::get($cacheKey);

        if (is_array($cachedCoverage)) {
            return $cachedCoverage;
        }

        $coverage = $this->resolveMembershipCoverage($integration, $webhooks);

        Cache::put(
            $cacheKey,
            $coverage,
            ($coverage['stats_available'] ?? false) === true ? now()->addDay() : now()->addMinutes(5),
        );

        return $coverage;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveMembershipCoverage(DiscordGuildIntegration $integration, IntegrationWebhookDispatcher $webhooks): array
    {
        $requestData = [
            'discord_guild_id' => $integration->discord_guild_id,
            'include_member_ids' => true,
            'request_refresh_if_stale' => true,
        ];

        $response = $webhooks->requestDiscordBotEvent(
            IntegrationClient::EVENT_DISCORD_GUILD_MEMBERSHIP_SNAPSHOT_REQUESTED,
            $requestData,
        );

        $result = $this->membershipStatsSnapshotResult($response);
        $membershipCache = data_get($result, 'membershipCache');

        if (! is_array($membershipCache)) {
            return $this->unavailableMembershipCoverage();
        }

        $discordUserIds = collect(data_get($membershipCache, 'discord_user_ids', []))
            ->map(fn ($id): ?string => is_string($id) || is_numeric($id) ? (string) $id : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($discordUserIds === []) {
            return [
                ...$this->unavailableMembershipCoverage(),
                'membership_cache' => $this->serializeMembershipCache($membershipCache, $response),
            ];
        }

        $linkedCount = collect(array_chunk($discordUserIds, 1000))
            ->flatMap(fn (array $chunk) => DiscordUserIntegration::query()
                ->whereNull('revoked_at')
                ->whereNotNull('user_app_installed_at')
                ->whereIn('discord_user_id', $chunk)
                ->distinct()
                ->pluck('discord_user_id'))
            ->unique()
            ->count();
        $memberCount = is_numeric(data_get($membershipCache, 'member_count'))
            ? max(0, (int) data_get($membershipCache, 'member_count'))
            : 0;

        return [
            'app_linked_member_count' => $linkedCount,
            'unlinked_member_count' => max($memberCount - $linkedCount, 0),
            'member_count' => $memberCount,
            'stats_available' => true,
            'membership_cache' => $this->serializeMembershipCache($membershipCache, $response),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $response
     * @return array<string, mixed>|null
     */
    private function membershipStatsSnapshotResult(?array $response): ?array
    {
        if (! is_array($response)) {
            return null;
        }

        if (($response['event'] ?? null) !== IntegrationClient::EVENT_DISCORD_GUILD_MEMBERSHIP_SNAPSHOT_REQUESTED) {
            return null;
        }

        if (($response['ok'] ?? false) !== true) {
            return null;
        }

        $result = $response['result'] ?? null;

        return is_array($result) ? $result : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailableMembershipCoverage(): array
    {
        return [
            'app_linked_member_count' => 0,
            'unlinked_member_count' => 0,
            'member_count' => 0,
            'stats_available' => false,
            'membership_cache' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $membershipCache
     * @param  array<string, mixed>|null  $response
     * @return array<string, mixed>
     */
    private function serializeMembershipCache(array $membershipCache, ?array $response): array
    {
        return [
            'discord_guild_id' => $membershipCache['discord_guild_id'] ?? null,
            'cached_member_count' => is_numeric($membershipCache['cached_member_count'] ?? null)
                ? (int) $membershipCache['cached_member_count']
                : null,
            'guild_member_count' => is_numeric($membershipCache['member_count'] ?? null)
                ? (int) $membershipCache['member_count']
                : null,
            'refresh_status' => $membershipCache['refresh_status'] ?? null,
            'stale' => is_bool($membershipCache['stale'] ?? null) ? $membershipCache['stale'] : null,
            'last_full_refresh_at' => $membershipCache['last_full_refresh_at'] ?? null,
            'next_refresh_after' => $membershipCache['next_refresh_after'] ?? null,
            'cache_age_seconds' => is_numeric($membershipCache['cache_age_seconds'] ?? null)
                ? (int) $membershipCache['cache_age_seconds']
                : null,
            'last_error' => $membershipCache['last_error'] ?? null,
            'refresh_queued' => (bool) data_get($response, 'result.refreshQueued', data_get($response, 'refreshQueued', false)),
        ];
    }

    private function membershipCoverageCacheKey(DiscordGuildIntegration $integration): string
    {
        return "discord-guild-membership-coverage:v3:{$integration->id}";
    }

    private function authorizeOwner(Group $group): void
    {
        if (! $group->isOwnedBy(auth()->id())) {
            abort(403);
        }
    }
}
