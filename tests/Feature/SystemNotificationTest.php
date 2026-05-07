<?php

use App\Jobs\DispatchSystemNotificationBroadcastChunkJob;
use App\Jobs\DispatchSystemNotificationBroadcastJob;
use App\Jobs\SendNotificationEmailDeliveryJob;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\SocialAccount;
use App\Models\SystemBanner;
use App\Models\SystemNotificationBroadcast;
use App\Models\User;
use App\Services\Notifications\NotificationInboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('allows admins to view the system notifications page', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $this->actingAs($admin)
        ->get('/admin/system-notifications')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/SystemNotifications')
            ->has('history')
        );
});

it('forbids non admins from viewing the system notifications page', function () {
    $user = User::factory()->create([
        'is_admin' => false,
    ]);

    $this->actingAs($user)
        ->get('/admin/system-notifications')
        ->assertForbidden();
});

it('sends mandatory maintenance notifications in app and off site regardless of the category preference', function () {
    Queue::fake();

    $admin = User::factory()->create([
        'is_admin' => true,
        'email_notifications' => true,
        'discord_notifications' => false,
    ]);

    $recipient = User::factory()->create([
        'system_notice_notifications' => false,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);

    SocialAccount::query()->create([
        'user_id' => $recipient->id,
        'provider' => 'discord',
        'provider_user_id' => 'discord-system-user',
        'provider_name' => 'System Recipient',
        'provider_email' => $recipient->email,
    ]);

    $this->actingAs($admin)
        ->post('/admin/system-notifications/maintenance', [
            'headline' => 'Scheduled maintenance',
            'message' => 'FullParty will be unavailable while we patch the app.',
            'scheduled_for' => '2026-05-20 18:00:00',
            'action_url' => 'https://status.fullparty.gg/maintenance',
        ])
        ->assertRedirect();

    $broadcast = SystemNotificationBroadcast::query()->latest('id')->first();
    $event = NotificationEvent::query()->find($broadcast?->notification_event_id);

    expect($broadcast)->not->toBeNull()
        ->and($event)->not->toBeNull()
        ->and($event->type)->toBe('system.maintenance.upcoming')
        ->and($event->is_mandatory)->toBeTrue();

    Queue::assertPushed(DispatchSystemNotificationBroadcastJob::class, function (DispatchSystemNotificationBroadcastJob $job) use ($broadcast) {
        return $job->broadcastId === $broadcast->id;
    });

    expect($recipient->fresh()->inAppNotifications)->toHaveCount(0);

    (new DispatchSystemNotificationBroadcastJob($broadcast->id))->handle();

    Queue::assertPushed(DispatchSystemNotificationBroadcastChunkJob::class, 1);

    (new DispatchSystemNotificationBroadcastChunkJob($broadcast->id, 1, $recipient->id))->handle(
        app(\App\Services\Notifications\NotificationService::class),
        app(\App\Services\Notifications\NotificationDeliveryDispatcher::class),
    );

    expect($recipient->fresh()->inAppNotifications)->toHaveCount(0)
        ->and(app(NotificationInboxService::class)->unreadCount($recipient))->toBe(1);

    $emailDelivery = NotificationDelivery::query()
        ->where('notification_event_id', $event->id)
        ->where('user_id', $recipient->id)
        ->where('channel', 'email')
        ->sole();

    $discordDelivery = NotificationDelivery::query()
        ->where('notification_event_id', $event->id)
        ->where('user_id', $recipient->id)
        ->where('channel', 'discord')
        ->sole();

    expect($emailDelivery->status)->toBe(NotificationDelivery::STATUS_PENDING)
        ->and($discordDelivery->status)->toBe(NotificationDelivery::STATUS_SKIPPED)
        ->and($discordDelivery->status_reason)->toBe('discord_transport_unavailable');

    Queue::assertPushed(SendNotificationEmailDeliveryJob::class);
});

it('only sends optional update announcements to users who have system notices enabled', function () {
    Queue::fake();

    $admin = User::factory()->create([
        'is_admin' => true,
        'email_notifications' => true,
    ]);

    $optedInUser = User::factory()->create([
        'system_notice_notifications' => true,
        'email_notifications' => true,
    ]);

    $optedOutUser = User::factory()->create([
        'system_notice_notifications' => false,
        'email_notifications' => true,
    ]);

    $this->actingAs($admin)
        ->post('/admin/system-notifications/announcements', [
            'headline' => 'New feature drop',
            'message' => 'Follower muting is now live for groups.',
            'action_url' => 'https://test.fullparty.gg/changelog',
        ])
        ->assertRedirect();

    $broadcast = SystemNotificationBroadcast::query()->latest('id')->first();
    $event = NotificationEvent::query()->find($broadcast?->notification_event_id);

    expect($broadcast)->not->toBeNull()
        ->and($event)->not->toBeNull()
        ->and($event->type)->toBe('system.announcement')
        ->and($event->is_mandatory)->toBeFalse();

    Queue::assertPushed(DispatchSystemNotificationBroadcastJob::class, function (DispatchSystemNotificationBroadcastJob $job) use ($broadcast) {
        return $job->broadcastId === $broadcast->id;
    });

    expect($optedInUser->fresh()->inAppNotifications)->toHaveCount(0)
        ->and($optedOutUser->fresh()->inAppNotifications)->toHaveCount(0);

    (new DispatchSystemNotificationBroadcastJob($broadcast->id))->handle();

    Queue::assertPushed(DispatchSystemNotificationBroadcastChunkJob::class, 1);

    (new DispatchSystemNotificationBroadcastChunkJob($broadcast->id, 1, $optedOutUser->id))->handle(
        app(\App\Services\Notifications\NotificationService::class),
        app(\App\Services\Notifications\NotificationDeliveryDispatcher::class),
    );

    expect($optedInUser->fresh()->inAppNotifications)->toHaveCount(0)
        ->and($optedOutUser->fresh()->inAppNotifications)->toHaveCount(0);

    expect(app(NotificationInboxService::class)->unreadCount($optedInUser))->toBe(1)
        ->and(app(NotificationInboxService::class)->unreadCount($optedOutUser))->toBe(0);

    expect(NotificationDelivery::query()
        ->where('notification_event_id', $event->id)
        ->where('user_id', $optedInUser->id)
        ->where('channel', 'email')
        ->exists())->toBeTrue()
        ->and(NotificationDelivery::query()
            ->where('notification_event_id', $event->id)
            ->where('user_id', $optedOutUser->id)
            ->count())->toBe(0);
});

it('allows admins to save and clear the active system banner', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->put(route('admin.system-notifications.banner.store'), [
            'title' => 'Service degradation',
            'message' => 'Roster updates may take longer than usual while we work through a database issue.',
            'action_label' => 'View status',
            'action_url' => 'https://status.fullparty.gg/',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'system_banner_saved');

    $banner = SystemBanner::query()->sole();

    expect($banner->title)->toBe('Service degradation')
        ->and($banner->action_label)->toBe('View status');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('system_banner.title', 'Service degradation')
            ->where('system_banner.action_label', 'View status')
        );

    $this->actingAs($admin)
        ->delete(route('admin.system-notifications.banner.clear'))
        ->assertRedirect()
        ->assertSessionHas('success', 'system_banner_cleared');

    expect(SystemBanner::query()->count())->toBe(0);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('system_banner', null)
        );
});

it('forbids non admins from changing the system banner', function () {
    $user = User::factory()->create([
        'is_admin' => false,
    ]);

    $this->actingAs($user)
        ->put(route('admin.system-notifications.banner.store'), [
            'title' => 'Service degradation',
            'message' => 'Roster updates may take longer than usual while we work through a database issue.',
        ])
        ->assertForbidden();

    $this->actingAs($user)
        ->delete(route('admin.system-notifications.banner.clear'))
        ->assertForbidden();
});
