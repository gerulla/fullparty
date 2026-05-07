<?php

use App\Events\UserNotificationsUpdated;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\NotificationChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use App\Jobs\SendNotificationEmailDeliveryJob;
use App\Services\Notifications\EmailNotificationDeliveryService;

uses(RefreshDatabase::class);

it('creates notification events with actor, subject, and rendering metadata', function () {
    $actor = User::factory()->create();
    $subject = User::factory()->create();

    $event = app(NotificationService::class)->createEvent(
        type: 'applications.submitted',
        category: NotificationCategory::APPLICATIONS,
        titleKey: 'notifications.applications.submitted.title',
        bodyKey: 'notifications.applications.submitted.body',
        messageParams: [
            'activity' => 'Fresh Prog',
        ],
        actionUrl: '/groups/example/activities/1',
        actor: $actor,
        subject: $subject,
        payload: [
            'activity_id' => 1,
        ],
    );

    expect($event)
        ->type->toBe('applications.submitted')
        ->and($event->category)->toBe(NotificationCategory::APPLICATIONS)
        ->and($event->actor_user_id)->toBe($actor->id)
        ->and($event->subject_type)->toBe(User::class)
        ->and($event->subject_id)->toBe($subject->id)
        ->and($event->title_key)->toBe('notifications.applications.submitted.title')
        ->and($event->body_key)->toBe('notifications.applications.submitted.body')
        ->and($event->message_params)->toBe([
            'activity' => 'Fresh Prog',
        ])
        ->and($event->payload)->toBe([
            'activity_id' => 1,
        ]);
});

it('creates in app notifications only for recipients who want the category and stays idempotent', function () {
    Event::fake([UserNotificationsUpdated::class]);

    $optedInUser = User::factory()->create([
        'application_notifications' => true,
    ]);
    $optedOutUser = User::factory()->create([
        'application_notifications' => false,
    ]);

    $service = app(NotificationService::class);
    $event = $service->createEvent(
        type: 'applications.submitted',
        category: NotificationCategory::APPLICATIONS,
        titleKey: 'notifications.applications.submitted.title',
    );

    $createdNotifications = $service->sendInAppNotifications($event, [$optedInUser, $optedOutUser]);
    $duplicateCall = $service->sendInAppNotifications($event, [$optedInUser, $optedOutUser]);

    expect($createdNotifications)->toHaveCount(1)
        ->and($duplicateCall)->toHaveCount(1)
        ->and($createdNotifications->sole()->user_id)->toBe($optedInUser->id)
        ->and($duplicateCall->sole()->id)->toBe($createdNotifications->sole()->id);

    expect($optedInUser->fresh()->inAppNotifications)->toHaveCount(1)
        ->and($optedOutUser->fresh()->inAppNotifications)->toHaveCount(0);

    Event::assertDispatchedTimes(UserNotificationsUpdated::class, 1);
    Event::assertDispatched(UserNotificationsUpdated::class, function (UserNotificationsUpdated $event) use ($optedInUser) {
        return $event->userId === $optedInUser->id;
    });
});

it('lets mandatory system notices bypass the optional system notice preference for in app notifications', function () {
    $recipient = User::factory()->create([
        'system_notice_notifications' => false,
    ]);

    $service = app(NotificationService::class);
    $event = $service->createEvent(
        type: 'system.maintenance',
        category: NotificationCategory::SYSTEM_NOTICES,
        titleKey: 'notifications.system.maintenance.title',
        isMandatory: true,
    );

    $notifications = $service->sendInAppNotifications($event, $recipient);

    expect($notifications)->toHaveCount(1)
        ->and($recipient->fresh()->inAppNotifications)->toHaveCount(1);
});

it('lets mandatory system notices queue off site deliveries when the delivery channel is enabled', function () {
    Queue::fake();

    $recipient = User::factory()->create([
        'system_notice_notifications' => false,
        'email_notifications' => true,
    ]);

    $service = app(NotificationService::class);
    $event = $service->createEvent(
        type: 'system.maintenance',
        category: NotificationCategory::SYSTEM_NOTICES,
        titleKey: 'notifications.system.maintenance.title',
        isMandatory: true,
    );

    $deliveries = $service->sendOffSiteNotifications($event, $recipient, [NotificationChannel::EMAIL]);

    expect($deliveries)->toHaveCount(1)
        ->and($deliveries->sole()->status)->toBe(NotificationDelivery::STATUS_PENDING)
        ->and($deliveries->sole()->target)->toBe($recipient->email);

    Queue::assertPushed(SendNotificationEmailDeliveryJob::class, 1);
});

it('creates pending off site delivery rows for eligible email and discord recipients without duplicating them', function () {
    Queue::fake();

    $recipient = User::factory()->create([
        'application_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);

    SocialAccount::query()->create([
        'user_id' => $recipient->id,
        'provider' => 'discord',
        'provider_user_id' => 'discord-user-123',
        'provider_name' => 'Notif Tester',
        'provider_email' => $recipient->email,
    ]);

    $service = app(NotificationService::class);
    $event = $service->createEvent(
        type: 'applications.submitted',
        category: NotificationCategory::APPLICATIONS,
        titleKey: 'notifications.applications.submitted.title',
    );

    $deliveries = $service->sendOffSiteNotifications($event, $recipient);
    $duplicateCall = $service->sendOffSiteNotifications($event, $recipient);

    expect($deliveries)->toHaveCount(2)
        ->and($duplicateCall)->toHaveCount(2)
        ->and(NotificationDelivery::query()->count())->toBe(2);

    $emailDelivery = NotificationDelivery::query()
        ->where('notification_event_id', $event->id)
        ->where('user_id', $recipient->id)
        ->where('channel', NotificationChannel::EMAIL)
        ->sole();

    $discordDelivery = NotificationDelivery::query()
        ->where('notification_event_id', $event->id)
        ->where('user_id', $recipient->id)
        ->where('channel', NotificationChannel::DISCORD)
        ->sole();

    expect($emailDelivery->status)->toBe(NotificationDelivery::STATUS_PENDING)
        ->and($emailDelivery->target)->toBe($recipient->email)
        ->and($emailDelivery->queued_at)->not->toBeNull()
        ->and($discordDelivery->status)->toBe(NotificationDelivery::STATUS_SKIPPED)
        ->and($discordDelivery->target)->toBe('discord-user-123')
        ->and($discordDelivery->status_reason)->toBe('discord_transport_unavailable');

    Queue::assertPushed(SendNotificationEmailDeliveryJob::class, 1);
});

it('records skipped off site deliveries when preferences or delivery targets do not allow sending', function () {
    $categoryOptOutUser = User::factory()->create([
        'application_notifications' => false,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);

    SocialAccount::query()->create([
        'user_id' => $categoryOptOutUser->id,
        'provider' => 'discord',
        'provider_user_id' => 'discord-opt-out',
        'provider_name' => 'Category Opt Out',
        'provider_email' => $categoryOptOutUser->email,
    ]);

    $missingDiscordUser = User::factory()->create([
        'application_notifications' => true,
        'email_notifications' => false,
        'discord_notifications' => true,
    ]);

    $service = app(NotificationService::class);
    $event = $service->createEvent(
        type: 'applications.submitted',
        category: NotificationCategory::APPLICATIONS,
        titleKey: 'notifications.applications.submitted.title',
    );

    $deliveries = $service->sendOffSiteNotifications($event, [$categoryOptOutUser, $missingDiscordUser]);

    expect($deliveries)->toHaveCount(4);

    $categoryOptOutDeliveries = NotificationDelivery::query()
        ->where('notification_event_id', $event->id)
        ->where('user_id', $categoryOptOutUser->id)
        ->orderBy('channel')
        ->get();

    expect($categoryOptOutDeliveries)->toHaveCount(2)
        ->and($categoryOptOutDeliveries->pluck('status')->all())->toBe([
            NotificationDelivery::STATUS_SKIPPED,
            NotificationDelivery::STATUS_SKIPPED,
        ])
        ->and($categoryOptOutDeliveries->pluck('status_reason')->unique()->values()->all())->toBe([
            'category_preference_disabled',
        ]);

    $emailDelivery = NotificationDelivery::query()
        ->where('notification_event_id', $event->id)
        ->where('user_id', $missingDiscordUser->id)
        ->where('channel', NotificationChannel::EMAIL)
        ->sole();

    $discordDelivery = NotificationDelivery::query()
        ->where('notification_event_id', $event->id)
        ->where('user_id', $missingDiscordUser->id)
        ->where('channel', NotificationChannel::DISCORD)
        ->sole();

    expect($emailDelivery->status)->toBe(NotificationDelivery::STATUS_SKIPPED)
        ->and($emailDelivery->status_reason)->toBe('channel_preference_disabled')
        ->and($discordDelivery->status)->toBe(NotificationDelivery::STATUS_SKIPPED)
        ->and($discordDelivery->status_reason)->toBe('missing_discord_account');
});

it('can promote a previously skipped discord delivery to pending once the recipient links discord and enables the channel', function () {
    $recipient = User::factory()->create([
        'application_notifications' => true,
        'discord_notifications' => false,
    ]);

    $service = app(NotificationService::class);
    $event = $service->createEvent(
        type: 'applications.submitted',
        category: NotificationCategory::APPLICATIONS,
        titleKey: 'notifications.applications.submitted.title',
    );

    $firstPass = $service->sendOffSiteNotifications($event, $recipient, [NotificationChannel::DISCORD]);

    expect($firstPass)->toHaveCount(1)
        ->and($firstPass->sole()->status)->toBe(NotificationDelivery::STATUS_SKIPPED)
        ->and($firstPass->sole()->status_reason)->toBe('channel_preference_disabled');

    SocialAccount::query()->create([
        'user_id' => $recipient->id,
        'provider' => 'discord',
        'provider_user_id' => 'discord-linked-456',
        'provider_name' => 'Late Link',
        'provider_email' => $recipient->email,
    ]);

    $recipient->update([
        'discord_notifications' => true,
    ]);

    $secondPass = $service->sendOffSiteNotifications($event, $recipient->fresh(), [NotificationChannel::DISCORD]);

    expect($secondPass)->toHaveCount(1)
        ->and($secondPass->sole()->status)->toBe(NotificationDelivery::STATUS_SKIPPED)
        ->and($secondPass->sole()->target)->toBe('discord-linked-456')
        ->and($secondPass->sole()->status_reason)->toBe('discord_transport_unavailable');

    expect(NotificationDelivery::query()->count())->toBe(1);
});

it('sends email deliveries through the email delivery service and marks them as sent', function () {
    Mail::fake();
    Queue::fake();

    $recipient = User::factory()->create([
        'application_notifications' => true,
        'email_notifications' => true,
    ]);

    $service = app(NotificationService::class);
    $event = $service->createEvent(
        type: 'user.settings.username_updated',
        category: NotificationCategory::ACCOUNT_CHARACTER_UPDATES,
        titleKey: 'notifications.user.settings.username_updated.title',
        bodyKey: 'notifications.user.settings.username_updated.body',
        messageParams: [
            'changed_setting_label_keys' => [
                'general.username',
            ],
        ],
    );

    $service->sendOffSiteNotifications($event, $recipient, [NotificationChannel::EMAIL]);

    Queue::assertPushed(SendNotificationEmailDeliveryJob::class, 1);

    $delivery = NotificationDelivery::query()->sole();

    app(EmailNotificationDeliveryService::class)->send($delivery->id);

    $delivery->refresh();

    expect($delivery->status)->toBe(NotificationDelivery::STATUS_SENT)
        ->and($delivery->status_reason)->toBeNull()
        ->and($delivery->sent_at)->not->toBeNull()
        ->and($delivery->target)->toBe($recipient->email);

});

it('rejects invalid notification categories', function () {
    app(NotificationService::class)->createEvent(
        type: 'applications.submitted',
        category: 'made_up_category',
        titleKey: 'notifications.fake.title',
    );
})->throws(InvalidArgumentException::class);

it('rejects invalid notification channels', function () {
    $recipient = User::factory()->create();
    $event = NotificationEvent::query()->create([
        'type' => 'applications.submitted',
        'category' => NotificationCategory::APPLICATIONS,
        'title_key' => 'notifications.applications.submitted.title',
    ]);

    app(NotificationService::class)->sendOffSiteNotifications($event, $recipient, ['pagerduty']);
})->throws(InvalidArgumentException::class);
