<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Notifications\NotificationCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createApplicationNotificationActivity(User $owner, Group $group, array $activityOverrides = []): Activity
{
    $type = ActivityType::factory()->create([
        'created_by_user_id' => $owner->id,
    ]);

    $version = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $type->id,
        'published_by_user_id' => $owner->id,
        'application_schema' => [
            [
                'key' => 'experience',
                'label' => ['en' => 'Experience'],
                'type' => 'textarea',
                'required' => true,
            ],
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
        'status' => Activity::STATUS_PLANNED,
        'needs_application' => true,
        'allow_guest_applications' => true,
        'is_public' => true,
    ], $activityOverrides));
}

it('notifies eligible moderators when an authenticated user submits an application', function () {
    $owner = User::factory()->create([
        'application_notifications' => true,
    ]);
    $group = Group::factory()->public()->create([
        'owner_id' => $owner->id,
    ]);
    $optedInModerator = User::factory()->create([
        'application_notifications' => true,
    ]);
    $optedOutModerator = User::factory()->create([
        'application_notifications' => false,
    ]);
    $applicant = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $applicant->id,
        'name' => 'Ciela Dawn',
        'lodestone_id' => '11112222',
        'world' => 'Twintania',
        'datacenter' => 'Light',
    ]);

    $group->memberships()->createMany([
        [
            'user_id' => $optedInModerator->id,
            'role' => GroupMembership::ROLE_MODERATOR,
            'joined_at' => now(),
        ],
        [
            'user_id' => $optedOutModerator->id,
            'role' => GroupMembership::ROLE_MODERATOR,
            'joined_at' => now(),
        ],
    ]);

    $activity = createApplicationNotificationActivity($owner, $group, [
        'allow_guest_applications' => false,
    ]);

    $this->actingAs($applicant);

    $this->post(route('groups.activities.application.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]), [
        'selected_character_id' => $character->id,
        'answers' => [
            'experience' => 'Ready to prog.',
        ],
    ])->assertRedirect(route('groups.activities.application.confirmation', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    $event = NotificationEvent::query()->where('type', 'applications.submitted')->sole();

    expect($event->category)->toBe(NotificationCategory::APPLICATIONS)
        ->and($event->title_key)->toBe('notifications.applications.submitted.title')
        ->and($event->body_key)->toBe('notifications.applications.submitted.body')
        ->and($event->action_url)->toBe(route('groups.dashboard.activities.show', [
            'group' => $group,
            'activity' => $activity,
        ]))
        ->and($event->message_params['activity'])->toBe($activity->title)
        ->and($event->message_params['character'])->toBe('Ciela Dawn');

    $recipientIds = UserNotification::query()
        ->where('notification_event_id', $event->id)
        ->pluck('user_id')
        ->sort()
        ->values()
        ->all();

    expect($recipientIds)->toBe(
        collect([$optedInModerator->id, $owner->id])->sort()->values()->all()
    );
});

it('notifies eligible moderators when an application is updated', function () {
    $owner = User::factory()->create([
        'application_notifications' => true,
    ]);
    $group = Group::factory()->public()->create([
        'owner_id' => $owner->id,
    ]);
    $activity = createApplicationNotificationActivity($owner, $group, [
        'allow_guest_applications' => false,
    ]);
    $applicant = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $applicant->id,
        'name' => 'Nova Vale',
        'lodestone_id' => '33334444',
    ]);

    $application = ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $applicant->id,
        'selected_character_id' => $character->id,
        'status' => ActivityApplication::STATUS_PENDING,
        'applicant_lodestone_id' => $character->lodestone_id,
        'applicant_character_name' => $character->name,
        'applicant_world' => $character->world,
        'applicant_datacenter' => $character->datacenter,
    ]);

    $this->actingAs($applicant);

    $this->put(route('groups.activities.application.update', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]), [
        'selected_character_id' => $character->id,
        'notes' => 'Updated notes.',
        'answers' => [
            'experience' => 'Reached enrage.',
        ],
    ])->assertRedirect(route('groups.activities.application.confirmation', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    $event = NotificationEvent::query()->where('type', 'applications.updated')->sole();

    expect($event->message_params['character'])->toBe('Nova Vale')
        ->and($event->action_url)->toBe(route('groups.dashboard.activities.show', [
            'group' => $group,
            'activity' => $activity,
        ]));

    expect(UserNotification::query()->where('notification_event_id', $event->id)->pluck('user_id')->all())
        ->toBe([$owner->id]);
});

it('notifies eligible moderators when an application is withdrawn', function () {
    $owner = User::factory()->create([
        'application_notifications' => true,
    ]);
    $group = Group::factory()->public()->create([
        'owner_id' => $owner->id,
    ]);
    $activity = createApplicationNotificationActivity($owner, $group, [
        'allow_guest_applications' => false,
    ]);
    $applicant = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $applicant->id,
        'name' => 'Iris Sol',
        'lodestone_id' => '55556666',
    ]);

    $application = ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $applicant->id,
        'selected_character_id' => $character->id,
        'status' => ActivityApplication::STATUS_PENDING,
        'applicant_lodestone_id' => $character->lodestone_id,
        'applicant_character_name' => $character->name,
        'applicant_world' => $character->world,
        'applicant_datacenter' => $character->datacenter,
    ]);

    $this->actingAs($applicant);

    $this->delete(route('account.applications.destroy', [
        'application' => $application->id,
    ]))->assertRedirect(route('account.applications'));

    $event = NotificationEvent::query()->where('type', 'applications.withdrawn')->sole();

    expect($event->message_params['character'])->toBe('Iris Sol');
    expect(UserNotification::query()->where('notification_event_id', $event->id)->pluck('user_id')->all())
        ->toBe([$owner->id]);
});

it('notifies a signed in applicant when their application is declined', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->public()->create([
        'owner_id' => $owner->id,
    ]);
    $activity = createApplicationNotificationActivity($owner, $group, [
        'allow_guest_applications' => false,
    ]);
    $applicant = User::factory()->create([
        'application_notifications' => true,
    ]);
    $character = Character::factory()->primary()->create([
        'user_id' => $applicant->id,
        'name' => 'Luna Crest',
        'lodestone_id' => '77778888',
    ]);

    $application = ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $applicant->id,
        'selected_character_id' => $character->id,
        'status' => ActivityApplication::STATUS_PENDING,
        'applicant_lodestone_id' => $character->lodestone_id,
        'applicant_character_name' => $character->name,
        'applicant_world' => $character->world,
        'applicant_datacenter' => $character->datacenter,
    ]);

    $this->actingAs($owner);

    $this->postJson(route('groups.dashboard.activities.application-declines.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'application' => $application->id,
    ]), [
        'reason' => 'Roster is already full.',
    ])->assertOk();

    $event = NotificationEvent::query()->where('type', 'applications.declined')->sole();

    expect($event->body_key)->toBe('notifications.applications.declined.body_with_reason')
        ->and($event->action_url)->toBe(route('account.applications'))
        ->and($event->message_params['reason'])->toBe('Roster is already full.');

    $notification = UserNotification::query()->where('notification_event_id', $event->id)->sole();

    expect($notification->user_id)->toBe($applicant->id);
});

it('does not create a decline notification when the declined application belongs to a guest', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->public()->create([
        'owner_id' => $owner->id,
    ]);
    $activity = createApplicationNotificationActivity($owner, $group);

    $application = ActivityApplication::factory()->guest()->create([
        'activity_id' => $activity->id,
        'status' => ActivityApplication::STATUS_PENDING,
    ]);

    $this->actingAs($owner);

    $this->postJson(route('groups.dashboard.activities.application-declines.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'application' => $application->id,
    ]), [
        'reason' => 'Roster is already full.',
    ])->assertOk();

    expect(NotificationEvent::query()->where('type', 'applications.declined')->count())->toBe(0);
    expect(UserNotification::query()->count())->toBe(0);
});

it('notifies signed in applicants when their applications are cancelled with the run', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->public()->create([
        'owner_id' => $owner->id,
    ]);
    $activity = createApplicationNotificationActivity($owner, $group, [
        'allow_guest_applications' => true,
    ]);

    $signedInApplicant = User::factory()->create([
        'application_notifications' => true,
    ]);
    $signedInCharacter = Character::factory()->primary()->create([
        'user_id' => $signedInApplicant->id,
        'name' => 'Rin Vale',
        'lodestone_id' => '99990000',
    ]);

    ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $signedInApplicant->id,
        'selected_character_id' => $signedInCharacter->id,
        'status' => ActivityApplication::STATUS_PENDING,
        'applicant_lodestone_id' => $signedInCharacter->lodestone_id,
        'applicant_character_name' => $signedInCharacter->name,
        'applicant_world' => $signedInCharacter->world,
        'applicant_datacenter' => $signedInCharacter->datacenter,
    ]);

    ActivityApplication::factory()->guest()->approved($owner)->create([
        'activity_id' => $activity->id,
    ]);

    $this->actingAs($owner);

    $this->post(route('groups.dashboard.activities.cancel', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]))->assertRedirect(route('groups.dashboard.activities.show', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    $event = NotificationEvent::query()->where('type', 'applications.cancelled')->sole();

    expect($event->title_key)->toBe('notifications.applications.cancelled.title')
        ->and($event->action_url)->toBe(route('account.applications'))
        ->and($event->message_params['character'])->toBe('Rin Vale');

    $notification = UserNotification::query()->where('notification_event_id', $event->id)->sole();

    expect($notification->user_id)->toBe($signedInApplicant->id);
});
