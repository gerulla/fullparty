<?php

use App\Events\XivPluginRunCommandAcknowledged;
use App\Events\XivPluginRunCommandIssued;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlotAssignment;
use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\OccultProgress;
use App\Models\PhantomJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    mock_test_passport_resource_server();
});

it('returns the authenticated users groups with their group rank', function () {
    $user = User::factory()->create();

    $ownedGroup = Group::factory()->create([
        'owner_id' => $user->id,
        'name' => 'Owned Static',
        'slug' => 'ownedst',
    ]);

    $memberGroup = Group::factory()
        ->withMember($user, GroupMembership::ROLE_ADMIN)
        ->create([
            'name' => 'Admin Community',
            'slug' => 'admincom',
        ]);

    Group::factory()->create([
        'name' => 'Hidden From Plugin',
        'slug' => 'hiddenpl',
    ]);

    Passport::actingAs($user, ['xivplugin:read']);

    $this->getJson(route('api.xivplugin.groups.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Admin Community')
        ->assertJsonPath('data.0.role', GroupMembership::ROLE_ADMIN)
        ->assertJsonPath('data.0.can_moderate', true)
        ->assertJsonPath('data.1.name', 'Owned Static')
        ->assertJsonPath('data.1.role', GroupMembership::ROLE_OWNER)
        ->assertJsonPath('data.1.can_moderate', true);

    expect($ownedGroup->exists)->toBeTrue()
        ->and($memberGroup->exists)->toBeTrue();
});

it('requires the xiv plugin read scope', function () {
    Passport::actingAs(User::factory()->create(), []);

    $this->getJson(route('api.xivplugin.groups.index'))
        ->assertForbidden();
});

it('returns realtime connection settings for plugin clients', function () {
    config()->set('broadcasting.connections.reverb.key', 'reverb-public-key');
    config()->set('broadcasting.connections.reverb.options.host', 'ws.fullparty.test');
    config()->set('broadcasting.connections.reverb.options.port', 443);
    config()->set('broadcasting.connections.reverb.options.scheme', 'https');

    Passport::actingAs(User::factory()->create(), ['xivplugin:read']);

    $this->getJson(route('api.xivplugin.realtime.show'))
        ->assertOk()
        ->assertJsonPath('data.reverb.app_key', 'reverb-public-key')
        ->assertJsonPath('data.reverb.ws_host', 'ws.fullparty.test')
        ->assertJsonPath('data.reverb.scheme', 'wss')
        ->assertJsonPath('data.reverb.auth_endpoint', route('api.xivplugin.broadcasting.auth'))
        ->assertJsonPath('data.channels.run_presence', 'presence-xivplugin.runs.{run_id}');
});

it('authenticates plugin users into run presence channels with assigned character info', function () {
    config()->set('broadcasting.connections.reverb.key', 'presence-key');
    config()->set('broadcasting.connections.reverb.secret', 'presence-secret');

    $user = User::factory()->create(['name' => 'Party Lead']);
    $character = Character::factory()->create([
        'user_id' => $user->id,
        'name' => 'Lead Character',
        'world' => 'Lich',
        'datacenter' => 'Light',
        'avatar_url' => '/characters/lead.png',
    ]);
    $group = Group::factory()->withMember($user)->create(['slug' => 'plgpres']);
    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'status' => Activity::STATUS_SCHEDULED,
        'starts_at' => now()->addHour(),
    ]);
    $slot = $activity->slots()->firstOrFail();
    $slot->update([
        'assigned_character_id' => $character->id,
        'assigned_by_user_id' => $user->id,
        'is_raid_leader' => true,
    ]);

    Passport::actingAs($user, ['xivplugin:read']);

    $response = $this->postJson(route('api.xivplugin.broadcasting.auth'), [
        'socket_id' => '1234.5678',
        'channel_name' => "presence-xivplugin.runs.{$activity->id}",
    ])->assertOk();

    $channelData = $response->json('channel_data');

    expect($response->json('auth'))->toBe('presence-key:'.hash_hmac(
        'sha256',
        "1234.5678:presence-xivplugin.runs.{$activity->id}:{$channelData}",
        'presence-secret',
    ));

    $decodedChannelData = json_decode($channelData, true, flags: JSON_THROW_ON_ERROR);

    expect($decodedChannelData['user_id'])->toBe((string) $user->id)
        ->and($decodedChannelData['user_info']['user']['name'])->toBe('Party Lead')
        ->and($decodedChannelData['user_info']['slots'][0]['id'])->toBe($slot->id)
        ->and($decodedChannelData['user_info']['slots'][0]['character']['name'])->toBe('Lead Character')
        ->and($decodedChannelData['user_info']['slots'][0]['character']['avatar_url'])->toBe(asset('characters/lead.png'))
        ->and($decodedChannelData['user_info']['slots'][0]['is_raid_leader'])->toBeTrue();
});

it('lets run hosts broadcast plugin commands to resolved party lead targets', function () {
    Event::fake([XivPluginRunCommandIssued::class]);

    $host = User::factory()->create(['name' => 'Run Host']);
    $leader = User::factory()->create(['name' => 'Party Lead']);
    $hostCharacter = Character::factory()->create(['user_id' => $host->id, 'name' => 'Host Character']);
    $leaderCharacter = Character::factory()->create(['user_id' => $leader->id, 'name' => 'Leader Character']);
    $group = Group::factory()
        ->withMember($host)
        ->withMember($leader)
        ->create(['slug' => 'plgcmds']);
    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'status' => Activity::STATUS_SCHEDULED,
        'starts_at' => now()->addHour(),
    ]);
    $slots = $activity->slots()->take(2)->get();
    $slots[0]->update([
        'assigned_character_id' => $hostCharacter->id,
        'assigned_by_user_id' => $host->id,
        'is_host' => true,
    ]);
    $slots[1]->update([
        'assigned_character_id' => $leaderCharacter->id,
        'assigned_by_user_id' => $host->id,
        'is_raid_leader' => true,
    ]);

    Passport::actingAs($host, ['xivplugin:read']);

    $this->postJson(route('api.xivplugin.runs.commands.store', $activity), [
        'command' => 'place_markers',
        'target' => ['type' => 'party_leads'],
        'payload' => ['preset' => 'forked_tower_phase_1'],
        'idempotency_key' => 'marker-command-1',
    ])
        ->assertOk()
        ->assertJsonPath('data.command', 'place_markers')
        ->assertJsonPath('data.target.type', 'party_leads')
        ->assertJsonPath('data.target.user_ids.0', $leader->id)
        ->assertJsonPath('data.target.slots.0.character.name', 'Leader Character')
        ->assertJsonPath('data.payload.preset', 'forked_tower_phase_1');

    Event::assertDispatched(XivPluginRunCommandIssued::class, function (XivPluginRunCommandIssued $event) use ($activity, $leader): bool {
        return $event->activityId === $activity->id
            && $event->command['target']['user_ids'] === [$leader->id]
            && ! array_key_exists('slots', $event->command['target'])
            && $event->command['target']['slot_count'] === 1
            && $event->command['command'] === 'place_markers';
    });
});

it('lets plugin clients acknowledge run commands', function () {
    Event::fake([XivPluginRunCommandAcknowledged::class]);

    $user = User::factory()->create(['name' => 'Ack User']);
    $character = Character::factory()->create(['user_id' => $user->id, 'name' => 'Ack Character']);
    $group = Group::factory()->withMember($user)->create(['slug' => 'plgacks']);
    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'status' => Activity::STATUS_SCHEDULED,
        'starts_at' => now()->addHour(),
    ]);
    $slot = $activity->slots()->firstOrFail();
    $slot->update([
        'assigned_character_id' => $character->id,
        'assigned_by_user_id' => $user->id,
    ]);

    Passport::actingAs($user, ['xivplugin:read']);

    $this->postJson(route('api.xivplugin.runs.commands.acknowledgements.store', [$activity, 'cmd_123']), [
        'status' => 'executed',
    ])
        ->assertOk()
        ->assertJsonPath('data.command_id', 'cmd_123')
        ->assertJsonPath('data.status', 'executed')
        ->assertJsonPath('data.slots.0.id', $slot->id)
        ->assertJsonPath('data.slots.0.character.name', 'Ack Character');

    Event::assertDispatched(XivPluginRunCommandAcknowledged::class, function (XivPluginRunCommandAcknowledged $event) use ($activity, $user): bool {
        return $event->activityId === $activity->id
            && $event->acknowledgement['command_id'] === 'cmd_123'
            && $event->acknowledgement['acknowledged_by']['user_id'] === $user->id;
    });
});

it('lists future group runs and only includes drafts for moderators', function () {
    $member = User::factory()->create();
    $moderator = User::factory()->create();

    $group = Group::factory()
        ->withMember($member)
        ->withMember($moderator, GroupMembership::ROLE_MODERATOR)
        ->create([
            'slug' => 'plgruns',
        ]);

    $futureRun = Activity::factory()->create([
        'group_id' => $group->id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Future Run',
        'starts_at' => now()->addDay(),
    ]);

    $draftRun = Activity::factory()->create([
        'group_id' => $group->id,
        'status' => Activity::STATUS_DRAFT,
        'title' => 'Draft Run',
        'starts_at' => now()->addDays(2),
    ]);

    Activity::factory()->create([
        'group_id' => $group->id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Past Run',
        'starts_at' => now()->subDay(),
    ]);

    ActivityApplication::factory()->create([
        'activity_id' => $futureRun->id,
    ]);

    ActivityApplication::factory()->declined($group->owner)->create([
        'activity_id' => $futureRun->id,
    ]);

    Passport::actingAs($member, ['xivplugin:read']);

    $this->getJson(route('api.xivplugin.groups.runs.index', $group))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $futureRun->id)
        ->assertJsonPath('data.0.name', 'Future Run')
        ->assertJsonPath('data.0.application_count', null);

    Passport::actingAs($moderator, ['xivplugin:read']);

    $this->getJson(route('api.xivplugin.groups.runs.index', $group))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $futureRun->id)
        ->assertJsonPath('data.0.application_count', 1)
        ->assertJsonPath('data.1.id', $draftRun->id);
});

it('returns run details with roster data and moderator access metadata', function () {
    $moderator = User::factory()->create();
    $assignedUser = User::factory()->create(['name' => 'Roster User']);
    $assignedCharacter = Character::factory()->create([
        'user_id' => $assignedUser->id,
        'name' => 'Giki Chomusuke',
        'world' => 'Lich',
        'datacenter' => 'Light',
        'avatar_url' => '/characters/giki.png',
    ]);

    $group = Group::factory()
        ->withMember($moderator, GroupMembership::ROLE_MODERATOR)
        ->withMember($assignedUser)
        ->create([
            'slug' => 'plgrstr',
        ]);

    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => null,
        'starts_at' => now()->addHours(3),
        'duration_hours' => 2.5,
    ]);

    $slot = $activity->slots()->firstOrFail();
    $phantomJob = PhantomJob::query()->create([
        'name' => 'Geomancer',
        'max_level' => 20,
        'icon_url' => '/phantom-jobs/geomancer.png',
        'black_icon_url' => '/phantom-jobs/geomancer-black.png',
        'transparent_icon_url' => '/phantom-jobs/geomancer-transparent.png',
        'sprite_url' => '/phantom-jobs/geomancer-sprite.png',
    ]);

    $slot->update([
        'assigned_character_id' => $assignedCharacter->id,
        'assigned_by_user_id' => $moderator->id,
        'is_host' => true,
    ]);

    $slot->fieldValues()->where('field_key', 'character_class')->firstOrFail()->update([
        'value' => [
            'id' => 1,
            'name' => 'Astrologian',
            'shorthand' => 'AST',
            'role' => 'Healer',
        ],
    ]);

    $slot->fieldValues()->where('field_key', 'phantom_job')->firstOrFail()->update([
        'value' => [
            'id' => $phantomJob->id,
            'name' => 'Geomancer',
            'max_level' => 20,
        ],
    ]);

    ActivitySlotAssignment::query()->create([
        'activity_id' => $activity->id,
        'group_id' => $group->id,
        'activity_slot_id' => $slot->id,
        'character_id' => $assignedCharacter->id,
        'assignment_source' => ActivitySlotAssignment::SOURCE_MANUAL,
        'attendance_status' => ActivitySlotAssignment::STATUS_CHECKED_IN,
        'assigned_at' => now()->subHour(),
        'assigned_by_user_id' => $moderator->id,
        'checked_in_at' => now(),
        'checked_in_by_user_id' => $moderator->id,
    ]);

    ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
    ]);

    ActivityApplication::factory()->declined($group->owner)->create([
        'activity_id' => $activity->id,
    ]);

    Passport::actingAs($moderator, ['xivplugin:read']);

    $this->getJson(route('api.xivplugin.runs.show', $activity))
        ->assertOk()
        ->assertJsonPath('data.id', $activity->id)
        ->assertJsonPath('data.name', $activity->activityTypeVersion->name['en'])
        ->assertJsonPath('data.can_moderate', true)
        ->assertJsonPath('data.duration_minutes', 150)
        ->assertJsonPath('data.application_count', 1)
        ->assertJsonPath('data.roster.slots.0.assigned_character.name', 'Giki Chomusuke')
        ->assertJsonPath('data.roster.slots.0.assigned_character.user.name', 'Roster User')
        ->assertJsonPath('data.roster.slots.0.assignment.application_id', null)
        ->assertJsonPath('data.roster.slots.0.assignment.attendance_status', ActivitySlotAssignment::STATUS_CHECKED_IN)
        ->assertJsonPath('data.roster.slots.0.field_values.0.value.shorthand', 'AST')
        ->assertJsonPath('data.roster.slots.0.field_values.1.value.name', 'Geomancer')
        ->assertJsonPath('data.roster.slots.0.field_values.1.value.icon_url', asset('phantom-jobs/geomancer.png'))
        ->assertJsonPath('data.roster.slots.0.field_values.1.value.black_icon_url', asset('phantom-jobs/geomancer-black.png'))
        ->assertJsonPath('data.roster.slots.0.field_values.1.value.transparent_icon_url', asset('phantom-jobs/geomancer-transparent.png'))
        ->assertJsonPath('data.roster.slots.0.field_values.1.value.sprite_url', asset('phantom-jobs/geomancer-sprite.png'));
});

it('returns the application attached to an assigned slot for moderators', function () {
    $moderator = User::factory()->create();
    $member = User::factory()->create();
    $applicant = User::factory()->create(['name' => 'Applicant User']);
    $character = Character::factory()->create([
        'user_id' => $applicant->id,
        'name' => 'Applied Character',
        'world' => 'Twintania',
        'datacenter' => 'Light',
        'avatar_url' => '/characters/applied.png',
        'lodestone_refreshed_at' => now()->subMinutes(12),
    ]);
    $characterClass = CharacterClass::query()->create([
        'name' => 'Astrologian',
        'shorthand' => 'AST',
        'role' => 'healer',
        'icon_url' => '/class-icons/astrologian.png',
        'flaticon_url' => '/class-icons/astrologian-flat.png',
    ]);
    $phantomJob = PhantomJob::query()->create([
        'name' => 'Geomancer',
        'max_level' => 20,
        'icon_url' => '/phantom-jobs/geomancer.png',
        'black_icon_url' => '/phantom-jobs/geomancer-black.png',
        'transparent_icon_url' => '/phantom-jobs/geomancer-transparent.png',
        'sprite_url' => '/phantom-jobs/geomancer-sprite.png',
    ]);

    $character->classes()->attach($characterClass->id, [
        'level' => 100,
        'is_preferred' => true,
    ]);
    $character->phantomJobs()->attach($phantomJob->id, [
        'current_level' => 20,
        'is_preferred' => true,
    ]);
    $character->occultProgress()->create([
        'data_source' => OccultProgress::DATA_SOURCE_FFLOGS,
        'knowledge_level' => 17,
        'demon_tablet_kills' => 3,
        'demon_tablet_progress' => 100,
        'dead_stars_kills' => 1,
        'dead_stars_progress' => 100,
        'marble_dragon_kills' => 0,
        'marble_dragon_progress' => 64,
        'magitaur_kills' => 0,
        'magitaur_progress' => 0,
    ]);

    $group = Group::factory()
        ->withMember($moderator, GroupMembership::ROLE_MODERATOR)
        ->withMember($member)
        ->withMember($applicant)
        ->create([
            'slug' => 'plgapps',
        ]);

    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Application Run',
        'starts_at' => now()->addDay(),
    ]);
    $activity->activityTypeVersion->update([
        'application_schema' => [
            [
                'key' => 'preferred_class',
                'label' => ['en' => 'Preferred Class'],
                'type' => 'multi_select',
                'source' => 'character_classes',
            ],
            [
                'key' => 'preferred_phantom_job',
                'label' => ['en' => 'Preferred Phantom Job'],
                'type' => 'single_select',
                'source' => 'phantom_jobs',
            ],
            [
                'key' => 'preferred_role',
                'label' => ['en' => 'Preferred Role'],
                'type' => 'single_select',
                'source' => 'static_options',
                'options' => [
                    [
                        'value' => 'healer',
                        'label' => ['en' => 'Healer'],
                    ],
                ],
            ],
        ],
        'progress_schema' => [
            'milestones' => [
                [
                    'key' => 'demon_tablet',
                    'label' => ['en' => 'Demon Tablet'],
                    'fflogs_matcher' => [
                        'type' => 'encounter',
                        'encounter_id' => 2062,
                    ],
                ],
            ],
        ],
    ]);

    $application = ActivityApplication::factory()->approved($moderator)->create([
        'activity_id' => $activity->id,
        'user_id' => $applicant->id,
        'selected_character_id' => $character->id,
        'notes' => 'I can flex around the group.',
    ]);

    $application->answers()->updateOrCreate(
        ['question_key' => 'preferred_class'],
        [
            'question_label' => ['en' => 'Preferred Class'],
            'question_type' => 'multi_select',
            'source' => 'character_classes',
            'value' => [(string) $characterClass->id],
        ],
    );

    $application->answers()->updateOrCreate(
        ['question_key' => 'preferred_phantom_job'],
        [
            'question_label' => ['en' => 'Preferred Phantom Job'],
            'question_type' => 'single_select',
            'source' => 'phantom_jobs',
            'value' => [(string) $phantomJob->id],
        ],
    );

    $application->answers()->updateOrCreate(
        ['question_key' => 'preferred_role'],
        [
            'question_label' => ['en' => 'Preferred Role'],
            'question_type' => 'single_select',
            'source' => 'static_options',
            'value' => 'healer',
        ],
    );

    $completedRun = Activity::factory()->complete()->create([
        'group_id' => $group->id,
        'starts_at' => now()->subWeek(),
    ]);
    $completedSlot = $completedRun->slots()->firstOrFail();
    $completedSlot->update([
        'assigned_character_id' => $character->id,
        'assigned_by_user_id' => $moderator->id,
    ]);
    $completedSlot->fieldValues()->where('field_key', 'character_class')->firstOrFail()->update([
        'value' => [
            'id' => $characterClass->id,
            'name' => 'Astrologian',
            'shorthand' => 'AST',
            'role' => 'healer',
        ],
    ]);
    $completedSlot->fieldValues()->where('field_key', 'phantom_job')->firstOrFail()->update([
        'value' => [
            'id' => $phantomJob->id,
            'name' => 'Geomancer',
            'max_level' => 20,
        ],
    ]);

    $slot = $activity->slots()->firstOrFail();
    $slot->update([
        'assigned_character_id' => $character->id,
        'assigned_by_user_id' => $moderator->id,
    ]);

    ActivitySlotAssignment::query()->create([
        'activity_id' => $activity->id,
        'group_id' => $group->id,
        'activity_slot_id' => $slot->id,
        'character_id' => $character->id,
        'application_id' => $application->id,
        'assignment_source' => ActivitySlotAssignment::SOURCE_APPLICATION,
        'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
        'assigned_at' => now(),
        'assigned_by_user_id' => $moderator->id,
    ]);

    Passport::actingAs($moderator, ['xivplugin:read']);

    $this->getJson(route('api.xivplugin.runs.show', $activity))
        ->assertOk()
        ->assertJsonPath('data.roster.slots.0.assignment.application_id', $application->id);

    $this->getJson(route('api.xivplugin.runs.slots.application.show', [$activity, $slot]))
        ->assertOk()
        ->assertJsonPath('data.id', $application->id)
        ->assertJsonPath('data.activity_id', $activity->id)
        ->assertJsonPath('data.status', ActivityApplication::STATUS_APPROVED)
        ->assertJsonPath('data.notes', 'I can flex around the group.')
        ->assertJsonPath('data.user.name', 'Applicant User')
        ->assertJsonPath('data.selected_character.name', 'Applied Character')
        ->assertJsonPath('data.selected_character.avatar_url', asset('characters/applied.png'))
        ->assertJsonPath('data.applicant_character.avatar_url', asset('characters/applied.png'))
        ->assertJsonPath('data.slot.id', $slot->id)
        ->assertJsonPath('data.answers.2.question_key', 'preferred_role')
        ->assertJsonPath('data.answers.2.value', 'healer')
        ->assertJsonPath('data.details.id', $application->id)
        ->assertJsonPath('data.details.selected_character.occult_level', 17)
        ->assertJsonPath('data.details.selected_character.phantom_mastery', 1)
        ->assertJsonPath('data.details.selected_character.preferred_character_class_ids.0', (string) $characterClass->id)
        ->assertJsonPath('data.details.selected_character.preferred_phantom_job_ids.0', (string) $phantomJob->id)
        ->assertJsonPath('data.details.answers.0.display_values.0', 'Astrologian')
        ->assertJsonPath('data.details.answers.0.role_values.0', 'Healer')
        ->assertJsonPath('data.details.answers.0.display_items.0.label', 'Astrologian')
        ->assertJsonPath('data.details.answers.0.display_items.0.icon_url', asset('class-icons/astrologian.png'))
        ->assertJsonPath('data.details.answers.0.display_items.0.flat_icon_url', asset('class-icons/astrologian-flat.png'))
        ->assertJsonPath('data.details.answers.1.display_values.0', 'Geomancer')
        ->assertJsonPath('data.details.answers.1.display_items.0.icon_url', asset('phantom-jobs/geomancer.png'))
        ->assertJsonPath('data.details.answers.1.display_items.0.transparent_icon_url', asset('phantom-jobs/geomancer-transparent.png'))
        ->assertJsonPath('data.details.progress_milestones.0.key', 'demon_tablet')
        ->assertJsonPath('data.details.progress_milestones.0.kills', 3)
        ->assertJsonPath('data.details.progress_milestones.0.progress_percent', 100)
        ->assertJsonPath('data.details.user_stats.group_run_count', 1)
        ->assertJsonPath('data.details.user_stats.overall_run_count', 1)
        ->assertJsonPath('data.details.user_stats.class.group.0.label', 'Astrologian')
        ->assertJsonPath('data.details.user_stats.phantom_job.group.0.label', 'Geomancer');

    Passport::actingAs($member, ['xivplugin:read']);

    $this->getJson(route('api.xivplugin.runs.show', $activity))
        ->assertOk()
        ->assertJsonPath('data.roster.slots.0.assignment.application_id', null);

    $this->getJson(route('api.xivplugin.runs.slots.application.show', [$activity, $slot]))
        ->assertNotFound();
});

it('does not return slot application data for manual assignments', function () {
    $moderator = User::factory()->create();
    $assignedUser = User::factory()->create();
    $assignedCharacter = Character::factory()->create([
        'user_id' => $assignedUser->id,
    ]);

    $group = Group::factory()
        ->withMember($moderator, GroupMembership::ROLE_MODERATOR)
        ->withMember($assignedUser)
        ->create();

    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'status' => Activity::STATUS_SCHEDULED,
        'starts_at' => now()->addDay(),
    ]);

    $slot = $activity->slots()->firstOrFail();
    $slot->update([
        'assigned_character_id' => $assignedCharacter->id,
        'assigned_by_user_id' => $moderator->id,
    ]);

    ActivitySlotAssignment::query()->create([
        'activity_id' => $activity->id,
        'group_id' => $group->id,
        'activity_slot_id' => $slot->id,
        'character_id' => $assignedCharacter->id,
        'application_id' => null,
        'assignment_source' => ActivitySlotAssignment::SOURCE_MANUAL,
        'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
        'assigned_at' => now(),
        'assigned_by_user_id' => $moderator->id,
    ]);

    Passport::actingAs($moderator, ['xivplugin:read']);

    $this->getJson(route('api.xivplugin.runs.slots.application.show', [$activity, $slot]))
        ->assertNotFound();
});

it('does not expose drafts or outside group runs to regular plugin users', function () {
    $user = User::factory()->create();
    $group = Group::factory()->withMember($user)->create();
    $otherGroup = Group::factory()->create();

    $draftRun = Activity::factory()->create([
        'group_id' => $group->id,
        'status' => Activity::STATUS_DRAFT,
        'starts_at' => now()->addDay(),
    ]);

    $otherRun = Activity::factory()->create([
        'group_id' => $otherGroup->id,
        'status' => Activity::STATUS_SCHEDULED,
        'starts_at' => now()->addDay(),
    ]);

    Passport::actingAs($user, ['xivplugin:read']);

    $this->getJson(route('api.xivplugin.runs.show', $draftRun))
        ->assertNotFound();

    $this->getJson(route('api.xivplugin.runs.show', $otherRun))
        ->assertNotFound();
});
