<?php

use App\Jobs\SendNotificationEmailDeliveryJob;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\Group;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\NotificationChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

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
    $group = Group::factory()->public()->create([
        'owner_id' => $owner->id,
    ]);
    $activity = createRunNotificationActivity($owner, $group, [
        'status' => Activity::STATUS_PLANNED,
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

    SocialAccount::query()->create([
        'user_id' => $pendingUser->id,
        'provider' => NotificationChannel::DISCORD,
        'provider_user_id' => 'discord-pending-run',
        'provider_name' => 'Pending User',
        'provider_email' => $pendingUser->email,
    ]);

    SocialAccount::query()->create([
        'user_id' => $approvedUser->id,
        'provider' => NotificationChannel::DISCORD,
        'provider_user_id' => 'discord-approved-run',
        'provider_name' => 'Approved User',
        'provider_email' => $approvedUser->email,
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

    ActivityApplication::factory()->guest()->approved($owner)->create([
        'activity_id' => $activity->id,
    ]);

    $this->actingAs($owner)
        ->post(route('groups.dashboard.activities.cancel', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]))
        ->assertRedirect(route('groups.dashboard.activities.show', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]));

    $event = NotificationEvent::query()->where('type', 'runs.cancelled')->sole();

    expect($event->category)->toBe(NotificationCategory::RUNS_AND_REMINDERS)
        ->and($event->action_url)->toBe(route('account.applications'))
        ->and($event->message_params['activity'])->toBe('Late Night Prog');

    $recipientIds = UserNotification::query()
        ->where('notification_event_id', $event->id)
        ->pluck('user_id')
        ->sort()
        ->values()
        ->all();

    expect($recipientIds)->toBe(
        collect([$pendingUser->id, $approvedUser->id])->sort()->values()->all()
    )
        ->and(NotificationDelivery::query()->where('notification_event_id', $event->id)->count())->toBe(4)
        ->and(NotificationEvent::query()->where('type', 'applications.cancelled')->doesntExist())->toBeTrue();

    Queue::assertPushed(SendNotificationEmailDeliveryJob::class, 2);
});

it('notifies placed applicants when a run is completed', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $group = Group::factory()->public()->create([
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

    foreach ([$approvedUser, $benchUser, $pendingUser] as $index => $user) {
        SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => NotificationChannel::DISCORD,
            'provider_user_id' => 'discord-complete-'.$index,
            'provider_name' => 'Completion User '.$index,
            'provider_email' => $user->email,
        ]);
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

    $this->actingAs($owner)
        ->postJson(route('groups.dashboard.activities.complete', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]), [])
        ->assertOk();

    $event = NotificationEvent::query()->where('type', 'runs.completed')->sole();

    $recipientIds = UserNotification::query()
        ->where('notification_event_id', $event->id)
        ->pluck('user_id')
        ->sort()
        ->values()
        ->all();

    expect($recipientIds)->toBe(
        collect([$approvedUser->id, $benchUser->id])->sort()->values()->all()
    )
        ->and(NotificationDelivery::query()->where('notification_event_id', $event->id)->count())->toBe(4);

    Queue::assertPushed(SendNotificationEmailDeliveryJob::class, 2);
});

it('dispatches starting soon and starting now reminders only once', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $group = Group::factory()->public()->create([
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
        SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => NotificationChannel::DISCORD,
            'provider_user_id' => 'discord-reminder-'.$index,
            'provider_name' => 'Reminder User '.$index,
            'provider_email' => $user->email,
        ]);
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
