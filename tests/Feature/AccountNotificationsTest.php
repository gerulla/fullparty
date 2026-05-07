<?php

use App\Events\UserNotificationsUpdated;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shows the notifications history page with the first 50 newest notifications and pagination metadata', function () {
    $user = User::factory()->create();

    $createdNotifications = collect();

    foreach (range(1, 55) as $index) {
        $createdNotifications->push(createNotificationForUser(
            $user,
            createdAt: now()->subMinutes(55 - $index),
        ));
    }

    $newestNotification = $createdNotifications->last();
    $oldestOnFirstPage = $createdNotifications->slice(5)->first();

    $this->actingAs($user);

    $this->get(route('account.notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Account/Notifications')
            ->where('unreadCount', 55)
            ->has('notificationsPage.items', 50)
            ->where('notificationsPage.items.0.id', $newestNotification->id)
            ->where('notificationsPage.items.49.id', $oldestOnFirstPage->id)
            ->where('notificationsPage.pagination.current_page', 1)
            ->where('notificationsPage.pagination.next_page', 2)
            ->where('notificationsPage.pagination.has_more_pages', true)
            ->where('notificationsPage.pagination.total', 55)
        );
});

it('returns later notification pages through the feed endpoint', function () {
    $user = User::factory()->create();

    $createdNotifications = collect();

    foreach (range(1, 55) as $index) {
        $createdNotifications->push(createNotificationForUser(
            $user,
            createdAt: now()->subMinutes(55 - $index),
        ));
    }

    $expectedSecondPage = $createdNotifications->take(5)->reverse()->values();

    $this->actingAs($user);

    $response = $this->getJson(route('account.notifications.feed', [
        'page' => 2,
    ]));

    $response
        ->assertOk()
        ->assertJsonCount(5, 'items')
        ->assertJsonPath('items.0.id', $expectedSecondPage[0]->id)
        ->assertJsonPath('items.4.id', $expectedSecondPage[4]->id)
        ->assertJsonPath('pagination.current_page', 2)
        ->assertJsonPath('pagination.next_page', null)
        ->assertJsonPath('pagination.has_more_pages', false)
        ->assertJsonPath('pagination.total', 55);
});

it('returns the unread count and latest notifications for the bell summary endpoint', function () {
    $user = User::factory()->create();

    $latestNotifications = collect();

    foreach (range(1, 6) as $index) {
        $latestNotifications->push(createNotificationForUser(
            $user,
            createdAt: now()->subMinutes(6 - $index),
        ));
    }

    createNotificationForUser(
        $user,
        readAt: now(),
        createdAt: now()->subMinutes(10),
    );

    $expectedLatest = $latestNotifications->reverse()->take(5)->values();

    $this->actingAs($user);

    $this->getJson(route('account.notifications.summary'))
        ->assertOk()
        ->assertJsonPath('unread_count', 6)
        ->assertJsonCount(5, 'latest')
        ->assertJsonPath('latest.0.id', $expectedLatest[0]->id)
        ->assertJsonPath('latest.4.id', $expectedLatest[4]->id)
        ->assertJsonPath('latest.0.is_unread', true);
});

it('shares unread counts and latest notifications in inertia props for authenticated users', function () {
    $user = User::factory()->create();

    $unreadNotifications = collect();

    foreach (range(1, 6) as $index) {
        $unreadNotifications->push(createNotificationForUser(
            $user,
            createdAt: now()->subMinutes(6 - $index),
        ));
    }

    createNotificationForUser(
        $user,
        readAt: now(),
        createdAt: now()->subMinutes(10),
    );

    $latestUnread = $unreadNotifications->last();

    $this->actingAs($user);

    $this->get(route('settings'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('notifications.unread_count', 6)
            ->has('notifications.latest', 5)
            ->where('notifications.latest.0.id', $latestUnread->id)
            ->where('notifications.latest.0.is_unread', true)
        );
});

it('marks all of the users notifications as read', function () {
    Event::fake([UserNotificationsUpdated::class]);

    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $first = createNotificationForUser($user);
    $second = createNotificationForUser($user);
    $otherUsersNotification = createNotificationForUser($otherUser);

    $this->actingAs($user);

    $this->post(route('account.notifications.read-all'))
        ->assertRedirect();

    expect($first->fresh()->read_at)->not->toBeNull()
        ->and($second->fresh()->read_at)->not->toBeNull()
        ->and($otherUsersNotification->fresh()->read_at)->toBeNull();

    Event::assertDispatchedTimes(UserNotificationsUpdated::class, 1);
    Event::assertDispatched(UserNotificationsUpdated::class, function (UserNotificationsUpdated $event) use ($user) {
        return $event->userId === $user->id;
    });
});

it('opens a notification, marks it as read, and redirects to its action url', function () {
    Event::fake([UserNotificationsUpdated::class]);

    $user = User::factory()->create();
    $notification = createNotificationForUser(
        $user,
        actionUrl: '/settings',
    );

    $this->actingAs($user);

    $this->get(route('account.notifications.open', $notification))
        ->assertRedirect('/settings');

    expect($notification->fresh()->read_at)->not->toBeNull();

    Event::assertDispatchedTimes(UserNotificationsUpdated::class, 1);
    Event::assertDispatched(UserNotificationsUpdated::class, function (UserNotificationsUpdated $event) use ($user) {
        return $event->userId === $user->id;
    });
});

it('redirects notification opens without an action url back to the notifications page', function () {
    Event::fake([UserNotificationsUpdated::class]);

    $user = User::factory()->create();
    $notification = createNotificationForUser(
        $user,
        actionUrl: null,
    );

    $this->actingAs($user);

    $this->get(route('account.notifications.open', $notification))
        ->assertRedirect(route('account.notifications.index'));

    expect($notification->fresh()->read_at)->not->toBeNull();

    Event::assertDispatchedTimes(UserNotificationsUpdated::class, 1);
    Event::assertDispatched(UserNotificationsUpdated::class, function (UserNotificationsUpdated $event) use ($user) {
        return $event->userId === $user->id;
    });
});

it('does not allow users to open another users notification', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $notification = createNotificationForUser($otherUser);

    $this->actingAs($user);

    $this->get(route('account.notifications.open', $notification))
        ->assertNotFound();
});

function createNotificationForUser(
    User $user,
    ?string $actionUrl = '/settings',
    ?Carbon $readAt = null,
    ?Carbon $createdAt = null,
): UserNotification {
    $timestamp = $createdAt ?? now();

    $event = NotificationEvent::query()->create([
        'type' => 'user.settings.notifications_updated',
        'category' => 'account_character_updates',
        'title_key' => 'notifications.user.settings.notifications_updated.title',
        'body_key' => 'notifications.user.settings.notifications_updated.body',
        'message_params' => [
            'changed_setting_label_keys' => [
                'settings.notifications.applications',
            ],
        ],
        'action_url' => $actionUrl,
    ]);

    $event->forceFill([
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ])->saveQuietly();

    $notification = UserNotification::query()->create([
        'notification_event_id' => $event->id,
        'user_id' => $user->id,
        'read_at' => $readAt,
    ]);

    $notification->forceFill([
        'read_at' => $readAt,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ])->saveQuietly();

    return $notification;
}
