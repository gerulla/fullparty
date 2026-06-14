<?php

use App\Jobs\SendNotificationEmailDeliveryJob;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlotAssignment;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\DiscordGuildIntegration;
use App\Models\DiscordUserIntegration;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\IntegrationClient;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Input\TextInputSanitizer;
use App\Support\Notifications\NotificationCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function createRunNotificationDiscordIntegration(User $user, string $discordUserId, string $username): DiscordUserIntegration
{
    return DiscordUserIntegration::query()->create([
        'user_id' => $user->id,
        'discord_user_id' => $discordUserId,
        'username' => $username,
        'user_app_installed_at' => now(),
    ]);
}

function createRunNotificationActivity(User $owner, Group $group, array $overrides = []): Activity
{
    $type = ActivityType::factory()->create([
        'created_by_user_id' => $owner->id,
    ]);

    $version = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $type->id,
        'published_by_user_id' => $owner->id,
        'application_schema' => [],
        'slot_schema' => [],
        'layout_schema' => [
            'groups' => [],
        ],
    ]);

    $type->update([
        'current_published_version_id' => $version->id,
    ]);

    return Activity::factory()->create(array_merge([
        'group_id' => $group->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_ASSIGNED,
        'title' => 'Late Night Prog',
        'needs_application' => true,
        'allow_guest_applications' => true,
        'is_public' => true,
    ], $overrides));
}

it('notifies signed in active applicants when a run is cancelled', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $activity = createRunNotificationActivity($owner, $group, [
        'status' => Activity::STATUS_ASSIGNED,
    ]);

    $pendingUser = User::factory()->create([
        'run_and_reminder_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);
    $approvedUser = User::factory()->create([
        'run_and_reminder_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);
    $manualUser = User::factory()->create([
        'run_and_reminder_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);

    $pendingCharacter = Character::factory()->primary()->create([
        'user_id' => $pendingUser->id,
        'name' => 'Pace Dawn',
        'lodestone_id' => '10101010',
    ]);
    $approvedCharacter = Character::factory()->primary()->create([
        'user_id' => $approvedUser->id,
        'name' => 'Rho Vale',
        'lodestone_id' => '20202020',
    ]);
    $manualCharacter = Character::factory()->primary()->create([
        'user_id' => $manualUser->id,
        'name' => 'Nia Manual',
        'lodestone_id' => '21212121',
    ]);

    createRunNotificationDiscordIntegration($pendingUser, 'discord-pending-run', 'Pending User');
    createRunNotificationDiscordIntegration($approvedUser, 'discord-approved-run', 'Approved User');
    createRunNotificationDiscordIntegration($manualUser, 'discord-manual-run', 'Manual User');

    ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $pendingUser->id,
        'selected_character_id' => $pendingCharacter->id,
        'status' => ActivityApplication::STATUS_PENDING,
        'applicant_lodestone_id' => $pendingCharacter->lodestone_id,
        'applicant_character_name' => $pendingCharacter->name,
    ]);

    ActivityApplication::factory()->approved($owner)->create([
        'activity_id' => $activity->id,
        'user_id' => $approvedUser->id,
        'selected_character_id' => $approvedCharacter->id,
        'applicant_lodestone_id' => $approvedCharacter->lodestone_id,
        'applicant_character_name' => $approvedCharacter->name,
    ]);

    ActivityApplication::factory()->guest()->approved($owner)->create([
        'activity_id' => $activity->id,
    ]);

    $manualSlot = $activity->slots()->create([
        'group_key' => 'party-a',
        'group_label' => ['en' => 'Party A'],
        'slot_key' => 'party-a-slot-1',
        'slot_label' => ['en' => 'Party A 1'],
        'position_in_group' => 1,
        'sort_order' => 1,
        'assigned_character_id' => $manualCharacter->id,
        'assigned_by_user_id' => $owner->id,
    ]);

    ActivitySlotAssignment::query()->create([
        'activity_id' => $activity->id,
        'group_id' => $group->id,
        'activity_slot_id' => $manualSlot->id,
        'character_id' => $manualCharacter->id,
        'application_id' => null,
        'field_values_snapshot' => [],
        'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
        'assigned_at' => now()->subHour(),
        'assigned_by_user_id' => $owner->id,
    ]);

    $this->actingAs($owner)
        ->post(route('groups.dashboard.activities.cancel', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]), [
            'reason' => "  Storm\u{200B} outage\r\nin the data center. ",
        ])
        ->assertRedirect(route('groups.dashboard.activities.show', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]));

    $event = NotificationEvent::query()->where('type', 'runs.cancelled')->sole();
    $sanitizedReason = app(TextInputSanitizer::class)->sanitizeMultiline("  Storm\u{200B} outage\r\nin the data center. ");

    expect($event->category)->toBe(NotificationCategory::RUNS_AND_REMINDERS)
        ->and($event->action_url)->toBe(route('groups.activities.overview', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]))
        ->and($event->body_key)->toBe('notifications.runs.cancelled.body_with_reason')
        ->and($event->message_params['activity'])->toBe('Late Night Prog')
        ->and($event->message_params['reason'])->toBe($sanitizedReason);

    $recipientIds = UserNotification::query()
        ->where('notification_event_id', $event->id)
        ->pluck('user_id')
        ->sort()
        ->values()
        ->all();

    expect($recipientIds)->toBe(
        collect([$pendingUser->id, $approvedUser->id, $manualUser->id])->sort()->values()->all()
    )
        ->and(NotificationDelivery::query()->where('notification_event_id', $event->id)->count())->toBe(6)
        ->and(NotificationEvent::query()->where('type', 'applications.cancelled')->doesntExist())->toBeTrue();

    expect($manualSlot->fresh()->assigned_character_id)->toBe($manualCharacter->id);
    expect(ActivitySlotAssignment::query()->where('activity_id', $activity->id)->whereNull('ended_at')->count())->toBe(0);

    Queue::assertPushed(SendNotificationEmailDeliveryJob::class, 3);
});

it('notifies placed applicants when a run is completed', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $activity = createRunNotificationActivity($owner, $group, [
        'status' => Activity::STATUS_ASSIGNED,
    ]);

    $approvedUser = User::factory()->create([
        'run_and_reminder_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);
    $benchUser = User::factory()->create([
        'run_and_reminder_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);
    $pendingUser = User::factory()->create([
        'run_and_reminder_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);
    $manualUser = User::factory()->create([
        'run_and_reminder_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);

    $approvedCharacter = Character::factory()->primary()->create([
        'user_id' => $approvedUser->id,
        'name' => 'Sia Crest',
        'lodestone_id' => '30303030',
    ]);
    $benchCharacter = Character::factory()->primary()->create([
        'user_id' => $benchUser->id,
        'name' => 'Tae Sol',
        'lodestone_id' => '40404040',
    ]);
    $pendingCharacter = Character::factory()->primary()->create([
        'user_id' => $pendingUser->id,
        'name' => 'Uma Frost',
        'lodestone_id' => '50505050',
    ]);
    $manualCharacter = Character::factory()->primary()->create([
        'user_id' => $manualUser->id,
        'name' => 'Vera Slot',
        'lodestone_id' => '51515151',
    ]);

    foreach ([$approvedUser, $benchUser, $pendingUser, $manualUser] as $index => $user) {
        createRunNotificationDiscordIntegration($user, 'discord-complete-'.$index, 'Completion User '.$index);
    }

    ActivityApplication::factory()->approved($owner)->create([
        'activity_id' => $activity->id,
        'user_id' => $approvedUser->id,
        'selected_character_id' => $approvedCharacter->id,
        'applicant_lodestone_id' => $approvedCharacter->lodestone_id,
        'applicant_character_name' => $approvedCharacter->name,
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
    ]);

    ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $pendingUser->id,
        'selected_character_id' => $pendingCharacter->id,
        'status' => ActivityApplication::STATUS_PENDING,
        'applicant_lodestone_id' => $pendingCharacter->lodestone_id,
        'applicant_character_name' => $pendingCharacter->name,
    ]);

    $activity->slots()->create([
        'group_key' => 'party-a',
        'group_label' => ['en' => 'Party A'],
        'slot_key' => 'party-a-slot-1',
        'slot_label' => ['en' => 'Party A 1'],
        'position_in_group' => 1,
        'sort_order' => 1,
        'assigned_character_id' => $manualCharacter->id,
        'assigned_by_user_id' => $owner->id,
    ]);

    $this->actingAs($owner)
        ->postJson(route('groups.dashboard.activities.complete', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]), [])
        ->assertOk();

    $event = NotificationEvent::query()->where('type', 'runs.completed')->sole();

    expect($event->action_url)->toBe(route('groups.activities.overview', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    $recipientIds = UserNotification::query()
        ->where('notification_event_id', $event->id)
        ->pluck('user_id')
        ->sort()
        ->values()
        ->all();

    expect($recipientIds)->toBe(
        collect([$approvedUser->id, $benchUser->id, $manualUser->id])->sort()->values()->all()
    )
        ->and(NotificationDelivery::query()->where('notification_event_id', $event->id)->count())->toBe(6);

    Queue::assertPushed(SendNotificationEmailDeliveryJob::class, 3);
});

it('dispatches a guild discord run completed event for placed participants', function () {
    Queue::fake();
    Http::fake([
        'https://discord-bot.fullparty.test/events' => Http::response([], 204),
    ]);

    IntegrationClient::factory()->create([
        'outbound_events_url' => 'https://discord-bot.fullparty.test/events',
        'webhook_signing_secret' => 'guild-completed-secret',
        'allowed_events' => [
            IntegrationClient::EVENT_DISCORD_GUILD_RUN_COMPLETED,
        ],
    ]);

    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
        'name' => 'Completion Linked Group',
    ]);

    DiscordGuildIntegration::query()->create([
        'group_id' => $group->id,
        'discord_guild_id' => '800100200300400500',
        'name' => 'Completion Guild',
        'guild_installed_at' => now(),
    ]);

    $completedActivity = createRunNotificationActivity($owner, $group, [
        'status' => Activity::STATUS_ASSIGNED,
        'title' => 'Completed Guild Run',
    ]);

    $removeRoleUser = User::factory()->create([
        'run_and_reminder_notifications' => false,
        'email_notifications' => true,
        'discord_notifications' => false,
    ]);
    $keepRoleUser = User::factory()->create([
        'run_and_reminder_notifications' => false,
        'email_notifications' => true,
        'discord_notifications' => false,
    ]);

    createRunNotificationDiscordIntegration($removeRoleUser, 'discord-role-remove', 'Remove Role');
    createRunNotificationDiscordIntegration($keepRoleUser, 'discord-role-keep', 'Keep Role');

    $removeRoleCharacter = Character::factory()->primary()->create([
        'user_id' => $removeRoleUser->id,
        'name' => 'Role Remove',
        'world' => 'Twintania',
        'lodestone_id' => '61616161',
    ]);
    $keepRoleCharacter = Character::factory()->primary()->create([
        'user_id' => $keepRoleUser->id,
        'name' => 'Role Keep',
        'world' => 'Ragnarok',
        'lodestone_id' => '62626262',
    ]);

    foreach ([[$removeRoleUser, $removeRoleCharacter], [$keepRoleUser, $keepRoleCharacter]] as [$user, $character]) {
        ActivityApplication::factory()->approved($owner)->create([
            'activity_id' => $completedActivity->id,
            'user_id' => $user->id,
            'selected_character_id' => $character->id,
            'applicant_lodestone_id' => $character->lodestone_id,
            'applicant_character_name' => $character->name,
        ]);
    }

    $this->actingAs($owner)
        ->postJson(route('groups.dashboard.activities.complete', [
            'group' => $group->slug,
            'activity' => $completedActivity->id,
        ]), [])
        ->assertOk();

    Http::assertSent(function (HttpRequest $request) use ($completedActivity, $group): bool {
        if ($request->url() !== 'https://discord-bot.fullparty.test/events') {
            return false;
        }

        $body = $request->body();
        $timestamp = $request->header('X-FullParty-Timestamp')[0] ?? null;
        $payload = json_decode($body, true);
        $participants = collect($payload['data']['participants'] ?? [])->keyBy('discord_user_id');

        expect($payload['event'])->toBe(IntegrationClient::EVENT_DISCORD_GUILD_RUN_COMPLETED)
            ->and($payload['data']['type'])->toBe('runs.completed')
            ->and($payload['data']['run_id'])->toBe($completedActivity->id)
            ->and($payload['data']['group_id'])->toBe($group->id)
            ->and($payload['data']['discord_guild_id'])->toBe('800100200300400500')
            ->and($payload['data']['discord_user_ids'])->toBe([
                'discord-role-remove',
                'discord-role-keep',
            ])
            ->and($participants)->toHaveCount(2)
            ->and($participants->get('discord-role-remove')['primary_character'])->toBe([
                'name' => 'Role Remove',
                'world' => 'Twintania',
            ])
            ->and($participants->get('discord-role-keep')['primary_character'])->toBe([
                'name' => 'Role Keep',
                'world' => 'Ragnarok',
            ])
            ->and($payload['data']['run']['display_name'])->toBe('Completed Guild Run')
            ->and($payload['data']['run']['completed_at'])->not->toBeNull()
            ->and($payload['data']['group']['name'])->toBe('Completion Linked Group');

        return is_string($timestamp)
            && ($request->header('X-FullParty-Event')[0] ?? null) === IntegrationClient::EVENT_DISCORD_GUILD_RUN_COMPLETED
            && ($request->header('X-FullParty-Signature')[0] ?? null) === 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'guild-completed-secret');
    });

    Http::assertSentCount(1);
});

it('dispatches a guild discord run cancelled event for placed participants', function () {
    Queue::fake();
    Http::fake([
        'https://discord-bot.fullparty.test/events' => Http::response([], 204),
    ]);

    IntegrationClient::factory()->create([
        'outbound_events_url' => 'https://discord-bot.fullparty.test/events',
        'webhook_signing_secret' => 'guild-cancelled-secret',
        'allowed_events' => [
            IntegrationClient::EVENT_DISCORD_GUILD_RUN_CANCELLED,
        ],
    ]);

    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
        'name' => 'Cancellation Linked Group',
    ]);

    DiscordGuildIntegration::query()->create([
        'group_id' => $group->id,
        'discord_guild_id' => '700100200300400500',
        'name' => 'Cancellation Guild',
        'guild_installed_at' => now(),
    ]);

    $activity = createRunNotificationActivity($owner, $group, [
        'status' => Activity::STATUS_ASSIGNED,
        'title' => 'Cancelled Guild Run',
    ]);

    $pendingUser = User::factory()->create([
        'run_and_reminder_notifications' => false,
        'email_notifications' => true,
        'discord_notifications' => false,
    ]);
    $approvedUser = User::factory()->create([
        'run_and_reminder_notifications' => false,
        'email_notifications' => true,
        'discord_notifications' => false,
    ]);
    $benchUser = User::factory()->create([
        'run_and_reminder_notifications' => false,
        'email_notifications' => true,
        'discord_notifications' => false,
    ]);
    $manualUser = User::factory()->create([
        'run_and_reminder_notifications' => false,
        'email_notifications' => true,
        'discord_notifications' => false,
    ]);

    createRunNotificationDiscordIntegration($pendingUser, 'discord-cancel-pending', 'Cancel Pending');
    createRunNotificationDiscordIntegration($approvedUser, 'discord-cancel-approved', 'Cancel Approved');
    createRunNotificationDiscordIntegration($benchUser, 'discord-cancel-bench', 'Cancel Bench');
    createRunNotificationDiscordIntegration($manualUser, 'discord-cancel-manual', 'Cancel Manual');

    $pendingCharacter = Character::factory()->primary()->create([
        'user_id' => $pendingUser->id,
        'name' => 'Cancel Pending',
        'world' => 'Omega',
        'lodestone_id' => '63636363',
    ]);
    $approvedCharacter = Character::factory()->primary()->create([
        'user_id' => $approvedUser->id,
        'name' => 'Cancel Approved',
        'world' => 'Twintania',
        'lodestone_id' => '64646464',
    ]);
    $benchCharacter = Character::factory()->primary()->create([
        'user_id' => $benchUser->id,
        'name' => 'Cancel Bench',
        'world' => 'Ragnarok',
        'lodestone_id' => '65656565',
    ]);
    $manualCharacter = Character::factory()->primary()->create([
        'user_id' => $manualUser->id,
        'name' => 'Cancel Manual',
        'world' => 'Phoenix',
        'lodestone_id' => '66666666',
    ]);

    ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $pendingUser->id,
        'selected_character_id' => $pendingCharacter->id,
        'status' => ActivityApplication::STATUS_PENDING,
        'applicant_lodestone_id' => $pendingCharacter->lodestone_id,
        'applicant_character_name' => $pendingCharacter->name,
    ]);

    ActivityApplication::factory()->approved($owner)->create([
        'activity_id' => $activity->id,
        'user_id' => $approvedUser->id,
        'selected_character_id' => $approvedCharacter->id,
        'applicant_lodestone_id' => $approvedCharacter->lodestone_id,
        'applicant_character_name' => $approvedCharacter->name,
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
    ]);

    $activity->slots()->create([
        'group_key' => 'party-a',
        'group_label' => ['en' => 'Party A'],
        'slot_key' => 'party-a-slot-1',
        'slot_label' => ['en' => 'Party A 1'],
        'position_in_group' => 1,
        'sort_order' => 1,
        'assigned_character_id' => $manualCharacter->id,
        'assigned_by_user_id' => $owner->id,
    ]);

    $this->actingAs($owner)
        ->post(route('groups.dashboard.activities.cancel', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]), [
            'reason' => "  Host\u{200B} emergency\r\nreplacement needed. ",
        ])
        ->assertRedirect(route('groups.dashboard.activities.show', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]));

    $sanitizedReason = app(TextInputSanitizer::class)->sanitizeMultiline("  Host\u{200B} emergency\r\nreplacement needed. ");

    Http::assertSent(function (HttpRequest $request) use ($activity, $group, $sanitizedReason): bool {
        if ($request->url() !== 'https://discord-bot.fullparty.test/events') {
            return false;
        }

        $body = $request->body();
        $timestamp = $request->header('X-FullParty-Timestamp')[0] ?? null;
        $payload = json_decode($body, true);
        $participants = collect($payload['data']['participants'] ?? [])->keyBy('discord_user_id');

        expect($payload['event'])->toBe(IntegrationClient::EVENT_DISCORD_GUILD_RUN_CANCELLED)
            ->and($payload['data']['type'])->toBe('runs.cancelled')
            ->and($payload['data']['run_id'])->toBe($activity->id)
            ->and($payload['data']['group_id'])->toBe($group->id)
            ->and($payload['data']['discord_guild_id'])->toBe('700100200300400500')
            ->and($payload['data']['cancellation_reason'])->toBe($sanitizedReason)
            ->and($payload['data']['discord_user_ids'])->toBe([
                'discord-cancel-approved',
                'discord-cancel-bench',
                'discord-cancel-manual',
            ])
            ->and($participants)->toHaveCount(3)
            ->and($participants->has('discord-cancel-pending'))->toBeFalse()
            ->and($participants->get('discord-cancel-approved')['primary_character'])->toBe([
                'name' => 'Cancel Approved',
                'world' => 'Twintania',
            ])
            ->and($participants->get('discord-cancel-bench')['primary_character'])->toBe([
                'name' => 'Cancel Bench',
                'world' => 'Ragnarok',
            ])
            ->and($participants->get('discord-cancel-manual')['primary_character'])->toBe([
                'name' => 'Cancel Manual',
                'world' => 'Phoenix',
            ])
            ->and($payload['data']['run']['display_name'])->toBe('Cancelled Guild Run')
            ->and($payload['data']['run']['status'])->toBe(Activity::STATUS_CANCELLED)
            ->and($payload['data']['run']['cancelled_at'])->not->toBeNull()
            ->and($payload['data']['group']['name'])->toBe('Cancellation Linked Group');

        return is_string($timestamp)
            && ($request->header('X-FullParty-Event')[0] ?? null) === IntegrationClient::EVENT_DISCORD_GUILD_RUN_CANCELLED
            && ($request->header('X-FullParty-Signature')[0] ?? null) === 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'guild-cancelled-secret');
    });

    Http::assertSentCount(1);
});

it('uses the activity type name in run notifications when no custom title is set', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $activity = createRunNotificationActivity($owner, $group, [
        'status' => Activity::STATUS_ASSIGNED,
        'title' => null,
    ]);
    $activity->activityTypeVersion()->update([
        'name' => ['en' => 'Forked Tower: Blood'],
    ]);

    $approvedUser = User::factory()->create([
        'run_and_reminder_notifications' => true,
        'email_notifications' => false,
        'discord_notifications' => false,
    ]);
    $approvedCharacter = Character::factory()->primary()->create([
        'user_id' => $approvedUser->id,
        'name' => 'Type Name',
        'lodestone_id' => '62626262',
    ]);

    ActivityApplication::factory()->approved($owner)->create([
        'activity_id' => $activity->id,
        'user_id' => $approvedUser->id,
        'selected_character_id' => $approvedCharacter->id,
        'applicant_lodestone_id' => $approvedCharacter->lodestone_id,
        'applicant_character_name' => $approvedCharacter->name,
    ]);

    $this->actingAs($owner)
        ->postJson(route('groups.dashboard.activities.complete', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]), [])
        ->assertOk();

    $event = NotificationEvent::query()->where('type', 'runs.completed')->sole();

    expect($event->message_params['activity'])->toBe('Forked Tower: Blood')
        ->and($event->payload['activity_title'])->toBe('Forked Tower: Blood');
});

it('sanitizes completion progress notes before storing them', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $activity = createRunNotificationActivity($owner, $group, [
        'status' => Activity::STATUS_ASSIGNED,
    ]);
    $sanitizer = app(TextInputSanitizer::class);
    $rawNotes = " First\u{200B}\r\n clear\t tonight ";

    $this->actingAs($owner)
        ->postJson(route('groups.dashboard.activities.complete', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]), [
            'progress_notes' => $rawNotes,
        ])
        ->assertOk();

    expect($activity->fresh()->progress_notes)->toBe($sanitizer->sanitizeMultiline($rawNotes));
});

it('stores manually recorded completion progress', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);

    $type = ActivityType::factory()->create([
        'created_by_user_id' => $owner->id,
    ]);
    $version = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $type->id,
        'published_by_user_id' => $owner->id,
        'progress_schema' => [
            'milestones' => [
                ['key' => 'boss-1', 'label' => ['en' => 'Boss 1'], 'order' => 1],
                ['key' => 'boss-2', 'label' => ['en' => 'Boss 2'], 'order' => 2],
                ['key' => 'boss-3', 'label' => ['en' => 'Boss 3'], 'order' => 3],
                ['key' => 'boss-4', 'label' => ['en' => 'Boss 4'], 'order' => 4],
            ],
        ],
        'prog_points' => [
            ['key' => 'boss-1', 'label' => ['en' => 'Boss 1'], 'order' => 1],
            ['key' => 'boss-2', 'label' => ['en' => 'Boss 2'], 'order' => 2],
            ['key' => 'boss-3', 'label' => ['en' => 'Boss 3'], 'order' => 3],
            ['key' => 'boss-4', 'label' => ['en' => 'Boss 4'], 'order' => 4],
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
    ]);

    $recipient = User::factory()->create([
        'run_and_reminder_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => false,
    ]);
    $recipientCharacter = Character::factory()->primary()->create([
        'user_id' => $recipient->id,
        'name' => 'Payload Check',
        'lodestone_id' => '61616161',
    ]);

    ActivityApplication::factory()->approved($owner)->create([
        'activity_id' => $activity->id,
        'user_id' => $recipient->id,
        'selected_character_id' => $recipientCharacter->id,
        'applicant_lodestone_id' => $recipientCharacter->lodestone_id,
        'applicant_character_name' => $recipientCharacter->name,
    ]);

    $this->actingAs($owner)
        ->postJson(route('groups.dashboard.activities.complete', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]), [
            'progress_entry_mode' => 'manual',
            'furthest_progress_key' => 'boss-4',
            'milestones' => [
                ['milestone_key' => 'boss-1', 'kills' => 1, 'best_progress_percent' => 100],
                ['milestone_key' => 'boss-2', 'kills' => 1, 'best_progress_percent' => 100],
                ['milestone_key' => 'boss-3', 'kills' => 1, 'best_progress_percent' => 100],
                ['milestone_key' => 'boss-4', 'kills' => 0, 'best_progress_percent' => 50],
            ],
        ])
        ->assertOk();

    $activity->refresh()->load('progressMilestones');
    $milestones = $activity->progressMilestones->keyBy('milestone_key');
    $event = NotificationEvent::query()->where('type', 'runs.completed')->sole();

    expect($activity->status)->toBe(Activity::STATUS_COMPLETE)
        ->and($activity->progress_entry_mode)->toBe('manual')
        ->and($activity->furthest_progress_key)->toBe('boss-4')
        ->and($activity->furthest_progress_percent)->toBe('50.00')
        ->and($milestones)->toHaveCount(4)
        ->and($milestones->get('boss-1')->kills)->toBe(1)
        ->and($milestones->get('boss-1')->best_progress_percent)->toBe('100.00')
        ->and($milestones->get('boss-2')->best_progress_percent)->toBe('100.00')
        ->and($milestones->get('boss-3')->best_progress_percent)->toBe('100.00')
        ->and($milestones->get('boss-4')->best_progress_percent)->toBe('50.00')
        ->and($event->payload['completion']['progress_entry_mode'])->toBe('manual')
        ->and($event->payload['completion']['progress_recorded_by_user_id'])->toBe($owner->id)
        ->and($event->payload['completion']['furthest_progress_key'])->toBe('boss-4')
        ->and($event->payload['completion']['furthest_progress_label'])->toBe(['en' => 'Boss 4'])
        ->and($event->payload['completion']['furthest_progress_percent'])->toBe(50)
        ->and($event->payload['completion']['milestones'])->toHaveCount(4)
        ->and($event->payload['completion']['milestones'][0])->toMatchArray([
            'milestone_key' => 'boss-1',
            'milestone_label' => ['en' => 'Boss 1'],
            'kills' => 1,
            'best_progress_percent' => 100,
            'source' => 'manual',
        ]);
});

it('rejects completion progress notes that exceed the configured limit', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $activity = createRunNotificationActivity($owner, $group, [
        'status' => Activity::STATUS_ASSIGNED,
    ]);

    $this->actingAs($owner)
        ->postJson(route('groups.dashboard.activities.complete', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]), [
            'progress_notes' => str_repeat('p', Activity::PROGRESS_NOTES_MAX_LENGTH + 1),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['progress_notes']);

    expect($activity->fresh()->progress_notes)->toBeNull();
});

it('dispatches starting soon and starting now reminders only once', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);

    $soonActivity = createRunNotificationActivity($owner, $group, [
        'title' => 'Soon Run',
        'starts_at' => now()->addMinutes(45),
    ]);
    $nowActivity = createRunNotificationActivity($owner, $group, [
        'title' => 'Now Run',
        'starts_at' => now()->subMinutes(5),
    ]);

    $soonUser = User::factory()->create([
        'run_and_reminder_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);
    $nowUser = User::factory()->create([
        'run_and_reminder_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);

    $soonCharacter = Character::factory()->primary()->create([
        'user_id' => $soonUser->id,
        'name' => 'Vale Soon',
        'lodestone_id' => '60606060',
    ]);
    $nowCharacter = Character::factory()->primary()->create([
        'user_id' => $nowUser->id,
        'name' => 'Vale Now',
        'lodestone_id' => '70707070',
    ]);

    foreach ([$soonUser, $nowUser] as $index => $user) {
        createRunNotificationDiscordIntegration($user, 'discord-reminder-'.$index, 'Reminder User '.$index);
    }

    ActivityApplication::factory()->approved($owner)->create([
        'activity_id' => $soonActivity->id,
        'user_id' => $soonUser->id,
        'selected_character_id' => $soonCharacter->id,
        'applicant_lodestone_id' => $soonCharacter->lodestone_id,
        'applicant_character_name' => $soonCharacter->name,
    ]);

    ActivityApplication::factory()->approved($owner)->create([
        'activity_id' => $nowActivity->id,
        'user_id' => $nowUser->id,
        'selected_character_id' => $nowCharacter->id,
        'applicant_lodestone_id' => $nowCharacter->lodestone_id,
        'applicant_character_name' => $nowCharacter->name,
    ]);

    $this->artisan('notifications:dispatch-run-reminders')->assertExitCode(0);
    $this->artisan('notifications:dispatch-run-reminders')->assertExitCode(0);

    expect(NotificationEvent::query()->where('type', 'runs.starting_soon')->count())->toBe(1)
        ->and(NotificationEvent::query()->where('type', 'runs.starting_now')->count())->toBe(1)
        ->and(filled($soonActivity->fresh()->settings['run_notification_starting_soon_sent_at'] ?? null))->toBeTrue()
        ->and(filled($nowActivity->fresh()->settings['run_notification_starting_now_sent_at'] ?? null))->toBeTrue()
        ->and(NotificationDelivery::query()->count())->toBe(4);

    Queue::assertPushed(SendNotificationEmailDeliveryJob::class, 2);
});

it('dispatches a guild discord run reminder event for linked group runs', function () {
    Queue::fake();
    Http::fake([
        'https://discord-bot.fullparty.test/events' => Http::response([], 204),
    ]);

    IntegrationClient::factory()->create([
        'outbound_events_url' => 'https://discord-bot.fullparty.test/events',
        'webhook_signing_secret' => 'guild-reminder-secret',
        'allowed_events' => [
            IntegrationClient::EVENT_DISCORD_GUILD_RUN_STARTING_SOON,
        ],
    ]);

    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
        'name' => 'Guild Linked Group',
    ]);

    DiscordGuildIntegration::query()->create([
        'group_id' => $group->id,
        'discord_guild_id' => '900100200300400500',
        'name' => 'Raid Guild',
        'guild_installed_at' => now(),
    ]);

    $activity = createRunNotificationActivity($owner, $group, [
        'title' => 'Guild Reminder Run',
        'starts_at' => now()->addMinutes(30),
    ]);

    $firstUser = User::factory()->create([
        'run_and_reminder_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);
    $secondUser = User::factory()->create([
        'run_and_reminder_notifications' => false,
        'email_notifications' => true,
        'discord_notifications' => false,
    ]);
    $unlinkedUser = User::factory()->create([
        'run_and_reminder_notifications' => false,
        'email_notifications' => false,
        'discord_notifications' => false,
    ]);

    GroupMembership::query()->create([
        'group_id' => $group->id,
        'user_id' => $firstUser->id,
        'role' => GroupMembership::ROLE_MEMBER,
        'joined_at' => now(),
    ]);

    createRunNotificationDiscordIntegration($firstUser, 'discord-guild-first', 'First User');
    createRunNotificationDiscordIntegration($secondUser, 'discord-guild-second', 'Second User');

    $characters = [];

    foreach ([$firstUser, $secondUser, $unlinkedUser] as $index => $user) {
        $character = Character::factory()->primary()->create([
            'user_id' => $user->id,
            'name' => 'Guild Member '.$index,
            'world' => $index === 0 ? 'Twintania' : 'Ragnarok',
            'datacenter' => $index === 0 ? 'Light' : 'Chaos',
            'avatar_url' => null,
            'lodestone_id' => '8080808'.$index,
        ]);
        $characters[$index] = $character;

        ActivityApplication::factory()->approved($owner)->create([
            'activity_id' => $activity->id,
            'user_id' => $user->id,
            'selected_character_id' => $character->id,
            'applicant_lodestone_id' => $character->lodestone_id,
            'applicant_character_name' => $character->name,
        ]);
    }

    $this->artisan('notifications:dispatch-run-reminders')->assertExitCode(0);

    Http::assertSent(function (HttpRequest $request) use ($activity, $characters, $firstUser, $group, $secondUser, $unlinkedUser): bool {
        if ($request->url() !== 'https://discord-bot.fullparty.test/events') {
            return false;
        }

        $body = $request->body();
        $timestamp = $request->header('X-FullParty-Timestamp')[0] ?? null;
        $payload = json_decode($body, true);

        expect($payload['event'])->toBe(IntegrationClient::EVENT_DISCORD_GUILD_RUN_REMINDER)
            ->and($payload['data']['type'])->toBe('runs.starting_soon')
            ->and($payload['data']['reminder_type'])->toBe('starting_soon')
            ->and($payload['data']['run_id'])->toBe($activity->id)
            ->and($payload['data']['activity_id'])->toBe($activity->id)
            ->and($payload['data']['group_id'])->toBe($group->id)
            ->and($payload['data']['group_slug'])->toBe($group->slug)
            ->and($payload['data']['discord_guild_id'])->toBe('900100200300400500')
            ->and($payload['data']['discord_user_ids'])->toBe([
                'discord-guild-first',
                'discord-guild-second',
            ])
            ->and($payload['data']['participants'])->toBe([
                [
                    'user_id' => $firstUser->id,
                    'discord_user_id' => 'discord-guild-first',
                    'is_discord_linked' => true,
                    'is_group_member' => true,
                    'group_role' => GroupMembership::ROLE_MEMBER,
                    'source' => 'application',
                    'primary_character' => [
                        'name' => 'Guild Member 0',
                        'world' => 'Twintania',
                    ],
                    'character' => [
                        'id' => $characters[0]->id,
                        'name' => 'Guild Member 0',
                        'world' => 'Twintania',
                        'datacenter' => 'Light',
                        'avatar_url' => null,
                    ],
                ],
                [
                    'user_id' => $secondUser->id,
                    'discord_user_id' => 'discord-guild-second',
                    'is_discord_linked' => true,
                    'is_group_member' => true,
                    'group_role' => GroupMembership::ROLE_MEMBER,
                    'source' => 'application',
                    'primary_character' => [
                        'name' => 'Guild Member 1',
                        'world' => 'Ragnarok',
                    ],
                    'character' => [
                        'id' => $characters[1]->id,
                        'name' => 'Guild Member 1',
                        'world' => 'Ragnarok',
                        'datacenter' => 'Chaos',
                        'avatar_url' => null,
                    ],
                ],
            ])
            ->and($payload['data']['unlinked_participants'])->toBe([
                [
                    'user_id' => $unlinkedUser->id,
                    'discord_user_id' => null,
                    'is_discord_linked' => false,
                    'is_group_member' => true,
                    'group_role' => GroupMembership::ROLE_MEMBER,
                    'source' => 'application',
                    'primary_character' => [
                        'name' => 'Guild Member 2',
                        'world' => 'Ragnarok',
                    ],
                    'character' => [
                        'id' => $characters[2]->id,
                        'name' => 'Guild Member 2',
                        'world' => 'Ragnarok',
                        'datacenter' => 'Chaos',
                        'avatar_url' => null,
                    ],
                ],
            ])
            ->and($payload['data']['unlinked_count'])->toBe(1)
            ->and($payload['data']['total_placed_count'])->toBe(3)
            ->and($payload['data']['run']['display_name'])->toBe('Guild Reminder Run')
            ->and($payload['data']['group']['name'])->toBe('Guild Linked Group')
            ->and($payload['data']['discord_guild']['name'])->toBe('Raid Guild');

        return is_string($timestamp)
            && ($request->header('X-FullParty-Event')[0] ?? null) === IntegrationClient::EVENT_DISCORD_GUILD_RUN_REMINDER
            && ($request->header('X-FullParty-Signature')[0] ?? null) === 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'guild-reminder-secret');
    });

    Http::assertSentCount(1);
});
