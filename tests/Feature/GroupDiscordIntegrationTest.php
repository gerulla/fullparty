<?php

use App\Models\ActivityType;
use App\Models\AuditLog;
use App\Models\DiscordGuildIntegration;
use App\Models\DiscordUserIntegration;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\IntegrationClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shows the discord integration page to group owners only', function () {
    $owner = User::factory()->create();
    $moderator = User::factory()->create();
    $group = Group::factory()->create([
        'owner_id' => $owner->id,
        'name' => 'Discord Group',
    ]);
    $group->memberships()->create([
        'user_id' => $moderator->id,
        'role' => GroupMembership::ROLE_MODERATOR,
        'joined_at' => now(),
    ]);

    $this->actingAs($moderator)
        ->get(route('groups.dashboard.discord-integration', $group))
        ->assertForbidden();

    $this->actingAs($owner)
        ->get(route('groups.dashboard.discord-integration', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Groups/DiscordIntegration')
            ->where('group.name', 'Discord Group')
            ->where('group.permissions.can_manage_group', true)
            ->where('integration', null)
            ->where('inviteUrl', route('discord-app.guild.redirect'))
        );
});

it('lets group owners generate a short lived discord link token', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->create([
        'owner_id' => $owner->id,
    ]);

    $response = $this->actingAs($owner)
        ->post(route('groups.dashboard.discord-integration.link-token', $group))
        ->assertRedirect()
        ->assertSessionHas('success', 'discord_guild_link_token_generated')
        ->assertSessionHas('flash_data.discord_guild_link_token.token')
        ->assertSessionHas('flash_data.discord_guild_link_token.expires_at');

    $token = $response->baseResponse->getSession()->get('flash_data')['discord_guild_link_token']['token'];

    expect($group->fresh()->discord_link_token_hash)->toBe(hash('sha256', $token))
        ->and($group->fresh()->discord_link_token_expires_at)->not->toBeNull();
});

it('lets only the group owner unlink the active Discord server', function () {
    Http::fake([
        'https://bot.fullparty.test/events' => Http::response([], 204),
    ]);
    IntegrationClient::factory()->create([
        'outbound_events_url' => 'https://bot.fullparty.test/events',
        'webhook_signing_secret' => 'disconnect-secret',
        'allowed_events' => [
            IntegrationClient::EVENT_DISCORD_GUILD_SETTINGS_UPDATED,
        ],
    ]);

    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $group = Group::factory()->create([
        'owner_id' => $owner->id,
        'discord_link_token_hash' => hash('sha256', 'PENDING-TOKEN'),
        'discord_link_token_expires_at' => now()->addMinutes(10),
    ]);
    $integration = DiscordGuildIntegration::query()->create([
        'group_id' => $group->id,
        'discord_guild_id' => '123456789012345678',
        'name' => 'Linked Guild',
        'guild_installed_at' => now(),
    ]);
    $cacheKey = "discord-guild-membership-coverage:v3:{$integration->id}";
    Cache::put($cacheKey, ['stats_available' => true], now()->addDay());

    $this->actingAs($otherUser)
        ->delete(route('groups.dashboard.discord-integration.destroy', $group))
        ->assertNotFound();

    expect($integration->fresh()->group_id)->toBe($group->id);

    $this->actingAs($owner)
        ->delete(route('groups.dashboard.discord-integration.destroy', $group))
        ->assertRedirect()
        ->assertSessionHas('success', 'discord_guild_unlinked');

    expect($integration->fresh()->group_id)->toBeNull()
        ->and($integration->fresh()->removed_at)->toBeNull()
        ->and($group->fresh()->discord_link_token_hash)->toBeNull()
        ->and($group->fresh()->discord_link_token_expires_at)->toBeNull()
        ->and(Cache::has($cacheKey))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'group.discord_guild.unlinked')->exists())->toBeTrue();

    Http::assertSent(function (HttpRequest $request) use ($group): bool {
        $body = $request->body();
        $timestamp = $request->header('X-FullParty-Timestamp')[0] ?? null;
        $payload = json_decode($body, true);

        expect($payload['event'])->toBe(IntegrationClient::EVENT_DISCORD_GUILD_DISCONNECTED)
            ->and($payload['data']['discord_guild_id'])->toBe('123456789012345678')
            ->and($payload['data']['group_id'])->toBe($group->id)
            ->and($payload['data']['group_slug'])->toBe($group->slug)
            ->and($payload['data']['disconnected_at'])->toBeString();

        return is_string($timestamp)
            && ($request->header('X-FullParty-Event')[0] ?? null) === IntegrationClient::EVENT_DISCORD_GUILD_DISCONNECTED
            && ($request->header('X-FullParty-Signature')[0] ?? null) === 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'disconnect-secret');
    });
});

it('loads a discord guild snapshot for linked group settings', function () {
    Cache::flush();
    $sentEvents = [];

    Http::fake(function (HttpRequest $request) use (&$sentEvents) {
        $payload = json_decode($request->body(), true);
        $sentEvents[] = $payload['event'] ?? null;

        if (($payload['event'] ?? null) === IntegrationClient::EVENT_DISCORD_GUILD_MEMBERSHIP_SNAPSHOT_REQUESTED) {
            return Http::response([
                'ok' => true,
                'event' => IntegrationClient::EVENT_DISCORD_GUILD_MEMBERSHIP_SNAPSHOT_REQUESTED,
                'result' => [
                    'configured' => true,
                    'discordGuildId' => '123456789012345678',
                    'membershipCache' => [
                        'discord_guild_id' => '123456789012345678',
                        'cached_member_count' => 3,
                        'member_count' => 128,
                        'discord_member_count' => 140,
                        'discord_user_ids' => [
                            '123456789012345678',
                            '223456789012345678',
                            '323456789012345678',
                        ],
                        'refresh_status' => 'fresh',
                        'stale' => false,
                        'last_full_refresh_at' => '2026-05-30T10:00:00.000Z',
                        'next_refresh_after' => '2026-05-31T10:00:00.000Z',
                        'cache_age_seconds' => 3600,
                        'last_error' => null,
                    ],
                    'refreshQueued' => false,
                ],
            ], 200);
        }

        return Http::response([
            'ok' => true,
            'event' => IntegrationClient::EVENT_DISCORD_GUILD_SNAPSHOT_REQUESTED,
            'result' => [
                'snapshot' => [
                    'guild_name' => 'Raid Server',
                    'member_count' => 128,
                    'channels' => [
                        ['id' => '111', 'name' => 'bot-log'],
                        ['id' => '222', 'name' => 'announcements'],
                    ],
                    'roles' => [
                        ['id' => '333', 'name' => 'Template'],
                        ['id' => '444', 'name' => 'Moderator'],
                    ],
                    'available_options' => [
                        'bot_log_channels' => [
                            [
                                'id' => '111',
                                'label' => 'bot-log',
                                'usable' => true,
                                'disabled_reason' => null,
                                'type' => 'text',
                                'type_name' => 'Text Channel',
                                'viewable_by_bot' => true,
                                'sendable_by_bot' => true,
                            ],
                            [
                                'id' => '555',
                                'label' => 'read-only',
                                'usable' => false,
                                'disabled_reason' => 'Bot cannot send messages in this channel.',
                                'type' => 'text',
                                'type_name' => 'Text Channel',
                                'viewable_by_bot' => true,
                                'sendable_by_bot' => false,
                            ],
                        ],
                        'run_announcement_channels' => [
                            [
                                'id' => '222',
                                'label' => 'announcements',
                                'usable' => true,
                                'disabled_reason' => null,
                            ],
                        ],
                        'run_role_template_roles' => [
                            [
                                'id' => '333',
                                'label' => 'Template',
                                'usable' => true,
                                'disabled_reason' => null,
                                'position' => 5,
                                'managed' => false,
                            ],
                        ],
                        'bot_moderator_roles' => [
                            [
                                'id' => '444',
                                'label' => 'Moderator',
                                'usable' => true,
                                'disabled_reason' => null,
                            ],
                        ],
                        'channels' => [
                            ['id' => '111', 'label' => 'bot-log', 'usable' => true],
                            ['id' => '222', 'label' => 'announcements', 'usable' => true],
                        ],
                        'roles' => [
                            ['id' => '333', 'label' => 'Template', 'usable' => true],
                            ['id' => '444', 'label' => 'Moderator', 'usable' => true],
                        ],
                    ],
                    'settings' => [
                        'bot_log_channel_id' => '111',
                        'run_announcement_channel_id' => '222',
                        'run_role_template_id' => '333',
                        'bot_moderator_role_id' => '444',
                        'sync_discord_names_to_ff14' => true,
                    ],
                ],
            ],
        ], 200);
    });

    IntegrationClient::factory()->create([
        'outbound_events_url' => 'https://bot.fullparty.test/events',
        'webhook_signing_secret' => 'snapshot-secret',
        'allowed_events' => [
            IntegrationClient::EVENT_DISCORD_GUILD_SNAPSHOT_REQUESTED,
            IntegrationClient::EVENT_DISCORD_GUILD_MEMBERSHIP_SNAPSHOT_REQUESTED,
        ],
    ]);

    $owner = User::factory()->create();
    DiscordUserIntegration::query()->create([
        'user_id' => User::factory()->create()->id,
        'discord_user_id' => '123456789012345678',
        'username' => 'Linked One',
        'user_app_installed_at' => now(),
    ]);
    DiscordUserIntegration::query()->create([
        'user_id' => User::factory()->create()->id,
        'discord_user_id' => '223456789012345678',
        'username' => 'Linked Two',
        'user_app_installed_at' => now(),
    ]);
    $group = Group::factory()->create([
        'owner_id' => $owner->id,
    ]);
    DiscordGuildIntegration::query()->create([
        'group_id' => $group->id,
        'discord_guild_id' => '123456789012345678',
        'name' => 'Linked Server',
        'guild_installed_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('groups.dashboard.discord-integration', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Groups/DiscordIntegration')
            ->where('snapshot.guild_name', 'Raid Server')
            ->where('snapshot.member_count', 128)
            ->where('snapshot.settings.bot_log_channel_id', '111')
            ->where('snapshot.settings.member_facing_channel_id', '222')
            ->where('snapshot.settings.template_role_id', '333')
            ->where('snapshot.settings.moderation_role_id', '444')
            ->where('snapshot.settings.name_sync_enabled', true)
            ->where('snapshot.available_options.bot_log_channels.1.usable', false)
            ->where('snapshot.available_options.bot_log_channels.1.disabled_reason', 'Bot cannot send messages in this channel.')
            ->missing('membershipCoverage')
            ->loadDeferredProps('discord-membership-coverage', fn (Assert $page) => $page
                ->where('membershipCoverage.app_linked_member_count', 2)
                ->where('membershipCoverage.unlinked_member_count', 126)
                ->where('membershipCoverage.member_count', 128)
                ->where('membershipCoverage.stats_available', true)
                ->where('membershipCoverage.membership_cache.refresh_status', 'fresh')
                ->where('membershipCoverage.membership_cache.refresh_queued', false)
            )
        );

    expect($sentEvents)->toContain(IntegrationClient::EVENT_DISCORD_GUILD_SNAPSHOT_REQUESTED)
        ->and($sentEvents)->toContain(IntegrationClient::EVENT_DISCORD_GUILD_MEMBERSHIP_SNAPSHOT_REQUESTED);

    Http::assertSent(function (HttpRequest $request): bool {
        $payload = json_decode($request->body(), true);

        return $payload['event'] === IntegrationClient::EVENT_DISCORD_GUILD_SNAPSHOT_REQUESTED
            && $payload['data']['discord_guild_id'] === '123456789012345678';
    });

    Http::assertSent(function (HttpRequest $request): bool {
        $payload = json_decode($request->body(), true);

        return $payload['event'] === IntegrationClient::EVENT_DISCORD_GUILD_MEMBERSHIP_SNAPSHOT_REQUESTED
            && $payload['data']['discord_guild_id'] === '123456789012345678'
            && $payload['data']['include_member_ids'] === true
            && $payload['data']['request_refresh_if_stale'] === true;
    });
});

it('sends discord guild settings updates to the bot', function () {
    Http::fake([
        'https://bot.fullparty.test/events' => Http::response([], 204),
    ]);

    IntegrationClient::factory()->create([
        'outbound_events_url' => 'https://bot.fullparty.test/events',
        'webhook_signing_secret' => 'settings-secret',
        'allowed_events' => [
            IntegrationClient::EVENT_DISCORD_GUILD_SETTINGS_UPDATED,
        ],
    ]);

    $owner = User::factory()->create();
    $group = Group::factory()->create([
        'owner_id' => $owner->id,
        'slug' => 'settings-group',
    ]);
    DiscordGuildIntegration::query()->create([
        'group_id' => $group->id,
        'discord_guild_id' => '223456789012345678',
        'name' => 'Settings Server',
        'guild_installed_at' => now(),
    ]);
    $activityType = ActivityType::factory()
        ->withPublishedVersion()
        ->create([
            'draft_name' => ['en' => 'Abyssos Savage'],
        ]);

    $this->actingAs($owner)
        ->put(route('groups.dashboard.discord-integration.settings.update', $group), [
            'bot_log_channel_id' => '111',
            'member_facing_channel_id' => null,
            'template_role_id' => '333',
            'moderation_role_id' => null,
            'name_sync_enabled' => true,
            'run_role_template_overrides' => [
                [
                    'activity_id' => $activityType->id,
                    'role_id' => '555',
                ],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'discord_guild_settings_updated');

    Http::assertSent(function (HttpRequest $request) use ($group, $activityType): bool {
        $body = $request->body();
        $timestamp = $request->header('X-FullParty-Timestamp')[0] ?? null;
        $payload = json_decode($body, true);

        expect($payload['event'])->toBe(IntegrationClient::EVENT_DISCORD_GUILD_SETTINGS_UPDATED)
            ->and($payload['data']['discord_guild_id'])->toBe('223456789012345678')
            ->and($payload['data']['group_id'])->toBe($group->id)
            ->and($payload['data']['group_slug'])->toBe('settings-group')
            ->and($payload['data']['settings'])->toBe([
                'bot_log_channel_id' => '111',
                'member_facing_channel_id' => null,
                'run_announcement_channel_id' => null,
                'template_role_id' => '333',
                'run_role_template_id' => '333',
                'run_role_template_role_id' => '333',
                'moderation_role_id' => null,
                'bot_moderator_role_id' => null,
                'name_sync_enabled' => true,
                'enable_name_sync' => true,
                'nickname_sync_enabled' => true,
                'sync_discord_names_to_ff14' => true,
                'run_role_template_overrides' => [
                    [
                        'activity_id' => $activityType->id,
                        'activity_name' => 'Abyssos Savage',
                        'role_id' => '555',
                    ],
                ],
            ]);

        return is_string($timestamp)
            && ($request->header('X-FullParty-Event')[0] ?? null) === IntegrationClient::EVENT_DISCORD_GUILD_SETTINGS_UPDATED
            && ($request->header('X-FullParty-Signature')[0] ?? null) === 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'settings-secret');
    });
});

it('requires guild write scope to link a discord guild through the integration api', function () {
    $token = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()
        ->withApiToken($token)
        ->create([
            'scopes' => [
                IntegrationClient::SCOPE_RUNS_READ,
            ],
        ]);

    $this->postJson(route('api.integrations.discord-guilds.link'), [
        'discord_guild_id' => '123456789012345678',
        'token' => 'ANY-TOKEN',
    ], [
        'Authorization' => 'Bearer '.$token,
    ])->assertForbidden();
});

it('links an installed discord guild to the group matching the generated token', function () {
    $apiToken = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()
        ->withApiToken($apiToken)
        ->create([
            'scopes' => [
                IntegrationClient::SCOPE_GUILDS_WRITE,
            ],
        ]);

    $group = Group::factory()->create([
        'name' => 'Token Group',
        'discord_link_token_hash' => hash('sha256', 'TOKEN-1234'),
        'discord_link_token_expires_at' => now()->addMinutes(10),
    ]);
    $integration = DiscordGuildIntegration::query()->create([
        'discord_guild_id' => '223456789012345678',
        'installed_by_discord_user_id' => '323456789012345678',
        'guild_installed_at' => now()->subMinute(),
    ]);

    $this->postJson(route('api.integrations.discord-guilds.link'), [
        'discord_guild_id' => '223456789012345678',
        'token' => 'TOKEN-1234',
        'name' => 'Raid Server',
        'icon_url' => 'https://cdn.discordapp.com/icons/223456789012345678/icon.png',
        'permissions' => '123456',
    ], [
        'Authorization' => 'Bearer '.$apiToken,
    ])
        ->assertOk()
        ->assertJsonPath('data.linked', true)
        ->assertJsonPath('data.group.slug', $group->slug)
        ->assertJsonPath('data.guild.discord_guild_id', '223456789012345678')
        ->assertJsonPath('data.guild.name', 'Raid Server');

    expect($integration->fresh()->group_id)->toBe($group->id)
        ->and($integration->fresh()->name)->toBe('Raid Server')
        ->and($group->fresh()->discord_link_token_hash)->toBeNull()
        ->and($group->fresh()->discord_link_token_expires_at)->toBeNull();
});

it('normalizes discord guild link tokens submitted by the bot', function () {
    $apiToken = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()
        ->withApiToken($apiToken)
        ->create([
            'scopes' => [
                IntegrationClient::SCOPE_GUILDS_WRITE,
            ],
        ]);

    $group = Group::factory()->create([
        'name' => 'Normalized Token Group',
        'discord_link_token_hash' => hash('sha256', 'ABCDEFGH-IJKLMNPQ'),
        'discord_link_token_expires_at' => now()->addMinutes(10),
    ]);

    DiscordGuildIntegration::query()->create([
        'discord_guild_id' => '623456789012345678',
        'installed_by_discord_user_id' => '723456789012345678',
        'guild_installed_at' => now()->subMinute(),
    ]);

    $this->postJson(route('api.integrations.discord-guilds.link'), [
        'discord_guild_id' => '623456789012345678',
        'token' => '`abcdefgh ijklmnpq`',
        'name' => 'Normalized Raid Server',
    ], [
        'Authorization' => 'Bearer '.$apiToken,
    ])
        ->assertOk()
        ->assertJsonPath('data.linked', true)
        ->assertJsonPath('data.group.slug', $group->slug)
        ->assertJsonPath('data.guild.discord_guild_id', '623456789012345678');

    expect($group->fresh()->activeDiscordGuildIntegration?->discord_guild_id)->toBe('623456789012345678');
});

it('reports incomplete discord guild link payloads separately from invalid tokens', function () {
    $apiToken = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()
        ->withApiToken($apiToken)
        ->create([
            'scopes' => [
                IntegrationClient::SCOPE_GUILDS_WRITE,
            ],
        ]);

    $this->postJson(route('api.integrations.discord-guilds.link'), [
        'token' => 'TOKEN-1234',
    ], [
        'Authorization' => 'Bearer '.$apiToken,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'discord_guild_link_payload_invalid')
        ->assertJsonPath('errors.discord_guild_id.0', 'discord_guild_link_payload_invalid')
        ->assertJsonMissing([
            'token' => ['discord_guild_link_token_invalid'],
        ]);
});

it('rejects expired or unknown discord guild link tokens', function () {
    $apiToken = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()
        ->withApiToken($apiToken)
        ->create([
            'scopes' => [
                IntegrationClient::SCOPE_GUILDS_WRITE,
            ],
        ]);

    Group::factory()->create([
        'discord_link_token_hash' => hash('sha256', 'EXPIRED-TOKEN'),
        'discord_link_token_expires_at' => now()->subMinute(),
    ]);

    $this->postJson(route('api.integrations.discord-guilds.link'), [
        'discord_guild_id' => '423456789012345678',
        'token' => 'EXPIRED-TOKEN',
    ], [
        'Authorization' => 'Bearer '.$apiToken,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('token');
});

it('does not let a discord guild link to multiple groups', function () {
    $apiToken = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()
        ->withApiToken($apiToken)
        ->create([
            'scopes' => [
                IntegrationClient::SCOPE_GUILDS_WRITE,
            ],
        ]);

    $firstGroup = Group::factory()->create();
    $secondGroup = Group::factory()->create([
        'discord_link_token_hash' => hash('sha256', 'SECOND-TOKEN'),
        'discord_link_token_expires_at' => now()->addMinutes(10),
    ]);
    DiscordGuildIntegration::query()->create([
        'group_id' => $firstGroup->id,
        'discord_guild_id' => '523456789012345678',
        'guild_installed_at' => now(),
    ]);

    $this->postJson(route('api.integrations.discord-guilds.link'), [
        'discord_guild_id' => '523456789012345678',
        'token' => 'SECOND-TOKEN',
    ], [
        'Authorization' => 'Bearer '.$apiToken,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('discord_guild_id');

    expect($secondGroup->fresh()->activeDiscordGuildIntegration)->toBeNull();
});
