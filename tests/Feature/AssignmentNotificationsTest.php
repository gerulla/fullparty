<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\Group;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\PhantomJob;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\UserNotification;
use App\Jobs\SendNotificationEmailDeliveryJob;
use App\Services\Groups\ActivitySlotBench;
use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\NotificationChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function createAssignmentNotificationActivity(User $owner, Group $group, array $activityOverrides = []): Activity
{
    $type = ActivityType::factory()->create([
        'created_by_user_id' => $owner->id,
    ]);

    $version = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $type->id,
        'published_by_user_id' => $owner->id,
        'layout_schema' => [
            'groups' => [
                [
                    'key' => 'party-a',
                    'label' => ['en' => 'Party A'],
                    'size' => 1,
                ],
            ],
        ],
        'slot_schema' => [],
        'bench_size' => 1,
        'application_schema' => [],
        'prog_points' => [
            [
                'key' => 'p1',
                'label' => ['en' => 'P1'],
            ],
        ],
    ]);

    $type->update([
        'current_published_version_id' => $version->id,
    ]);

    $activity = Activity::factory()->create(array_merge([
        'group_id' => $group->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_PLANNED,
        'title' => 'Weekly Savage',
        'needs_application' => true,
        'allow_guest_applications' => false,
        'is_public' => true,
    ], $activityOverrides));

    ActivitySlot::query()->firstOrCreate([
        'activity_id' => $activity->id,
        'group_key' => ActivitySlotBench::GROUP_KEY,
        'slot_key' => sprintf('%s-slot-1', ActivitySlotBench::GROUP_KEY),
    ], [
        'group_label' => ['en' => 'Bench'],
        'slot_label' => ['en' => 'Bench 1'],
        'position_in_group' => 1,
        'sort_order' => 99,
        'assigned_character_id' => null,
        'assigned_by_user_id' => null,
    ]);

    return $activity;
}

function createPublishedAssignmentFieldNotificationSetup(): array
{
    $owner = User::factory()->create();
    $group = Group::factory()->public()->create([
        'owner_id' => $owner->id,
    ]);

    $tankClass = CharacterClass::create([
        'name' => 'Paladin',
        'shorthand' => 'PLD',
        'role' => 'tank',
    ]);
    $healerClass = CharacterClass::create([
        'name' => 'White Mage',
        'shorthand' => 'WHM',
        'role' => 'healer',
    ]);
    $phantomKnight = PhantomJob::create([
        'name' => 'Phantom Knight',
        'max_level' => 20,
    ]);
    $phantomBard = PhantomJob::create([
        'name' => 'Phantom Bard',
        'max_level' => 20,
    ]);

    $type = ActivityType::factory()->create([
        'created_by_user_id' => $owner->id,
    ]);

    $version = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $type->id,
        'published_by_user_id' => $owner->id,
        'layout_schema' => [
            'groups' => [
                [
                    'key' => 'party-a',
                    'label' => ['en' => 'Party A'],
                    'size' => 1,
                ],
            ],
        ],
        'slot_schema' => [
            [
                'key' => 'character_class',
                'label' => ['en' => 'Character Class'],
                'type' => 'single_select',
                'source' => 'character_classes',
            ],
            [
                'key' => 'phantom_job',
                'label' => ['en' => 'Phantom Job'],
                'type' => 'single_select',
                'source' => 'phantom_jobs',
            ],
        ],
        'application_schema' => [
            [
                'key' => 'character_class',
                'label' => ['en' => 'Can Play'],
                'type' => 'multi_select',
                'required' => true,
                'source' => 'character_classes',
            ],
            [
                'key' => 'phantom_job',
                'label' => ['en' => 'Phantom Job'],
                'type' => 'multi_select',
                'required' => true,
                'source' => 'phantom_jobs',
            ],
        ],
        'bench_size' => 0,
        'prog_points' => [
            [
                'key' => 'p1',
                'label' => ['en' => 'P1'],
            ],
        ],
    ]);

    $type->update([
        'current_published_version_id' => $version->id,
    ]);

    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_ASSIGNED,
        'title' => 'Weekly Savage',
        'needs_application' => true,
        'allow_guest_applications' => false,
        'is_public' => true,
    ]);

    $mainSlot = $activity->slots()->where('group_key', 'party-a')->firstOrFail();

    return compact(
        'owner',
        'group',
        'activity',
        'mainSlot',
        'tankClass',
        'healerClass',
        'phantomKnight',
        'phantomBard',
    );
}

it('notifies signed in applicants of their roster positions when the roster is published', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $group = Group::factory()->public()->create([
        'owner_id' => $owner->id,
    ]);
    $activity = createAssignmentNotificationActivity($owner, $group, [
        'status' => Activity::STATUS_SCHEDULED,
    ]);

    $rosterUser = User::factory()->create([
        'assignment_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);
    $benchUser = User::factory()->create([
        'assignment_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);
    $guestCharacter = Character::factory()->provisional()->create([
        'name' => 'Guest Bench',
        'lodestone_id' => '99887766',
    ]);

    $rosterCharacter = Character::factory()->primary()->create([
        'user_id' => $rosterUser->id,
        'name' => 'Astra Vale',
        'lodestone_id' => '11110000',
    ]);
    $benchCharacter = Character::factory()->primary()->create([
        'user_id' => $benchUser->id,
        'name' => 'Bryn Sol',
        'lodestone_id' => '22220000',
    ]);

    $mainSlot = $activity->slots()->where('group_key', 'party-a')->firstOrFail();
    $benchSlot = $activity->slots()->where('group_key', ActivitySlotBench::GROUP_KEY)->firstOrFail();

    $mainSlot->update([
        'assigned_character_id' => $rosterCharacter->id,
        'assigned_by_user_id' => $owner->id,
    ]);
    $benchSlot->update([
        'assigned_character_id' => $benchCharacter->id,
        'assigned_by_user_id' => $owner->id,
    ]);

    SocialAccount::query()->create([
        'user_id' => $rosterUser->id,
        'provider' => NotificationChannel::DISCORD,
        'provider_user_id' => 'discord-roster-user',
        'provider_name' => 'Roster User',
        'provider_email' => $rosterUser->email,
    ]);

    SocialAccount::query()->create([
        'user_id' => $benchUser->id,
        'provider' => NotificationChannel::DISCORD,
        'provider_user_id' => 'discord-bench-user',
        'provider_name' => 'Bench User',
        'provider_email' => $benchUser->email,
    ]);

    ActivityApplication::factory()->approved($owner)->create([
        'activity_id' => $activity->id,
        'user_id' => $rosterUser->id,
        'selected_character_id' => $rosterCharacter->id,
        'applicant_lodestone_id' => $rosterCharacter->lodestone_id,
        'applicant_character_name' => $rosterCharacter->name,
        'applicant_world' => $rosterCharacter->world,
        'applicant_datacenter' => $rosterCharacter->datacenter,
    ]);

    ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $benchUser->id,
        'selected_character_id' => $benchCharacter->id,
        'status' => ActivityApplication::STATUS_ON_BENCH,
        'reviewed_by_user_id' => $owner->id,
        'reviewed_at' => now(),
        'applicant_lodestone_id' => $benchCharacter->lodestone_id,
        'applicant_character_name' => $benchCharacter->name,
        'applicant_world' => $benchCharacter->world,
        'applicant_datacenter' => $benchCharacter->datacenter,
    ]);

    ActivityApplication::factory()->guest()->approved($owner)->create([
        'activity_id' => $activity->id,
        'selected_character_id' => $guestCharacter->id,
        'applicant_lodestone_id' => $guestCharacter->lodestone_id,
        'applicant_character_name' => $guestCharacter->name,
        'applicant_world' => $guestCharacter->world,
        'applicant_datacenter' => $guestCharacter->datacenter,
    ]);

    $this->actingAs($owner);

    $this->post(route('groups.dashboard.activities.publish-roster', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]))->assertRedirect(route('groups.dashboard.activities.show', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    $events = NotificationEvent::query()
        ->whereIn('type', [
            'assignments.roster_published_assigned',
            'assignments.roster_published_bench',
        ])
        ->orderBy('type')
        ->get();

    expect($events)->toHaveCount(2)
        ->and($events->pluck('category')->unique()->values()->all())->toBe([NotificationCategory::ASSIGNMENTS]);

    $assignedEvent = $events->firstWhere('type', 'assignments.roster_published_assigned');
    $benchEvent = $events->firstWhere('type', 'assignments.roster_published_bench');

    expect($assignedEvent?->message_params['slot'])->toBe('Party A 1')
        ->and($benchEvent?->message_params['character'])->toBe('Bryn Sol');

    $assignedNotification = UserNotification::query()->where('notification_event_id', $assignedEvent->id)->sole();
    $benchNotification = UserNotification::query()->where('notification_event_id', $benchEvent->id)->sole();

    expect($assignedNotification->user_id)->toBe($rosterUser->id)
        ->and($benchNotification->user_id)->toBe($benchUser->id)
        ->and(UserNotification::query()->count())->toBe(2);

    expect(NotificationDelivery::query()->count())->toBe(4);

    $emailDeliveries = NotificationDelivery::query()
        ->whereIn('notification_event_id', $events->pluck('id'))
        ->where('channel', NotificationChannel::EMAIL)
        ->get();

    $discordDeliveries = NotificationDelivery::query()
        ->whereIn('notification_event_id', $events->pluck('id'))
        ->where('channel', NotificationChannel::DISCORD)
        ->get();

    expect($emailDeliveries)->toHaveCount(2)
        ->and($emailDeliveries->pluck('status')->unique()->values()->all())->toBe([NotificationDelivery::STATUS_PENDING])
        ->and($discordDeliveries)->toHaveCount(2)
        ->and($discordDeliveries->pluck('status')->unique()->values()->all())->toBe([NotificationDelivery::STATUS_SKIPPED])
        ->and($discordDeliveries->pluck('status_reason')->unique()->values()->all())->toBe(['discord_transport_unavailable']);

Queue::assertPushed(SendNotificationEmailDeliveryJob::class, 2);
});

it('notifies a manually assigned member when they are added to a published roster slot', function () {
    Queue::fake();

    extract(createPublishedAssignmentFieldNotificationSetup());

    $member = User::factory()->create([
        'assignment_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);
    $group->memberships()->create([
        'user_id' => $member->id,
        'role' => 'member',
        'joined_at' => now(),
    ]);

    $character = Character::factory()->primary()->create([
        'user_id' => $member->id,
        'name' => 'Nyx Vireo',
        'lodestone_id' => '77665544',
        'verified_at' => now(),
    ]);

    $character->classes()->attach($tankClass->id, [
        'level' => 100,
        'is_preferred' => true,
    ]);
    $character->phantomJobs()->attach($phantomKnight->id, [
        'current_level' => $phantomKnight->max_level,
        'is_preferred' => true,
    ]);

    SocialAccount::query()->create([
        'user_id' => $member->id,
        'provider' => NotificationChannel::DISCORD,
        'provider_user_id' => 'discord-manual-member',
        'provider_name' => 'Manual Member',
        'provider_email' => $member->email,
    ]);

    $this->actingAs($owner);

    $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'character_id' => $character->id,
        'expected_slot_state_token' => activity_slot_state_token($mainSlot->fresh()),
        'field_values' => [
            'character_class' => (string) $tankClass->id,
            'phantom_job' => (string) $phantomKnight->id,
        ],
    ])->assertOk();

    $event = NotificationEvent::query()->where('type', 'assignments.assigned')->sole();

    expect($event->message_params['character'])->toBe('Nyx Vireo')
        ->and(UserNotification::query()->where('user_id', $member->id)->count())->toBe(1)
        ->and(NotificationDelivery::query()->where('user_id', $member->id)->where('channel', NotificationChannel::EMAIL)->count())->toBe(1)
        ->and(NotificationDelivery::query()->where('user_id', $member->id)->where('channel', NotificationChannel::DISCORD)->count())->toBe(1);
});

it('notifies manually assigned members when the roster is published', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $group = Group::factory()->public()->create([
        'owner_id' => $owner->id,
    ]);
    $activity = createAssignmentNotificationActivity($owner, $group, [
        'status' => Activity::STATUS_SCHEDULED,
    ]);

    $member = User::factory()->create([
        'assignment_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);
    $group->memberships()->create([
        'user_id' => $member->id,
        'role' => 'member',
        'joined_at' => now(),
    ]);

    $character = Character::factory()->primary()->create([
        'user_id' => $member->id,
        'name' => 'Kael Thorn',
        'lodestone_id' => '44556677',
        'verified_at' => now(),
    ]);

    $mainSlot = $activity->slots()->where('group_key', 'party-a')->firstOrFail();
    $mainSlot->update([
        'assigned_character_id' => $character->id,
        'assigned_by_user_id' => $owner->id,
    ]);

    SocialAccount::query()->create([
        'user_id' => $member->id,
        'provider' => NotificationChannel::DISCORD,
        'provider_user_id' => 'discord-manual-publish',
        'provider_name' => 'Manual Publish',
        'provider_email' => $member->email,
    ]);

    $this->actingAs($owner);

    $this->post(route('groups.dashboard.activities.publish-roster', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]))->assertRedirect();

    $event = NotificationEvent::query()->where('type', 'assignments.roster_published_assigned')->sole();

    expect($event->message_params['character'])->toBe('Kael Thorn')
        ->and(UserNotification::query()->where('user_id', $member->id)->count())->toBe(1)
        ->and(NotificationDelivery::query()->where('user_id', $member->id)->where('channel', NotificationChannel::EMAIL)->count())->toBe(1)
        ->and(NotificationDelivery::query()->where('user_id', $member->id)->where('channel', NotificationChannel::DISCORD)->count())->toBe(1);
});

it('does not create assignment notifications while the roster is still unpublished', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->public()->create([
        'owner_id' => $owner->id,
    ]);
    $activity = createAssignmentNotificationActivity($owner, $group, [
        'status' => Activity::STATUS_PLANNED,
    ]);

    $applicant = User::factory()->create([
        'assignment_notifications' => true,
    ]);
    $character = Character::factory()->primary()->create([
        'user_id' => $applicant->id,
        'name' => 'Cora Mist',
        'lodestone_id' => '33330000',
    ]);

    $application = ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $applicant->id,
        'selected_character_id' => $character->id,
        'applicant_lodestone_id' => $character->lodestone_id,
        'applicant_character_name' => $character->name,
        'applicant_world' => $character->world,
        'applicant_datacenter' => $character->datacenter,
    ]);

    $slot = $activity->slots()->where('group_key', 'party-a')->firstOrFail();

    $this->actingAs($owner);

    $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $slot->id,
    ]), [
        'application_id' => $application->id,
        'expected_slot_state_token' => activity_slot_state_token($slot->fresh()),
    ])->assertOk();

    expect(NotificationEvent::query()->where('category', NotificationCategory::ASSIGNMENTS)->count())->toBe(0);
    expect(UserNotification::query()->count())->toBe(0);
});

it('notifies affected users when assignments change after the roster has been published', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $group = Group::factory()->public()->create([
        'owner_id' => $owner->id,
    ]);
    $activity = createAssignmentNotificationActivity($owner, $group, [
        'status' => Activity::STATUS_ASSIGNED,
    ]);

    $rosterUser = User::factory()->create([
        'assignment_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);
    $benchUser = User::factory()->create([
        'assignment_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);

    $rosterCharacter = Character::factory()->primary()->create([
        'user_id' => $rosterUser->id,
        'name' => 'Dawn Serin',
        'lodestone_id' => '44440000',
    ]);
    $benchCharacter = Character::factory()->primary()->create([
        'user_id' => $benchUser->id,
        'name' => 'Eris Vale',
        'lodestone_id' => '55550000',
    ]);

    $mainSlot = $activity->slots()->where('group_key', 'party-a')->firstOrFail();
    $benchSlot = $activity->slots()->where('group_key', ActivitySlotBench::GROUP_KEY)->firstOrFail();

    $mainSlot->update([
        'assigned_character_id' => $rosterCharacter->id,
        'assigned_by_user_id' => $owner->id,
    ]);
    $benchSlot->update([
        'assigned_character_id' => $benchCharacter->id,
        'assigned_by_user_id' => $owner->id,
    ]);

    SocialAccount::query()->create([
        'user_id' => $rosterUser->id,
        'provider' => NotificationChannel::DISCORD,
        'provider_user_id' => 'discord-roster-reassign',
        'provider_name' => 'Roster User',
        'provider_email' => $rosterUser->email,
    ]);

    SocialAccount::query()->create([
        'user_id' => $benchUser->id,
        'provider' => NotificationChannel::DISCORD,
        'provider_user_id' => 'discord-bench-reassign',
        'provider_name' => 'Bench User',
        'provider_email' => $benchUser->email,
    ]);

    $rosterApplication = ActivityApplication::factory()->approved($owner)->create([
        'activity_id' => $activity->id,
        'user_id' => $rosterUser->id,
        'selected_character_id' => $rosterCharacter->id,
        'applicant_lodestone_id' => $rosterCharacter->lodestone_id,
        'applicant_character_name' => $rosterCharacter->name,
        'applicant_world' => $rosterCharacter->world,
        'applicant_datacenter' => $rosterCharacter->datacenter,
    ]);

    $benchApplication = ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $benchUser->id,
        'selected_character_id' => $benchCharacter->id,
        'status' => ActivityApplication::STATUS_ON_BENCH,
        'reviewed_by_user_id' => $owner->id,
        'reviewed_at' => now(),
        'applicant_lodestone_id' => $benchCharacter->lodestone_id,
        'applicant_character_name' => $benchCharacter->name,
        'applicant_world' => $benchCharacter->world,
        'applicant_datacenter' => $benchCharacter->datacenter,
    ]);

    $this->actingAs($owner);

    $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'application_id' => $benchApplication->id,
        'source_slot_id' => $benchSlot->id,
        'expected_slot_state_token' => activity_slot_state_token($mainSlot->fresh()),
        'expected_source_slot_state_token' => activity_slot_state_token($benchSlot->fresh()),
    ])->assertOk();

    $events = NotificationEvent::query()
        ->whereIn('type', [
            'assignments.assigned',
            'assignments.on_bench',
        ])
        ->orderBy('type')
        ->get();

    expect($events)->toHaveCount(2);

    $assignedEvent = $events->firstWhere('type', 'assignments.assigned');
    $benchEvent = $events->firstWhere('type', 'assignments.on_bench');

    expect($assignedEvent?->message_params['character'])->toBe('Eris Vale')
        ->and($assignedEvent?->message_params['slot'])->toBe('Party A 1')
        ->and($benchEvent?->message_params['character'])->toBe('Dawn Serin');

    $recipientIds = UserNotification::query()
        ->whereIn('notification_event_id', $events->pluck('id'))
        ->pluck('user_id')
        ->sort()
        ->values()
        ->all();

    expect($recipientIds)->toBe(
        collect([$rosterUser->id, $benchUser->id])->sort()->values()->all()
    );

    expect($rosterApplication->fresh()->status)->toBe(ActivityApplication::STATUS_ON_BENCH)
        ->and($benchApplication->fresh()->status)->toBe(ActivityApplication::STATUS_APPROVED);

    expect(NotificationDelivery::query()->whereIn('notification_event_id', $events->pluck('id'))->count())->toBe(4);

    Queue::assertPushed(SendNotificationEmailDeliveryJob::class, 2);
});

it('notifies affected users when published filled slots are swapped', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $group = Group::factory()->public()->create([
        'owner_id' => $owner->id,
    ]);

    $activity = createAssignmentNotificationActivity($owner, $group, [
        'status' => Activity::STATUS_ASSIGNED,
    ]);

    $secondSlot = $activity->slots()->create([
        'group_key' => 'party-a',
        'group_label' => ['en' => 'Party A'],
        'slot_key' => 'party-a-slot-2',
        'slot_label' => ['en' => 'Party A 2'],
        'position_in_group' => 2,
        'sort_order' => 2,
    ]);

    $firstUser = User::factory()->create([
        'assignment_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);
    $secondUser = User::factory()->create([
        'assignment_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);

    SocialAccount::query()->create([
        'user_id' => $firstUser->id,
        'provider' => NotificationChannel::DISCORD,
        'provider_user_id' => 'discord-swap-first',
        'provider_name' => 'First Swap User',
        'provider_email' => $firstUser->email,
    ]);

    SocialAccount::query()->create([
        'user_id' => $secondUser->id,
        'provider' => NotificationChannel::DISCORD,
        'provider_user_id' => 'discord-swap-second',
        'provider_name' => 'Second Swap User',
        'provider_email' => $secondUser->email,
    ]);

    $firstCharacter = Character::factory()->primary()->create([
        'user_id' => $firstUser->id,
        'name' => 'Lyra Dawn',
        'lodestone_id' => '88880000',
    ]);
    $secondCharacter = Character::factory()->primary()->create([
        'user_id' => $secondUser->id,
        'name' => 'Mira Vale',
        'lodestone_id' => '99990000',
    ]);

    $firstSlot = $activity->slots()->where('slot_key', 'party-a-slot-1')->firstOrFail();

    $firstSlot->update([
        'assigned_character_id' => $firstCharacter->id,
        'assigned_by_user_id' => $owner->id,
    ]);
    $secondSlot->update([
        'assigned_character_id' => $secondCharacter->id,
        'assigned_by_user_id' => $owner->id,
    ]);

    ActivityApplication::factory()->approved($owner)->create([
        'activity_id' => $activity->id,
        'user_id' => $firstUser->id,
        'selected_character_id' => $firstCharacter->id,
        'applicant_lodestone_id' => $firstCharacter->lodestone_id,
        'applicant_character_name' => $firstCharacter->name,
        'applicant_world' => $firstCharacter->world,
        'applicant_datacenter' => $firstCharacter->datacenter,
    ]);

    ActivityApplication::factory()->approved($owner)->create([
        'activity_id' => $activity->id,
        'user_id' => $secondUser->id,
        'selected_character_id' => $secondCharacter->id,
        'applicant_lodestone_id' => $secondCharacter->lodestone_id,
        'applicant_character_name' => $secondCharacter->name,
        'applicant_world' => $secondCharacter->world,
        'applicant_datacenter' => $secondCharacter->datacenter,
    ]);

    $this->actingAs($owner);

    $this->postJson(route('groups.dashboard.activities.slot-swaps.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]), [
        'source_slot_id' => $firstSlot->id,
        'target_slot_id' => $secondSlot->id,
        'expected_source_slot_state_token' => activity_slot_state_token($firstSlot->fresh()),
        'expected_target_slot_state_token' => activity_slot_state_token($secondSlot->fresh()),
    ])->assertOk();

    $events = NotificationEvent::query()
        ->where('type', 'assignments.assigned')
        ->latest('id')
        ->take(2)
        ->get()
        ->sortBy('message_params.character')
        ->values();

    expect($events)->toHaveCount(2)
        ->and($events[0]->message_params['character'])->toBe('Lyra Dawn')
        ->and($events[0]->message_params['slot'])->toBe('Party A 2')
        ->and($events[1]->message_params['character'])->toBe('Mira Vale')
        ->and($events[1]->message_params['slot'])->toBe('Party A 1');

    expect(UserNotification::query()->whereIn('notification_event_id', $events->pluck('id'))->count())->toBe(2)
        ->and(NotificationDelivery::query()->whereIn('notification_event_id', $events->pluck('id'))->count())->toBe(4);

    Queue::assertPushed(SendNotificationEmailDeliveryJob::class, 2);
});

it('notifies the applicant when a published roster assignment is returned to the queue', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $group = Group::factory()->public()->create([
        'owner_id' => $owner->id,
    ]);
    $activity = createAssignmentNotificationActivity($owner, $group, [
        'status' => Activity::STATUS_ASSIGNED,
    ]);

    $applicant = User::factory()->create([
        'assignment_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);
    $character = Character::factory()->primary()->create([
        'user_id' => $applicant->id,
        'name' => 'Faye Ember',
        'lodestone_id' => '66660000',
    ]);

    $slot = $activity->slots()->where('group_key', 'party-a')->firstOrFail();
    $slot->update([
        'assigned_character_id' => $character->id,
        'assigned_by_user_id' => $owner->id,
    ]);

    $application = ActivityApplication::factory()->approved($owner)->create([
        'activity_id' => $activity->id,
        'user_id' => $applicant->id,
        'selected_character_id' => $character->id,
        'applicant_lodestone_id' => $character->lodestone_id,
        'applicant_character_name' => $character->name,
        'applicant_world' => $character->world,
        'applicant_datacenter' => $character->datacenter,
    ]);

    SocialAccount::query()->create([
        'user_id' => $applicant->id,
        'provider' => NotificationChannel::DISCORD,
        'provider_user_id' => 'discord-returned-user',
        'provider_name' => 'Returned User',
        'provider_email' => $applicant->email,
    ]);

    $this->actingAs($owner);

    $this->postJson(route('groups.dashboard.activities.slot-unassignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $slot->id,
    ]), [
        'expected_slot_state_token' => activity_slot_state_token($slot->fresh()),
    ])->assertOk();

    $event = NotificationEvent::query()->where('type', 'assignments.returned_to_queue')->sole();
    $notification = UserNotification::query()->where('notification_event_id', $event->id)->sole();

    expect($event->category)->toBe(NotificationCategory::ASSIGNMENTS)
        ->and($event->action_url)->toBe(route('account.applications'))
        ->and($event->message_params['character'])->toBe('Faye Ember')
        ->and($notification->user_id)->toBe($applicant->id)
        ->and($application->fresh()->status)->toBe(ActivityApplication::STATUS_PENDING);

    $emailDelivery = NotificationDelivery::query()
        ->where('notification_event_id', $event->id)
        ->where('channel', NotificationChannel::EMAIL)
        ->sole();

    $discordDelivery = NotificationDelivery::query()
        ->where('notification_event_id', $event->id)
        ->where('channel', NotificationChannel::DISCORD)
        ->sole();

    expect($emailDelivery->status)->toBe(NotificationDelivery::STATUS_PENDING)
        ->and($discordDelivery->status)->toBe(NotificationDelivery::STATUS_SKIPPED)
        ->and($discordDelivery->status_reason)->toBe('discord_transport_unavailable');

    Queue::assertPushed(SendNotificationEmailDeliveryJob::class, 1);
});

it('notifies the applicant when published slot field assignments change, but not for identical re-saves', function () {
    Queue::fake();

    extract(createPublishedAssignmentFieldNotificationSetup());

    $applicant = User::factory()->create([
        'assignment_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);
    $character = Character::factory()->primary()->create([
        'user_id' => $applicant->id,
        'name' => 'Galen Frost',
        'lodestone_id' => '77770000',
    ]);

    $character->classes()->attach($tankClass->id, [
        'level' => 100,
        'is_preferred' => true,
    ]);
    $character->classes()->attach($healerClass->id, [
        'level' => 100,
        'is_preferred' => false,
    ]);
    $character->phantomJobs()->attach($phantomKnight->id, [
        'current_level' => $phantomKnight->max_level,
        'is_preferred' => true,
    ]);
    $character->phantomJobs()->attach($phantomBard->id, [
        'current_level' => $phantomBard->max_level,
        'is_preferred' => false,
    ]);

    $application = ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $applicant->id,
        'selected_character_id' => $character->id,
        'applicant_lodestone_id' => $character->lodestone_id,
        'applicant_character_name' => $character->name,
        'applicant_world' => $character->world,
        'applicant_datacenter' => $character->datacenter,
    ]);

    $application->answers()
        ->where('question_key', 'character_class')
        ->update([
            'value' => [(string) $tankClass->id, (string) $healerClass->id],
        ]);

    $application->answers()
        ->where('question_key', 'phantom_job')
        ->update([
            'value' => [(string) $phantomKnight->id, (string) $phantomBard->id],
        ]);

    SocialAccount::query()->create([
        'user_id' => $applicant->id,
        'provider' => NotificationChannel::DISCORD,
        'provider_user_id' => 'discord-field-update-user',
        'provider_name' => 'Field Update User',
        'provider_email' => $applicant->email,
    ]);

    $this->actingAs($owner);

    $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'application_id' => $application->id,
        'expected_slot_state_token' => activity_slot_state_token($mainSlot->fresh()),
        'field_values' => [
            'character_class' => (string) $tankClass->id,
            'phantom_job' => (string) $phantomKnight->id,
        ],
    ])->assertOk();

    expect(NotificationEvent::query()->where('type', 'assignments.assigned')->count())->toBe(1);

    $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'application_id' => $application->id,
        'expected_slot_state_token' => activity_slot_state_token($mainSlot->fresh()),
        'field_values' => [
            'character_class' => (string) $healerClass->id,
            'phantom_job' => (string) $phantomBard->id,
        ],
    ])->assertOk();

    expect(NotificationEvent::query()->where('type', 'assignments.assigned')->count())->toBe(2);

    $latestEvent = NotificationEvent::query()
        ->where('type', 'assignments.assigned')
        ->latest('id')
        ->first();

    expect($latestEvent)->not->toBeNull()
        ->and($latestEvent?->message_params['character'])->toBe('Galen Frost')
        ->and($latestEvent?->message_params['slot'])->toBe('Party A 1');

    $this->postJson(route('groups.dashboard.activities.slot-assignments.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'slot' => $mainSlot->id,
    ]), [
        'application_id' => $application->id,
        'expected_slot_state_token' => activity_slot_state_token($mainSlot->fresh()),
        'field_values' => [
            'character_class' => (string) $healerClass->id,
            'phantom_job' => (string) $phantomBard->id,
        ],
    ])->assertOk();

    expect(NotificationEvent::query()->where('type', 'assignments.assigned')->count())->toBe(2)
        ->and(UserNotification::query()->where('user_id', $applicant->id)->count())->toBe(2)
        ->and(NotificationDelivery::query()->where('user_id', $applicant->id)->where('channel', NotificationChannel::EMAIL)->count())->toBe(2)
        ->and(NotificationDelivery::query()->where('user_id', $applicant->id)->where('channel', NotificationChannel::DISCORD)->count())->toBe(2);

    Queue::assertPushed(SendNotificationEmailDeliveryJob::class, 2);
});
