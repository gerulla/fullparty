<?php

use App\Jobs\SendNotificationEmailDeliveryJob;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\DiscordUserIntegration;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\IntegrationClient;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\ApplicationNotificationService;
use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\NotificationChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\OpenApiContract;

uses(RefreshDatabase::class);

it('sends ready-to-use character, group and published activity banner images in application Discord payloads', function (string $method, string $status, string $type) {
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake(['https://bot.example.test/events' => Http::response([], 204)]);
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create(['owner_id' => $owner->id, 'profile_picture_url' => Storage::disk('public')->url('groups/avatar.webp')]);
    $activity = createApplicationNotificationActivity($owner, $group);
    $activity->activityTypeVersion->update(['banner_image_url' => Storage::disk('public')->url('activity-types/published.webp')]);
    $activity->activityType->update(['draft_banner_image_url' => Storage::disk('public')->url('activity-types/unpublished.webp')]);
    $applicant = User::factory()->create(['application_notifications' => true, 'discord_notifications' => true, 'email_notifications' => false]);
    $character = Character::factory()->create(['user_id' => $applicant->id, 'avatar_url' => 'https://img2.finalfantasyxiv.com/f/character-avatar.jpg']);
    Character::factory()->primary()->create(['user_id' => $applicant->id, 'avatar_url' => 'https://img2.finalfantasyxiv.com/f/other-character.jpg']);
    createApplicationDiscordIntegration($applicant, '234567890123456789', 'Example');
    IntegrationClient::factory()->create(['outbound_events_url' => 'https://bot.example.test/events', 'allowed_events' => [IntegrationClient::EVENT_DISCORD_NOTIFICATION_DELIVERY]]);
    $application = ActivityApplication::factory()->create(['activity_id' => $activity->id, 'user_id' => $applicant->id, 'selected_character_id' => $character->id, 'applicant_lodestone_id' => $character->lodestone_id, 'applicant_avatar_url' => 'https://img2.finalfantasyxiv.com/f/old-avatar.jpg', 'status' => $status, 'review_reason' => 'Roster is already full.']);

    app(ApplicationNotificationService::class)->{$method}($application, $owner);

    Http::assertSent(function ($request) use ($type, $activity, $group, $character, $applicant) {
        $body = $request->data();
        expect($body['event'])->toBe('discord.notification.delivery')
            ->and($body['data']['notification']['type'])->toBe($type);
        $payload = $body['data']['notification']['payload'];
        expect($payload['character_image_url'])->toBe('https://img2.finalfantasyxiv.com/f/character-avatar.jpg');
        expect($payload)->toMatchArray([
            'run_title' => $activity->title,
            'group_name' => $group->name,
            'character_world' => $character->world,
            'character_avatar_url' => $payload['character_image_url'],
            'group_icon_url' => $payload['group_profile_image_url'],
            'banner_image_url' => $payload['activity_banner_image_url'],
            'applicant_name' => $applicant->name,
            'applicant_profile_url' => null,
        ]);
        expect($payload['run_url'])->toContain('/groups/'.$group->slug.'/activities/'.$activity->id)
            ->and($payload['application_url'])->toContain('/account/applications')
            ->and($payload['starts_at'])->toBe($activity->starts_at->toIso8601String());
        parse_str(parse_url($payload['group_profile_image_url'], PHP_URL_QUERY), $profile);
        parse_str(parse_url($payload['activity_banner_image_url'], PHP_URL_QUERY), $banner);
        expect($profile)->toMatchArray(['path' => 'groups/avatar.webp', 'width' => '256', 'height' => '256', 'fit' => 'contain'])
            ->and($banner)->toMatchArray(['path' => 'activity-types/published.webp', 'width' => '1200', 'height' => '400', 'fit' => 'crop', 'position' => 'center', 'upscale' => '1']);
        $document = json_decode(file_get_contents(resource_path('openapi/fullparty.json')), true, flags: JSON_THROW_ON_ERROR);
        OpenApiContract::assertMatches(json_decode($request->body()), $document['webhooks']['discord.notification.delivery']['post']['requestBody']['content']['application/json']['schema'], $document);

        return true;
    });
    Http::assertSentCount(1);
})->with([
    ['notifyDeclined', ActivityApplication::STATUS_DECLINED, 'applications.declined'],
    ['notifySubmitted', ActivityApplication::STATUS_PENDING, 'applications.submitted'],
    ['notifyCancelled', ActivityApplication::STATUS_CANCELLED, 'applications.cancelled'],
]);

it('keeps application notifications valid when images are missing or external', function () {
    Queue::fake();
    Http::preventStrayRequests();
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create(['owner_id' => $owner->id, 'profile_picture_url' => null]);
    $activity = createApplicationNotificationActivity($owner, $group);
    $activity->activityTypeVersion->update(['banner_image_url' => 'https://external.example/banner.webp']);
    $applicant = User::factory()->create(['application_notifications' => true, 'discord_notifications' => false, 'email_notifications' => false]);
    $application = ActivityApplication::factory()->create(['activity_id' => $activity->id, 'user_id' => $applicant->id, 'status' => ActivityApplication::STATUS_DECLINED]);
    app(ApplicationNotificationService::class)->notifyDeclined($application, $owner);
    $event = NotificationEvent::query()->where('type', 'applications.declined')->sole();
    expect($event->payload['group_profile_image_url'])->toBeNull()->and($event->payload['activity_banner_image_url'])->toBeNull();
    Http::assertNothingSent();
});

it('falls back to the application character picture or null without fetching external images', function (?string $snapshot, bool $hasCharacter) {
    Queue::fake();
    Http::preventStrayRequests();
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create(['owner_id' => $owner->id]);
    $activity = createApplicationNotificationActivity($owner, $group);
    $applicant = User::factory()->create(['application_notifications' => true, 'discord_notifications' => false, 'email_notifications' => false]);
    $character = $hasCharacter ? Character::factory()->create(['user_id' => $applicant->id, 'avatar_url' => null]) : null;
    $application = ActivityApplication::factory()->create([
        'activity_id' => $activity->id, 'user_id' => $applicant->id,
        'selected_character_id' => $character?->id, 'applicant_avatar_url' => $snapshot,
        'status' => ActivityApplication::STATUS_DECLINED,
    ]);
    // The factory normally fills both character and snapshot; model a missing/deleted character explicitly.
    $application->update(['selected_character_id' => $character?->id, 'applicant_avatar_url' => $snapshot]);
    $application->refresh();

    app(ApplicationNotificationService::class)->notifyDeclined($application, $owner);

    expect(NotificationEvent::query()->where('type', 'applications.declined')->sole()->payload['character_image_url'])->toBe($snapshot);
    Http::assertNothingSent();
})->with([
    ['https://img2.finalfantasyxiv.com/f/saved-avatar.jpg', true],
    ['https://img2.finalfantasyxiv.com/f/saved-avatar.jpg', false],
    [null, true],
    [null, false],
]);

function createApplicationDiscordIntegration(User $user, string $discordUserId, string $username): DiscordUserIntegration
{
    return DiscordUserIntegration::query()->create([
        'user_id' => $user->id,
        'discord_user_id' => $discordUserId,
        'username' => $username,
        'user_app_installed_at' => now(),
    ]);
}

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
        'status' => Activity::STATUS_SCHEDULED,
        'needs_application' => true,
        'allow_guest_applications' => true,
        'is_public' => true,
    ], $activityOverrides));
}

it('notifies the run host and applicant when an authenticated user submits an application', function () {
    Queue::fake();

    $owner = User::factory()->create([
        'application_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $optedInModerator = User::factory()->create([
        'application_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);
    $admin = User::factory()->create([
        'application_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);
    $optedOutModerator = User::factory()->create([
        'application_notifications' => false,
    ]);
    $applicant = User::factory()->create([
        'application_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);
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
            'user_id' => $admin->id,
            'role' => GroupMembership::ROLE_ADMIN,
            'joined_at' => now(),
        ],
        [
            'user_id' => $optedOutModerator->id,
            'role' => GroupMembership::ROLE_MODERATOR,
            'joined_at' => now(),
        ],
    ]);
    createApplicationDiscordIntegration($owner, 'discord-application-owner', 'Application Owner');
    createApplicationDiscordIntegration($applicant, 'discord-application-applicant', 'Application Applicant');

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

    $event = NotificationEvent::query()->where('type', 'applications.new_for_review')->sole();
    $submittedEvent = NotificationEvent::query()->where('type', 'applications.submitted')->sole();

    expect($event->category)->toBe(NotificationCategory::APPLICATIONS)
        ->and($event->title_key)->toBe('notifications.applications.new_for_review.title')
        ->and($event->body_key)->toBe('notifications.applications.new_for_review.body')
        ->and($event->action_url)->toBe(route('groups.dashboard.activities.show', [
            'group' => $group,
            'activity' => $activity,
        ]))
        ->and($event->message_params['activity'])->toBe($activity->title)
        ->and($event->message_params['character'])->toBe('Ciela Dawn')
        ->and($event->message_params['count'])->toBe(1);

    $notifications = UserNotification::query()
        ->where('notification_event_id', $event->id)
        ->orderBy('user_id')
        ->get();

    $recipientIds = $notifications
        ->pluck('user_id')
        ->sort()
        ->values()
        ->all();

    expect($recipientIds)->toBe(
        [$owner->id]
    )
        ->and($notifications->pluck('aggregate_count')->unique()->values()->all())->toBe([1])
        ->and($notifications->pluck('aggregate_key')->unique()->values()->all())->toBe([
            sprintf('applications.new_for_review.activity.%d', $activity->id),
        ])
        ->and(NotificationDelivery::query()->where('notification_event_id', $event->id)->count())->toBe(0)
        ->and(UserNotification::query()->where('notification_event_id', $submittedEvent->id)->pluck('user_id')->all())->toBe([$applicant->id])
        ->and(NotificationDelivery::query()->where('notification_event_id', $submittedEvent->id)->count())->toBe(2)
        ->and(NotificationDelivery::query()->where('notification_event_id', $submittedEvent->id)->where('channel', NotificationChannel::EMAIL)->where('status', NotificationDelivery::STATUS_PENDING)->count())->toBe(1)
        ->and(NotificationDelivery::query()->where('notification_event_id', $submittedEvent->id)->where('channel', NotificationChannel::DISCORD)->where('status', NotificationDelivery::STATUS_SKIPPED)->count())->toBe(1);

    Queue::assertPushed(SendNotificationEmailDeliveryJob::class, 1);
});

it('aggregates moderator new-application notifications per activity until they are read', function () {
    $owner = User::factory()->create([
        'application_notifications' => true,
    ]);
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $activity = createApplicationNotificationActivity($owner, $group, [
        'allow_guest_applications' => false,
    ]);

    $firstApplicant = User::factory()->create([
        'application_notifications' => false,
    ]);
    $secondApplicant = User::factory()->create([
        'application_notifications' => false,
    ]);
    $firstCharacter = Character::factory()->primary()->create([
        'user_id' => $firstApplicant->id,
        'name' => 'Tala Crest',
        'lodestone_id' => '12121212',
    ]);
    $secondCharacter = Character::factory()->primary()->create([
        'user_id' => $secondApplicant->id,
        'name' => 'Veya Sol',
        'lodestone_id' => '34343434',
    ]);

    $this->actingAs($firstApplicant);

    $this->post(route('groups.activities.application.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]), [
        'selected_character_id' => $firstCharacter->id,
        'answers' => [
            'experience' => 'Fresh applicant.',
        ],
    ])->assertRedirect(route('groups.activities.application.confirmation', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    $this->actingAs($secondApplicant);

    $this->post(route('groups.activities.application.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]), [
        'selected_character_id' => $secondCharacter->id,
        'answers' => [
            'experience' => 'Second applicant.',
        ],
    ])->assertRedirect(route('groups.activities.application.confirmation', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    expect(NotificationEvent::query()->where('type', 'applications.new_for_review')->count())->toBe(2)
        ->and(UserNotification::query()->count())->toBe(1);

    $aggregateNotification = UserNotification::query()->sole();
    $latestEvent = NotificationEvent::query()->latest('id')->firstOrFail();

    expect($aggregateNotification->notification_event_id)->toBe($latestEvent->id)
        ->and($aggregateNotification->aggregate_count)->toBe(2)
        ->and($aggregateNotification->read_at)->toBeNull()
        ->and($latestEvent->message_params['count'])->toBe(2);

    $this->actingAs($owner)
        ->post(route('account.notifications.read-all'))
        ->assertRedirect();

    $thirdApplicant = User::factory()->create([
        'application_notifications' => false,
    ]);
    $thirdCharacter = Character::factory()->primary()->create([
        'user_id' => $thirdApplicant->id,
        'name' => 'Wren Vale',
        'lodestone_id' => '56565656',
    ]);

    $this->actingAs($thirdApplicant);

    $this->post(route('groups.activities.application.store', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]), [
        'selected_character_id' => $thirdCharacter->id,
        'answers' => [
            'experience' => 'Third applicant.',
        ],
    ])->assertRedirect(route('groups.activities.application.confirmation', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    expect(NotificationEvent::query()->where('type', 'applications.new_for_review')->count())->toBe(3)
        ->and(UserNotification::query()->count())->toBe(2)
        ->and(UserNotification::query()->whereNull('read_at')->sole()->aggregate_count)->toBe(1);
});

it('notifies the run host when an application is updated', function () {
    Queue::fake();

    $owner = User::factory()->create([
        'application_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);
    $group = Group::factory()->open()->create([
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

    expect(NotificationDelivery::query()->where('notification_event_id', $event->id)->count())->toBe(2)
        ->and(NotificationDelivery::query()->where('notification_event_id', $event->id)->where('channel', NotificationChannel::EMAIL)->sole()->status)->toBe(NotificationDelivery::STATUS_PENDING)
        ->and(NotificationDelivery::query()->where('notification_event_id', $event->id)->where('channel', NotificationChannel::DISCORD)->sole()->status)->toBe(NotificationDelivery::STATUS_SKIPPED);

    Queue::assertPushed(SendNotificationEmailDeliveryJob::class, 1);
});

it('notifies the run host and applicant when an application is withdrawn', function () {
    Queue::fake();

    $owner = User::factory()->create([
        'application_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $activity = createApplicationNotificationActivity($owner, $group, [
        'allow_guest_applications' => false,
    ]);
    $applicant = User::factory()->create([
        'application_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);
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

    $hostEvent = NotificationEvent::query()
        ->where('type', 'applications.withdrawn_for_review')
        ->sole();
    $applicantEvent = NotificationEvent::query()
        ->where('type', 'applications.withdrawal_confirmed')
        ->sole();

    expect(NotificationEvent::query()->where('type', 'applications.withdrawn')->doesntExist())->toBeTrue();

    expect($hostEvent->action_url)->toBe(route('groups.dashboard.activities.show', [
        'group' => $group,
        'activity' => $activity,
    ]))
        ->and($applicantEvent->action_url)->toBe(route('account.applications'))
        ->and($hostEvent->message_params['character'])->toBe('Iris Sol')
        ->and($applicantEvent->message_params['character'])->toBe('Iris Sol');

    expect(UserNotification::query()->where('notification_event_id', $hostEvent->id)->pluck('user_id')->all())
        ->toBe([$owner->id]);
    expect(UserNotification::query()->where('notification_event_id', $applicantEvent->id)->pluck('user_id')->all())
        ->toBe([$applicant->id]);

    expect(NotificationDelivery::query()->where('notification_event_id', $hostEvent->id)->count())->toBe(2)
        ->and(NotificationDelivery::query()->where('notification_event_id', $hostEvent->id)->where('channel', NotificationChannel::EMAIL)->sole()->status)->toBe(NotificationDelivery::STATUS_PENDING)
        ->and(NotificationDelivery::query()->where('notification_event_id', $hostEvent->id)->where('channel', NotificationChannel::DISCORD)->sole()->status)->toBe(NotificationDelivery::STATUS_SKIPPED)
        ->and(NotificationDelivery::query()->where('notification_event_id', $applicantEvent->id)->count())->toBe(2)
        ->and(NotificationDelivery::query()->where('notification_event_id', $applicantEvent->id)->where('channel', NotificationChannel::EMAIL)->sole()->status)->toBe(NotificationDelivery::STATUS_PENDING)
        ->and(NotificationDelivery::query()->where('notification_event_id', $applicantEvent->id)->where('channel', NotificationChannel::DISCORD)->sole()->status)->toBe(NotificationDelivery::STATUS_SKIPPED);

    Queue::assertPushed(SendNotificationEmailDeliveryJob::class, 2);
});

it('does not send a review notification when the applicant hosts the run they withdraw from', function () {
    Queue::fake();

    $host = User::factory()->create([
        'application_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);
    $group = Group::factory()->open()->create([
        'owner_id' => $host->id,
    ]);
    $activity = createApplicationNotificationActivity($host, $group, [
        'allow_guest_applications' => false,
    ]);
    $character = Character::factory()->primary()->create([
        'user_id' => $host->id,
        'name' => 'Host Applicant',
        'lodestone_id' => '55557777',
    ]);

    $application = ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $host->id,
        'selected_character_id' => $character->id,
        'status' => ActivityApplication::STATUS_PENDING,
        'applicant_lodestone_id' => $character->lodestone_id,
        'applicant_character_name' => $character->name,
        'applicant_world' => $character->world,
        'applicant_datacenter' => $character->datacenter,
    ]);

    $this->actingAs($host);

    $this->delete(route('account.applications.destroy', [
        'application' => $application->id,
    ]))->assertRedirect(route('account.applications'));

    $event = NotificationEvent::query()->where('type', 'applications.withdrawal_confirmed')->sole();

    expect($event->action_url)->toBe(route('account.applications'));
    expect(UserNotification::query()->where('notification_event_id', $event->id)->pluck('user_id')->all())
        ->toBe([$host->id]);

    Queue::assertPushed(SendNotificationEmailDeliveryJob::class, 1);
});

it('notifies a signed in applicant when their application is declined', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $activity = createApplicationNotificationActivity($owner, $group, [
        'allow_guest_applications' => false,
    ]);
    $applicant = User::factory()->create([
        'application_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
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

    expect(NotificationDelivery::query()->where('notification_event_id', $event->id)->count())->toBe(2)
        ->and(NotificationDelivery::query()->where('notification_event_id', $event->id)->where('channel', NotificationChannel::EMAIL)->sole()->status)->toBe(NotificationDelivery::STATUS_PENDING)
        ->and(NotificationDelivery::query()->where('notification_event_id', $event->id)->where('channel', NotificationChannel::DISCORD)->sole()->status)->toBe(NotificationDelivery::STATUS_SKIPPED);

    Queue::assertPushed(SendNotificationEmailDeliveryJob::class, 1);
});

it('does not create a decline notification when the declined application belongs to a guest', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
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

it('sends off-site notifications when a signed in application is cancelled directly', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $activity = createApplicationNotificationActivity($owner, $group, [
        'allow_guest_applications' => false,
    ]);
    $applicant = User::factory()->create([
        'application_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);
    $character = Character::factory()->primary()->create([
        'user_id' => $applicant->id,
        'name' => 'Cancel Cora',
        'lodestone_id' => '88889999',
    ]);

    $application = ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $applicant->id,
        'selected_character_id' => $character->id,
        'status' => ActivityApplication::STATUS_CANCELLED,
        'applicant_lodestone_id' => $character->lodestone_id,
        'applicant_character_name' => $character->name,
        'applicant_world' => $character->world,
        'applicant_datacenter' => $character->datacenter,
    ]);

    app(ApplicationNotificationService::class)->notifyCancelled(
        $application->fresh(['activity.group', 'user', 'selectedCharacter']),
        $owner,
    );

    $event = NotificationEvent::query()->where('type', 'applications.cancelled')->sole();
    $notification = UserNotification::query()->where('notification_event_id', $event->id)->sole();

    expect($notification->user_id)->toBe($applicant->id)
        ->and(NotificationDelivery::query()->where('notification_event_id', $event->id)->count())->toBe(2)
        ->and(NotificationDelivery::query()->where('notification_event_id', $event->id)->where('channel', NotificationChannel::EMAIL)->sole()->status)->toBe(NotificationDelivery::STATUS_PENDING)
        ->and(NotificationDelivery::query()->where('notification_event_id', $event->id)->where('channel', NotificationChannel::DISCORD)->sole()->status)->toBe(NotificationDelivery::STATUS_SKIPPED);

    Queue::assertPushed(SendNotificationEmailDeliveryJob::class, 1);
});

it('does not create a separate application-category cancellation notification when a run is cancelled', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $activity = createApplicationNotificationActivity($owner, $group, [
        'allow_guest_applications' => true,
        'status' => Activity::STATUS_ASSIGNED,
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

    expect(NotificationEvent::query()->where('type', 'applications.cancelled')->doesntExist())->toBeTrue();
});
