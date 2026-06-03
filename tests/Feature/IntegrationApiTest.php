<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivityTypeVersion;
use App\Models\AuditLog;
use App\Models\Character;
use App\Models\DiscordGuildIntegration;
use App\Models\DiscordUserIntegration;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\IntegrationClient;
use App\Models\User;
use App\Models\UserOnboardingState;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('requires an active integration api token', function () {
    $activity = Activity::factory()->create();

    $this->getJson(route('api.integrations.runs.show', $activity))
        ->assertUnauthorized();

    $this->getJson(route('api.integrations.runs.show', $activity), [
        'Authorization' => 'Bearer nope',
    ])->assertUnauthorized();
});

it('requires the integration client to have the needed scope', function () {
    $token = IntegrationClient::makePlainApiToken();
    $activity = Activity::factory()->create();

    IntegrationClient::factory()
        ->withApiToken($token)
        ->create([
            'scopes' => [],
        ]);

    $this->getJson(route('api.integrations.runs.show', $activity), [
        'Authorization' => 'Bearer '.$token,
    ])->assertForbidden();
});

it('returns run status data for an authorized integration client', function () {
    $token = IntegrationClient::makePlainApiToken();
    $group = Group::factory()->create([
        'name' => 'Elemental Current',
        'slug' => 'elemental-current',
    ]);
    $version = ActivityTypeVersion::factory()->create([
        'name' => ['en' => 'Cloud of Darkness (Chaotic)'],
        'difficulty' => 'chaotic',
        'small_image_url' => '/storage/activities/cloud-small.webp',
        'banner_image_url' => '/storage/activities/cloud-banner.webp',
    ]);
    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_version_id' => $version->id,
        'activity_type_id' => $version->activity_type_id,
        'title' => 'Friday prog',
        'status' => Activity::STATUS_SCHEDULED,
        'starts_at' => now()->addDay(),
        'duration_hours' => 2.5,
    ]);
    $client = IntegrationClient::factory()
        ->withApiToken($token)
        ->create([
            'scopes' => [
                IntegrationClient::SCOPE_RUNS_READ,
            ],
        ]);

    $this->getJson(route('api.integrations.runs.show', $activity), [
        'Authorization' => 'Bearer '.$token,
    ])
        ->assertOk()
        ->assertJsonPath('data.id', $activity->id)
        ->assertJsonPath('data.title', 'Friday prog')
        ->assertJsonPath('data.status', Activity::STATUS_SCHEDULED)
        ->assertJsonPath('data.group.name', 'Elemental Current')
        ->assertJsonPath('data.activity_type.name.en', 'Cloud of Darkness (Chaotic)')
        ->assertJsonPath('data.activity_type.difficulty', 'chaotic');

    expect($client->fresh()->last_api_used_at)->not->toBeNull();
});

it('returns the next six upcoming runs for a linked discord user', function () {
    $token = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()
        ->withApiToken($token)
        ->create([
            'scopes' => [
                IntegrationClient::SCOPE_RUNS_READ,
            ],
        ]);

    $user = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
        'name' => 'Api Runner',
    ]);
    DiscordUserIntegration::query()->create([
        'user_id' => $user->id,
        'discord_user_id' => '123456789012345678',
        'username' => 'api-runner',
        'user_app_installed_at' => now(),
    ]);

    $group = Group::factory()->create([
        'name' => 'Integration Group',
        'slug' => 'integration-group',
    ]);
    $version = ActivityTypeVersion::factory()->create([
        'name' => ['en' => 'AAC Light-heavyweight M4 (Savage)'],
        'difficulty' => 'savage',
    ]);

    Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_version_id' => $version->id,
        'activity_type_id' => $version->activity_type_id,
        'status' => Activity::STATUS_SCHEDULED,
        'starts_at' => now()->subHour(),
    ]);

    $expectedIds = [];

    foreach (range(1, 7) as $hour) {
        $activity = Activity::factory()->create([
            'group_id' => $group->id,
            'activity_type_version_id' => $version->id,
            'activity_type_id' => $version->activity_type_id,
            'title' => 'Upcoming '.$hour,
            'status' => Activity::STATUS_SCHEDULED,
            'starts_at' => now()->addHours($hour),
        ]);

        ActivitySlot::factory()->assignedTo($character)->create([
            'activity_id' => $activity->id,
            'slot_key' => 'slot-'.$hour,
            'slot_label' => ['en' => 'Slot '.$hour],
        ]);

        if ($hour <= 6) {
            $expectedIds[] = $activity->id;
        }
    }

    $completedActivity = Activity::factory()->complete()->create([
        'group_id' => $group->id,
        'activity_type_version_id' => $version->id,
        'activity_type_id' => $version->activity_type_id,
        'starts_at' => now()->addMinutes(30),
    ]);
    ActivitySlot::factory()->assignedTo($character)->create([
        'activity_id' => $completedActivity->id,
        'slot_key' => 'completed-slot',
        'slot_label' => ['en' => 'Completed Slot'],
    ]);

    $this->getJson(route('api.integrations.discord-users.upcoming-runs.index', [
        'discordUserId' => '123456789012345678',
    ]), [
        'Authorization' => 'Bearer '.$token,
    ])
        ->assertOk()
        ->assertJsonCount(6, 'data')
        ->assertJsonPath('data.0.id', $expectedIds[0])
        ->assertJsonPath('data.0.display_name', 'Upcoming 1')
        ->assertJsonPath('data.0.user_context.is_assigned', true)
        ->assertJsonPath('data.0.user_context.slot.character.name', 'Api Runner')
        ->assertJsonPath('data.5.id', $expectedIds[5]);
});

it('returns only ongoing applications for a linked discord user', function () {
    $token = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()
        ->withApiToken($token)
        ->create([
            'scopes' => [
                IntegrationClient::SCOPE_RUNS_READ,
            ],
        ]);

    $user = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
        'name' => 'Api Applicant',
    ]);
    DiscordUserIntegration::query()->create([
        'user_id' => $user->id,
        'discord_user_id' => '223456789012345678',
        'username' => 'api-applicant',
        'user_app_installed_at' => now(),
    ]);

    $group = Group::factory()->create();
    $version = ActivityTypeVersion::factory()->create([
        'name' => ['en' => 'Futures Rewritten (Ultimate)'],
        'difficulty' => 'ultimate',
    ]);

    $activities = collect(range(1, 5))
        ->map(fn (int $index) => Activity::factory()->create([
            'group_id' => $group->id,
            'activity_type_version_id' => $version->id,
            'activity_type_id' => $version->activity_type_id,
            'title' => 'Application Run '.$index,
            'status' => $index === 5 ? Activity::STATUS_COMPLETE : Activity::STATUS_SCHEDULED,
            'starts_at' => now()->addDays($index),
        ]));

    $pending = ActivityApplication::factory()->create([
        'activity_id' => $activities[0]->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
        'status' => ActivityApplication::STATUS_PENDING,
        'submitted_at' => now()->subMinutes(3),
    ]);
    ActivityApplication::factory()->approved()->create([
        'activity_id' => $activities[1]->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
        'submitted_at' => now()->subMinutes(2),
    ]);
    $bench = ActivityApplication::factory()->create([
        'activity_id' => $activities[2]->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
        'status' => ActivityApplication::STATUS_ON_BENCH,
        'submitted_at' => now()->subMinute(),
    ]);
    ActivityApplication::factory()->declined()->create([
        'activity_id' => $activities[3]->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
    ]);
    ActivityApplication::factory()->create([
        'activity_id' => $activities[4]->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
        'status' => ActivityApplication::STATUS_PENDING,
    ]);

    $this->getJson(route('api.integrations.discord-users.applications.index', [
        'discordUserId' => '223456789012345678',
    ]), [
        'Authorization' => 'Bearer '.$token,
    ])
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.id', $bench->id)
        ->assertJsonPath('data.0.character.name', 'Api Applicant')
        ->assertJsonPath('data.0.activity.display_name', 'Application Run 3')
        ->assertJsonPath('data.2.id', $pending->id);
});

it('returns discoverable upcoming runs for a linked discord guild', function () {
    $token = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()
        ->withApiToken($token)
        ->create([
            'scopes' => [
                IntegrationClient::SCOPE_RUNS_READ,
            ],
        ]);

    $host = User::factory()->create([
        'name' => 'Host Person',
        'avatar_url' => 'https://example.com/host-avatar.png',
    ]);
    $hostCharacter = Character::factory()->primary()->create([
        'user_id' => $host->id,
        'name' => 'Host Character',
        'world' => 'Twintania',
        'datacenter' => 'Light',
        'avatar_url' => 'https://example.com/host-character.png',
    ]);
    DiscordUserIntegration::query()->create([
        'user_id' => $host->id,
        'discord_user_id' => '800000000000000001',
        'username' => 'host-person',
        'user_app_installed_at' => now(),
    ]);

    $group = Group::factory()->create([
        'owner_id' => $host->id,
        'name' => 'Guild Linked Group',
        'slug' => 'guildgrp',
    ]);
    DiscordGuildIntegration::query()->create([
        'group_id' => $group->id,
        'discord_guild_id' => '133700000000000001',
        'name' => 'Guild One',
        'guild_installed_at' => now(),
    ]);

    $otherGroup = Group::factory()->create([
        'slug' => 'othergrp',
    ]);
    $version = ActivityTypeVersion::factory()->create([
        'name' => ['en' => 'The Weapon\'s Refrain (Ultimate)'],
        'difficulty' => 'ultimate',
        'prog_points' => [
            [
                'key' => 'titan-cleanup',
                'label' => ['en' => 'Titan Cleanup'],
                'order' => 3,
            ],
        ],
    ]);

    $first = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_version_id' => $version->id,
        'activity_type_id' => $version->activity_type_id,
        'title' => 'Soon public',
        'organized_by_user_id' => $host->id,
        'organized_by_character_id' => $hostCharacter->id,
        'status' => Activity::STATUS_SCHEDULED,
        'starts_at' => now()->addHour(),
        'is_public' => true,
        'target_prog_point_key' => 'titan-cleanup',
    ]);
    $first->slots()->delete();

    $assignedCharacter = Character::factory()->create([
        'name' => 'Assigned One',
    ]);
    $secondAssignedCharacter = Character::factory()->create([
        'name' => 'Assigned Two',
    ]);

    ActivitySlot::factory()->assignedTo($assignedCharacter)->create([
        'activity_id' => $first->id,
        'group_key' => 'party-a',
        'slot_key' => 'party-a-slot-1',
    ]);
    ActivitySlot::factory()->assignedTo($secondAssignedCharacter)->create([
        'activity_id' => $first->id,
        'group_key' => 'party-a',
        'slot_key' => 'party-a-slot-2',
    ]);
    ActivitySlot::factory()->create([
        'activity_id' => $first->id,
        'group_key' => 'party-a',
        'slot_key' => 'party-a-slot-3',
    ]);
    ActivitySlot::factory()->assignedTo(Character::factory()->create())->create([
        'activity_id' => $first->id,
        'group_key' => 'bench',
        'slot_key' => 'bench-slot-1',
    ]);

    ActivityApplication::factory()->create([
        'activity_id' => $first->id,
        'status' => ActivityApplication::STATUS_PENDING,
    ]);
    ActivityApplication::factory()->approved()->create([
        'activity_id' => $first->id,
    ]);
    ActivityApplication::factory()->create([
        'activity_id' => $first->id,
        'status' => ActivityApplication::STATUS_ON_BENCH,
    ]);
    ActivityApplication::factory()->declined()->create([
        'activity_id' => $first->id,
    ]);
    ActivityApplication::factory()->create([
        'activity_id' => $first->id,
        'status' => ActivityApplication::STATUS_WITHDRAWN,
    ]);

    $membersOnly = Activity::factory()->private()->create([
        'group_id' => $group->id,
        'activity_type_version_id' => $version->id,
        'activity_type_id' => $version->activity_type_id,
        'title' => 'Members-only run',
        'status' => Activity::STATUS_SCHEDULED,
        'starts_at' => now()->addMinutes(30),
    ]);
    Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_version_id' => $version->id,
        'activity_type_id' => $version->activity_type_id,
        'title' => 'Past hidden',
        'status' => Activity::STATUS_SCHEDULED,
        'starts_at' => now()->subMinute(),
        'is_public' => true,
    ]);
    Activity::factory()->complete()->create([
        'group_id' => $group->id,
        'activity_type_version_id' => $version->id,
        'activity_type_id' => $version->activity_type_id,
        'title' => 'Complete hidden',
        'starts_at' => now()->addMinutes(45),
        'is_public' => true,
    ]);
    Activity::factory()->create([
        'group_id' => $otherGroup->id,
        'activity_type_version_id' => $version->id,
        'activity_type_id' => $version->activity_type_id,
        'title' => 'Other group hidden',
        'status' => Activity::STATUS_SCHEDULED,
        'starts_at' => now()->addMinutes(15),
        'is_public' => true,
    ]);

    $this->getJson(route('api.integrations.discord-guilds.upcoming-runs.index', [
        'discordGuildId' => '133700000000000001',
    ]), [
        'Authorization' => 'Bearer '.$token,
    ])
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $membersOnly->id)
        ->assertJsonPath('data.0.display_name', 'Members-only run')
        ->assertJsonPath('data.0.is_public', false)
        ->assertJsonPath('data.0.group.slug', 'guildgrp')
        ->assertJsonPath('data.1.id', $first->id)
        ->assertJsonPath('data.1.display_name', 'Soon public')
        ->assertJsonPath('data.1.is_public', true)
        ->assertJsonPath('data.1.group.slug', 'guildgrp')
        ->assertJsonPath('data.1.activity_type.name.en', 'The Weapon\'s Refrain (Ultimate)')
        ->assertJsonPath('data.1.target_prog_point_key', 'titan-cleanup')
        ->assertJsonPath('data.1.target_prog_point.key', 'titan-cleanup')
        ->assertJsonPath('data.1.target_prog_point.label.en', 'Titan Cleanup')
        ->assertJsonPath('data.1.target_prog_point.order', 3)
        ->assertJsonPath('data.1.host.user_id', $host->id)
        ->assertJsonPath('data.1.host.name', 'Host Person')
        ->assertJsonPath('data.1.host.avatar_url', 'https://example.com/host-avatar.png')
        ->assertJsonPath('data.1.host.discord_user_id', '800000000000000001')
        ->assertJsonPath('data.1.host.character.name', 'Host Character')
        ->assertJsonPath('data.1.host.character.avatar_url', 'https://example.com/host-character.png')
        ->assertJsonPath('data.1.organizer.discord_user_id', '800000000000000001')
        ->assertJsonPath('data.1.organizer.avatar_url', 'https://example.com/host-avatar.png')
        ->assertJsonPath('data.1.counts.assigned_slots', 2)
        ->assertJsonPath('data.1.counts.total_slots', 3)
        ->assertJsonPath('data.1.counts.total_applicants', 3)
        ->assertJsonPath('data.1.urls.application', route('groups.activities.application', [
            'group' => $group,
            'activity' => $first,
        ], false))
        ->assertJsonPath('meta.discord_guild_id', '133700000000000001')
        ->assertJsonPath('meta.group.slug', 'guildgrp');
});

it('returns role assignment participants with discord ids and an unlinked count for a guild run', function () {
    $token = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()
        ->withApiToken($token)
        ->create([
            'scopes' => [
                IntegrationClient::SCOPE_RUNS_READ,
            ],
        ]);

    $group = Group::factory()->create([
        'slug' => 'rolesgrp',
    ]);
    DiscordGuildIntegration::query()->create([
        'group_id' => $group->id,
        'discord_guild_id' => '133700000000000002',
        'name' => 'Role Guild',
        'guild_installed_at' => now(),
    ]);
    $version = ActivityTypeVersion::factory()->create([
        'name' => ['en' => 'AAC Cruiserweight M4 (Savage)'],
        'difficulty' => 'savage',
    ]);
    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_version_id' => $version->id,
        'activity_type_id' => $version->activity_type_id,
        'title' => 'Role Run',
        'status' => Activity::STATUS_ASSIGNED,
        'starts_at' => now()->addHour(),
    ]);
    $activity->slots()->delete();

    $linkedUser = User::factory()->create();
    GroupMembership::query()->create([
        'group_id' => $group->id,
        'user_id' => $linkedUser->id,
        'role' => GroupMembership::ROLE_MEMBER,
        'joined_at' => now(),
    ]);
    $linkedCharacter = Character::factory()->primary()->create([
        'user_id' => $linkedUser->id,
        'name' => 'Linked Slot',
        'world' => 'Twintania',
        'datacenter' => 'Light',
    ]);
    DiscordUserIntegration::query()->create([
        'user_id' => $linkedUser->id,
        'discord_user_id' => '900000000000000001',
        'username' => 'linked-slot',
        'user_app_installed_at' => now(),
    ]);
    ActivitySlot::factory()->assignedTo($linkedCharacter)->create([
        'activity_id' => $activity->id,
        'group_key' => 'party-a',
        'group_label' => ['en' => 'Party A'],
        'slot_key' => 'party-a-slot-1',
        'slot_label' => ['en' => 'Party A 1'],
        'sort_order' => 1,
    ]);
    ActivityApplication::factory()->approved()->create([
        'activity_id' => $activity->id,
        'user_id' => $linkedUser->id,
        'selected_character_id' => $linkedCharacter->id,
    ]);

    $benchUser = User::factory()->create();
    $benchCharacter = Character::factory()->primary()->create([
        'user_id' => $benchUser->id,
        'name' => 'Linked Bench',
        'world' => 'Lich',
        'datacenter' => 'Light',
    ]);
    DiscordUserIntegration::query()->create([
        'user_id' => $benchUser->id,
        'discord_user_id' => '900000000000000002',
        'username' => 'linked-bench',
        'user_app_installed_at' => now(),
    ]);
    ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $benchUser->id,
        'selected_character_id' => $benchCharacter->id,
        'status' => ActivityApplication::STATUS_ON_BENCH,
    ]);

    $unlinkedUser = User::factory()->create();
    $unlinkedCharacter = Character::factory()->primary()->create([
        'user_id' => $unlinkedUser->id,
        'name' => 'No Discord',
        'world' => 'Omega',
        'datacenter' => 'Chaos',
        'avatar_url' => null,
    ]);
    ActivitySlot::factory()->assignedTo($unlinkedCharacter)->create([
        'activity_id' => $activity->id,
        'group_key' => 'party-a',
        'slot_key' => 'party-a-slot-2',
        'slot_label' => ['en' => 'Party A 2'],
        'sort_order' => 2,
    ]);

    $response = $this->getJson(route('api.integrations.discord-guilds.runs.role-assignment', [
        'discordGuildId' => '133700000000000002',
        'activity' => $activity,
    ]), [
        'Authorization' => 'Bearer '.$token,
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.run.id', $activity->id)
        ->assertJsonPath('data.run.display_name', 'Role Run')
        ->assertJsonPath('data.discord_guild.id', '133700000000000002')
        ->assertJsonPath('data.discord_user_ids.0', '900000000000000001')
        ->assertJsonPath('data.discord_user_ids.1', '900000000000000002')
        ->assertJsonPath('data.participants.0.discord_user_id', '900000000000000001')
        ->assertJsonPath('data.participants.0.is_discord_linked', true)
        ->assertJsonPath('data.participants.0.is_group_member', true)
        ->assertJsonPath('data.participants.0.group_role', GroupMembership::ROLE_MEMBER)
        ->assertJsonPath('data.participants.0.source', 'slot')
        ->assertJsonPath('data.participants.0.character.name', 'Linked Slot')
        ->assertJsonPath('data.participants.0.slot.slot_key', 'party-a-slot-1')
        ->assertJsonPath('data.participants.1.discord_user_id', '900000000000000002')
        ->assertJsonPath('data.participants.1.is_discord_linked', true)
        ->assertJsonPath('data.participants.1.is_group_member', true)
        ->assertJsonPath('data.participants.1.group_role', GroupMembership::ROLE_MEMBER)
        ->assertJsonPath('data.participants.1.source', 'application')
        ->assertJsonPath('data.participants.1.character.name', 'Linked Bench')
        ->assertJsonPath('data.unlinked_participants.0.user_id', $unlinkedUser->id)
        ->assertJsonPath('data.unlinked_participants.0.discord_user_id', null)
        ->assertJsonPath('data.unlinked_participants.0.is_discord_linked', false)
        ->assertJsonPath('data.unlinked_participants.0.is_group_member', false)
        ->assertJsonPath('data.unlinked_participants.0.group_role', null)
        ->assertJsonPath('data.unlinked_participants.0.source', 'slot')
        ->assertJsonPath('data.unlinked_participants.0.character.name', 'No Discord')
        ->assertJsonPath('data.unlinked_participants.0.character.world', 'Omega')
        ->assertJsonPath('data.unlinked_count', 1)
        ->assertJsonPath('data.total_placed_count', 3);
});

it('does not return role assignment data for a run outside the linked guild group', function () {
    $token = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()
        ->withApiToken($token)
        ->create([
            'scopes' => [
                IntegrationClient::SCOPE_RUNS_READ,
            ],
        ]);

    $linkedGroup = Group::factory()->create([
        'slug' => 'linkedgp',
    ]);
    DiscordGuildIntegration::query()->create([
        'group_id' => $linkedGroup->id,
        'discord_guild_id' => '133700000000000003',
        'guild_installed_at' => now(),
    ]);

    $otherActivity = Activity::factory()->create([
        'group_id' => Group::factory()->create(['slug' => 'wronggrp'])->id,
        'status' => Activity::STATUS_SCHEDULED,
        'starts_at' => now()->addHour(),
    ]);

    $this->getJson(route('api.integrations.discord-guilds.runs.role-assignment', [
        'discordGuildId' => '133700000000000003',
        'activity' => $otherActivity,
    ]), [
        'Authorization' => 'Bearer '.$token,
    ])->assertNotFound();
});

it('returns not found when an integration asks for an unlinked discord user', function () {
    $token = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()
        ->withApiToken($token)
        ->create([
            'scopes' => [
                IntegrationClient::SCOPE_RUNS_READ,
            ],
        ]);

    $this->getJson(route('api.integrations.discord-users.upcoming-runs.index', [
        'discordUserId' => '323456789012345678',
    ]), [
        'Authorization' => 'Bearer '.$token,
    ])->assertNotFound();
});

it('requires users read scope for discord primary character lookups', function () {
    $token = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()
        ->withApiToken($token)
        ->create([
            'scopes' => [
                IntegrationClient::SCOPE_RUNS_READ,
            ],
        ]);

    $this->postJson(route('api.integrations.discord-users.primary-characters.index'), [
        'discord_user_ids' => ['423456789012345678'],
    ], [
        'Authorization' => 'Bearer '.$token,
    ])->assertForbidden();
});

it('returns primary characters for one or more linked discord users', function () {
    $token = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()
        ->withApiToken($token)
        ->create([
            'scopes' => [
                IntegrationClient::SCOPE_USERS_READ,
            ],
        ]);

    $firstUser = User::factory()->create();
    Character::factory()->primary()->create([
        'user_id' => $firstUser->id,
        'name' => 'Lyra Dawn',
        'world' => 'Twintania',
        'datacenter' => 'Light',
    ]);
    DiscordUserIntegration::query()->create([
        'user_id' => $firstUser->id,
        'discord_user_id' => '523456789012345678',
        'username' => 'lyra',
        'user_app_installed_at' => now(),
    ]);

    $secondUser = User::factory()->create();
    DiscordUserIntegration::query()->create([
        'user_id' => $secondUser->id,
        'discord_user_id' => '623456789012345678',
        'username' => 'no-character',
        'user_app_installed_at' => now(),
    ]);

    $revokedUser = User::factory()->create();
    Character::factory()->primary()->create([
        'user_id' => $revokedUser->id,
        'name' => 'Revoked Link',
        'world' => 'Lich',
        'datacenter' => 'Light',
    ]);
    DiscordUserIntegration::query()->create([
        'user_id' => $revokedUser->id,
        'discord_user_id' => '723456789012345678',
        'username' => 'revoked',
        'user_app_installed_at' => now(),
        'revoked_at' => now(),
    ]);

    $this->postJson(route('api.integrations.discord-users.primary-characters.index'), [
        'discord_user_ids' => [
            '823456789012345678',
            '523456789012345678',
            '623456789012345678',
            '723456789012345678',
        ],
    ], [
        'Authorization' => 'Bearer '.$token,
    ])
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('data.0.discord_user_id', '823456789012345678')
        ->assertJsonPath('data.0.linked', false)
        ->assertJsonPath('data.0.primary_character', null)
        ->assertJsonPath('data.1.discord_user_id', '523456789012345678')
        ->assertJsonPath('data.1.linked', true)
        ->assertJsonPath('data.1.primary_character.name', 'Lyra Dawn')
        ->assertJsonPath('data.1.primary_character.world', 'Twintania')
        ->assertJsonPath('data.1.primary_character.datacenter', 'Light')
        ->assertJsonPath('data.2.discord_user_id', '623456789012345678')
        ->assertJsonPath('data.2.linked', true)
        ->assertJsonPath('data.2.primary_character', null)
        ->assertJsonPath('data.3.discord_user_id', '723456789012345678')
        ->assertJsonPath('data.3.linked', false)
        ->assertJsonPath('data.3.primary_character', null);
});

it('requires users write scope to link a discord user by token', function () {
    $token = IntegrationClient::makePlainApiToken();

    IntegrationClient::factory()
        ->withApiToken($token)
        ->create([
            'scopes' => [
                IntegrationClient::SCOPE_USERS_READ,
            ],
        ]);

    $this->postJson(route('api.integrations.discord-users.link'), [
        'discord_user_id' => '923456789012345678',
        'token' => 'LINK-1234',
    ], [
        'Authorization' => 'Bearer '.$token,
    ])->assertForbidden();
});

it('links a discord user to the account matching a generated token', function () {
    $token = IntegrationClient::makePlainApiToken();

    IntegrationClient::factory()
        ->withApiToken($token)
        ->create([
            'scopes' => [
                IntegrationClient::SCOPE_USERS_WRITE,
            ],
        ]);

    $linkToken = 'FULLPARTY-LINK';
    $user = User::factory()->create([
        'discord_link_token_hash' => hash('sha256', $linkToken),
        'discord_link_token_expires_at' => now()->addMinutes(10),
    ]);

    $this->postJson(route('api.integrations.discord-users.link'), [
        'discord_user_id' => '103456789012345678',
        'token' => $linkToken,
        'username' => 'linked-user',
        'global_name' => 'Linked User',
        'avatar_url' => 'https://cdn.discordapp.com/avatar.png',
    ], [
        'Authorization' => 'Bearer '.$token,
    ])
        ->assertOk()
        ->assertJsonPath('data.linked', true)
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.discord_user.id', '103456789012345678')
        ->assertJsonPath('data.discord_user.username', 'linked-user');

    $integration = DiscordUserIntegration::query()->sole();

    expect($integration->user_id)->toBe($user->id)
        ->and($integration->discord_user_id)->toBe('103456789012345678')
        ->and($integration->user_app_installed_at)->not->toBeNull()
        ->and($integration->revoked_at)->toBeNull()
        ->and($user->fresh()->discord_link_token_hash)->toBeNull()
        ->and($user->fresh()->discord_link_token_expires_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'user.discord_app.user_installed')->exists())->toBeTrue()
        ->and($user->onboardingState()->sole()->current_step)->toBe(UserOnboardingState::STEP_NOTIFICATIONS);
});

it('rejects expired or unknown discord user link tokens', function () {
    $token = IntegrationClient::makePlainApiToken();

    IntegrationClient::factory()
        ->withApiToken($token)
        ->create([
            'scopes' => [
                IntegrationClient::SCOPE_USERS_WRITE,
            ],
        ]);

    User::factory()->create([
        'discord_link_token_hash' => hash('sha256', 'EXPIRED-LINK'),
        'discord_link_token_expires_at' => now()->subMinute(),
    ]);

    $this->postJson(route('api.integrations.discord-users.link'), [
        'discord_user_id' => '113456789012345678',
        'token' => 'EXPIRED-LINK',
    ], [
        'Authorization' => 'Bearer '.$token,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('token');
});

it('does not let one discord user id link to another account', function () {
    $token = IntegrationClient::makePlainApiToken();

    IntegrationClient::factory()
        ->withApiToken($token)
        ->create([
            'scopes' => [
                IntegrationClient::SCOPE_USERS_WRITE,
            ],
        ]);

    $existingUser = User::factory()->create();
    DiscordUserIntegration::query()->create([
        'user_id' => $existingUser->id,
        'discord_user_id' => '123456789012345678',
        'username' => 'claimed',
        'user_app_installed_at' => now(),
    ]);

    $linkToken = 'OTHER-LINK';
    User::factory()->create([
        'discord_link_token_hash' => hash('sha256', $linkToken),
        'discord_link_token_expires_at' => now()->addMinutes(10),
    ]);

    $this->postJson(route('api.integrations.discord-users.link'), [
        'discord_user_id' => '123456789012345678',
        'token' => $linkToken,
    ], [
        'Authorization' => 'Bearer '.$token,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('discord_user_id');
});
