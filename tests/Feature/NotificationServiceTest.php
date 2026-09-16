<?php

use App\Events\UserNotificationsUpdated;
use App\Jobs\SendNotificationEmailDeliveryJob;
use App\Mail\NotificationDeliveryMail;
use App\Models\DiscordUserIntegration;
use App\Models\IntegrationClient;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\EmailNotificationDeliveryService;
use App\Services\Notifications\NotificationMessageRenderer;
use App\Services\Notifications\NotificationRealtimeService;
use App\Services\Notifications\NotificationService;
use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\NotificationChannel;
use App\Support\Notifications\NotificationTopic;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

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

it('queues user inbox realtime broadcasts instead of sending them inline', function () {
    Queue::fake();

    $user = User::factory()->create();

    app(NotificationRealtimeService::class)->broadcastUserInboxUpdated($user);

    Queue::assertPushed(BroadcastEvent::class, function (BroadcastEvent $job) use ($user) {
        return $job->event instanceof UserNotificationsUpdated
            && $job->event->userId === $user->id;
    });
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

it('aggregates unread in app notifications by aggregate key and resets after read', function () {
    Event::fake([UserNotificationsUpdated::class]);

    $recipient = User::factory()->create([
        'application_notifications' => true,
    ]);

    $service = app(NotificationService::class);

    $firstEvent = $service->createEvent(
        type: 'applications.new_for_review',
        category: NotificationCategory::APPLICATIONS,
        titleKey: 'notifications.applications.new_for_review.title',
        bodyKey: 'notifications.applications.new_for_review.body',
        messageParams: [
            'activity' => 'Weekly Savage',
        ],
    );

    $secondEvent = $service->createEvent(
        type: 'applications.new_for_review',
        category: NotificationCategory::APPLICATIONS,
        titleKey: 'notifications.applications.new_for_review.title',
        bodyKey: 'notifications.applications.new_for_review.body',
        messageParams: [
            'activity' => 'Weekly Savage',
        ],
    );

    $firstPass = $service->sendAggregatedInAppNotifications(
        $firstEvent,
        $recipient,
        'applications.new_for_review.activity.1',
    );
    $secondPass = $service->sendAggregatedInAppNotifications(
        $secondEvent,
        $recipient,
        'applications.new_for_review.activity.1',
    );

    expect($firstPass)->toHaveCount(1)
        ->and($secondPass)->toHaveCount(1)
        ->and(UserNotification::query()->count())->toBe(1);

    $notification = UserNotification::query()->sole();

    expect($notification->notification_event_id)->toBe($secondEvent->id)
        ->and($notification->aggregate_key)->toBe('applications.new_for_review.activity.1')
        ->and($notification->aggregate_count)->toBe(2)
        ->and($secondEvent->fresh()->message_params['count'])->toBe(2);

    $notification->markAsRead();

    $thirdEvent = $service->createEvent(
        type: 'applications.new_for_review',
        category: NotificationCategory::APPLICATIONS,
        titleKey: 'notifications.applications.new_for_review.title',
        bodyKey: 'notifications.applications.new_for_review.body',
        messageParams: [
            'activity' => 'Weekly Savage',
        ],
    );

    $thirdPass = $service->sendAggregatedInAppNotifications(
        $thirdEvent,
        $recipient,
        'applications.new_for_review.activity.1',
    );

    expect($thirdPass)->toHaveCount(1)
        ->and(UserNotification::query()->count())->toBe(2)
        ->and(UserNotification::query()->whereNull('read_at')->sole()->aggregate_count)->toBe(1)
        ->and($thirdEvent->fresh()->message_params['count'])->toBe(1);

    Event::assertDispatchedTimes(UserNotificationsUpdated::class, 3);
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

    DiscordUserIntegration::query()->create([
        'user_id' => $recipient->id,
        'discord_user_id' => 'discord-user-123',
        'username' => 'Notif Tester',
        'user_app_installed_at' => now(),
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

    DiscordUserIntegration::query()->create([
        'user_id' => $categoryOptOutUser->id,
        'discord_user_id' => 'discord-opt-out',
        'username' => 'Category Opt Out',
        'user_app_installed_at' => now(),
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

    DiscordUserIntegration::query()->create([
        'user_id' => $recipient->id,
        'discord_user_id' => 'discord-linked-456',
        'username' => 'Late Link',
        'user_app_installed_at' => now(),
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

it('defaults supported discord delivery topics on once the recipient installs the discord app', function () {
    $recipient = User::factory()->create([
        'application_notifications' => true,
        'discord_notifications' => false,
    ]);

    DiscordUserIntegration::query()->create([
        'user_id' => $recipient->id,
        'discord_user_id' => 'discord-default-delivery',
        'username' => 'Default Delivery',
        'user_app_installed_at' => now(),
    ]);

    $service = app(NotificationService::class);
    $event = $service->createEvent(
        type: 'applications.submitted',
        category: NotificationCategory::APPLICATIONS,
        titleKey: 'notifications.applications.submitted.title',
        topic: NotificationTopic::APPLICATIONS_SUBMITTED,
    );

    $deliveries = $service->sendOffSiteNotifications($event, $recipient->fresh(), [NotificationChannel::DISCORD]);

    expect($deliveries)->toHaveCount(1)
        ->and($deliveries->sole()->status)->toBe(NotificationDelivery::STATUS_SKIPPED)
        ->and($deliveries->sole()->target)->toBe('discord-default-delivery')
        ->and($deliveries->sole()->status_reason)->toBe('discord_transport_unavailable');
});

it('sends discord deliveries through the configured discord bot integration', function () {
    $recipient = User::factory()->create([
        'name' => 'Discord Ready',
        'account_character_notifications' => true,
        'discord_notifications' => true,
    ]);

    DiscordUserIntegration::query()->create([
        'user_id' => $recipient->id,
        'discord_user_id' => 'discord-linked-789',
        'username' => 'Discord Ready',
        'user_app_installed_at' => now(),
    ]);

    IntegrationClient::factory()->create([
        'name' => 'FullParty Discord Bot',
        'outbound_events_url' => 'https://discord-bot.fullparty.test/events',
        'webhook_signing_secret' => 'notification-secret',
        'allowed_events' => [
            IntegrationClient::EVENT_DISCORD_NOTIFICATION_DELIVERY,
        ],
    ]);

    Http::fake([
        'https://discord-bot.fullparty.test/events' => Http::response([], 204),
    ]);

    $service = app(NotificationService::class);
    $event = $service->createEvent(
        type: 'user.social_account.linked',
        category: NotificationCategory::ACCOUNT_CHARACTER_UPDATES,
        titleKey: 'notifications.user.social_account.linked.title',
        bodyKey: 'notifications.user.social_account.linked.body',
        messageParams: [
            'provider' => 'Discord',
        ],
        actionUrl: '/settings',
    );

    $deliveries = $service->sendOffSiteNotifications($event, $recipient, [NotificationChannel::DISCORD]);
    $delivery = $deliveries->sole()->fresh();

    expect($delivery->status)->toBe(NotificationDelivery::STATUS_SENT)
        ->and($delivery->target)->toBe('discord-linked-789')
        ->and($delivery->sent_at)->not->toBeNull()
        ->and($delivery->status_reason)->toBeNull();

    Http::assertSent(function (HttpRequest $request) use ($delivery, $recipient) {
        if ($request->url() !== 'https://discord-bot.fullparty.test/events') {
            return false;
        }

        $body = $request->body();
        $timestamp = $request->header('X-FullParty-Timestamp')[0] ?? null;
        $payload = json_decode($body, true);

        expect($payload['event'])->toBe(IntegrationClient::EVENT_DISCORD_NOTIFICATION_DELIVERY)
            ->and($payload['data']['notification_delivery_id'])->toBe($delivery->id)
            ->and($payload['data']['user']['id'])->toBe($recipient->id)
            ->and($payload['data']['discord_user']['id'])->toBe('discord-linked-789')
            ->and($payload['data']['notification']['type'])->toBe('user.social_account.linked')
            ->and($payload['data']['notification']['category'])->toBe(NotificationCategory::ACCOUNT_CHARACTER_UPDATES)
            ->and($payload['data']['notification']['params']['provider'])->toBe('Discord')
            ->and($payload['data']['notification']['action_url'])->toBe('/settings')
            ->and($payload['data']['notification'])->not->toHaveKeys(['title_key', 'body_key']);

        return is_string($timestamp)
            && ($request->header('X-FullParty-Event')[0] ?? null) === IntegrationClient::EVENT_DISCORD_NOTIFICATION_DELIVERY
            && ($request->header('X-FullParty-Signature')[0] ?? null) === 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'notification-secret');
    });
});

it('sends email deliveries through the email delivery service and marks them as sent', function () {
    Mail::fake();
    Queue::fake();
    config()->set('mail.default', 'postmark');

    $recipient = User::factory()->create([
        'application_notifications' => true,
        'email_notifications' => true,
    ]);

    $service = app(NotificationService::class);
    $event = $service->createEvent(
        type: 'user.social_account.linked',
        category: NotificationCategory::ACCOUNT_CHARACTER_UPDATES,
        titleKey: 'notifications.user.social_account.linked.title',
        bodyKey: 'notifications.user.social_account.linked.body',
        messageParams: [
            'provider' => 'Discord',
        ],
    );

    $service->sendOffSiteNotifications($event, $recipient, [NotificationChannel::EMAIL]);

    Queue::assertPushed(SendNotificationEmailDeliveryJob::class, 1);

    $delivery = NotificationDelivery::query()->sole();

    app(EmailNotificationDeliveryService::class)->send($delivery->id);

    Mail::assertSent(NotificationDeliveryMail::class, function (NotificationDeliveryMail $mail) {
        return $mail->usesMailer('postmark')
            && $mail->subjectLine === 'Connected account linked'
            && $mail->bodyText === 'Your Discord account was linked to FullParty.';
    });

    $delivery->refresh();

    expect($delivery->status)->toBe(NotificationDelivery::STATUS_SENT)
        ->and($delivery->status_reason)->toBeNull()
        ->and($delivery->sent_at)->not->toBeNull()
        ->and($delivery->target)->toBe($recipient->email);

});

it('routes optional system announcement emails through the postmark broadcast stream', function () {
    Mail::fake();
    Queue::fake();
    config()->set('mail.default', 'postmark');

    $recipient = User::factory()->create([
        'system_notice_notifications' => true,
        'email_notifications' => true,
    ]);

    $event = NotificationEvent::query()->forceCreate([
        'type' => 'system.announcement',
        'category' => NotificationCategory::SYSTEM_NOTICES,
        'is_mandatory' => false,
        'title_key' => 'notifications.system.announcement.title',
        'body_key' => 'notifications.system.announcement.body',
        'message_params' => [
            'headline' => 'New feature',
            'message' => 'Follower muting is now live.',
        ],
    ]);

    app(NotificationService::class)->sendOffSiteNotifications($event, $recipient, [NotificationChannel::EMAIL]);

    $delivery = NotificationDelivery::query()->sole();

    app(EmailNotificationDeliveryService::class)->send($delivery->id);

    Mail::assertSent(NotificationDeliveryMail::class, function (NotificationDeliveryMail $mail) {
        return $mail->usesMailer('postmark_broadcast');
    });
});

it('renders assignment notification emails with translated copy instead of raw keys', function () {
    $recipient = User::factory()->create();

    $event = NotificationEvent::query()->forceCreate([
        'type' => 'assignments.roster_published_assigned',
        'category' => NotificationCategory::ASSIGNMENTS,
        'title_key' => 'notifications.assignments.roster_published_assigned.title',
        'body_key' => 'notifications.assignments.roster_published_assigned.body',
        'message_params' => [
            'activity' => 'Weekly Savage',
            'slot' => 'Party A 1',
            'character' => 'Astra Vale',
        ],
    ]);

    $message = app(NotificationMessageRenderer::class)->render($event, $recipient);

    expect($message['subject'])->toBe('Roster published')
        ->and($message['body'])->toBe('The roster for Weekly Savage has been published. You are assigned to Party A 1 as Astra Vale.');
});

it('renders same-origin notification action urls without a fixed locale for browser preference', function () {
    $recipient = User::factory()->create();

    $event = NotificationEvent::query()->forceCreate([
        'type' => 'user.social_account.linked',
        'category' => NotificationCategory::ACCOUNT_CHARACTER_UPDATES,
        'title_key' => 'notifications.user.social_account.linked.title',
        'body_key' => 'notifications.user.social_account.linked.body',
        'message_params' => [
            'provider' => 'Discord',
        ],
        'action_url' => 'http://fullparty.test/en/settings?tab=notifications#channels',
    ]);

    $message = app(NotificationMessageRenderer::class)->render($event, $recipient);

    expect($message['action_url'])->toBe('http://fullparty.test/settings?tab=notifications#channels');
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
    $event = NotificationEvent::query()->forceCreate([
        'type' => 'applications.submitted',
        'category' => NotificationCategory::APPLICATIONS,
        'title_key' => 'notifications.applications.submitted.title',
    ]);

    app(NotificationService::class)->sendOffSiteNotifications($event, $recipient, ['pagerduty']);
})->throws(InvalidArgumentException::class);
