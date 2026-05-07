<?php

use App\Models\AuditLog;
use App\Models\NotificationEvent;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Notifications\NotificationCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses opt out defaults for notification categories and opt in defaults for optional system notices', function () {
    $user = User::query()->create([
        'name' => 'Settings Tester',
        'email' => 'settings@example.com',
        'password' => 'password',
    ])->fresh();

    expect($user->application_notifications)->toBeTrue()
        ->and($user->run_and_reminder_notifications)->toBeTrue()
        ->and($user->group_update_notifications)->toBeTrue()
        ->and($user->assignment_notifications)->toBeTrue()
        ->and($user->account_character_notifications)->toBeTrue()
        ->and($user->system_notice_notifications)->toBeFalse()
        ->and($user->email_notifications)->toBeFalse()
        ->and($user->discord_notifications)->toBeFalse();
});

it('updates the new notification category preferences and delivery channels', function () {
    $user = User::factory()->create([
        'application_notifications' => true,
        'run_and_reminder_notifications' => true,
        'group_update_notifications' => true,
        'assignment_notifications' => true,
        'account_character_notifications' => true,
        'system_notice_notifications' => false,
        'email_notifications' => false,
        'discord_notifications' => false,
    ]);

    SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => 'discord',
        'provider_user_id' => 'discord-123',
        'provider_name' => 'Settings Tester',
        'provider_email' => $user->email,
    ]);

    $this->actingAs($user);

    $response = $this->post(route('settings.notifications'), [
        'application_notifications' => false,
        'run_and_reminder_notifications' => false,
        'group_update_notifications' => false,
        'assignment_notifications' => false,
        'account_character_notifications' => false,
        'system_notice_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);

    $response
        ->assertRedirect(route('settings'))
        ->assertSessionHas('success', ['notification_settings_updated']);

    $user->refresh();

    expect($user->application_notifications)->toBeFalse()
        ->and($user->run_and_reminder_notifications)->toBeFalse()
        ->and($user->group_update_notifications)->toBeFalse()
        ->and($user->assignment_notifications)->toBeFalse()
        ->and($user->account_character_notifications)->toBeFalse()
        ->and($user->system_notice_notifications)->toBeTrue()
        ->and($user->email_notifications)->toBeTrue()
        ->and($user->discord_notifications)->toBeTrue();

    $auditLog = AuditLog::query()->where('action', 'user.settings.notifications_updated')->sole();

    expect($auditLog->actor_user_id)->toBe($user->id)
        ->and($auditLog->metadata['changes']['run_and_reminder_notifications']['old'])->toBeTrue()
        ->and($auditLog->metadata['changes']['run_and_reminder_notifications']['new'])->toBeFalse()
        ->and($auditLog->metadata['changes']['system_notice_notifications']['old'])->toBeFalse()
        ->and($auditLog->metadata['changes']['system_notice_notifications']['new'])->toBeTrue();

    $event = NotificationEvent::query()->where('type', 'user.settings.notifications_updated')->sole();

    expect($event->category)->toBe(NotificationCategory::ACCOUNT_CHARACTER_UPDATES)
        ->and($event->is_mandatory)->toBeTrue()
        ->and($event->actor_user_id)->toBe($user->id)
        ->and($event->subject_type)->toBe(User::class)
        ->and($event->subject_id)->toBe($user->id)
        ->and($event->title_key)->toBe('notifications.user.settings.notifications_updated.title')
        ->and($event->body_key)->toBe('notifications.user.settings.notifications_updated.body')
        ->and($event->action_url)->toBe(route('settings'))
        ->and($event->message_params['changed_category_label_keys'])->toBe([
            'settings.notifications.applications',
            'settings.notifications.runs_and_reminders',
            'settings.notifications.group_updates',
            'settings.notifications.assignments',
            'settings.notifications.account_character_updates',
            'settings.notifications.system_notices',
        ])
        ->and($event->message_params['changed_channel_label_keys'])->toBe([
            'settings.notifications.email_notifications',
            'settings.notifications.discord_notifications',
        ])
        ->and($event->message_params['changed_setting_label_keys'])->toBe([
            'settings.notifications.applications',
            'settings.notifications.runs_and_reminders',
            'settings.notifications.group_updates',
            'settings.notifications.assignments',
            'settings.notifications.account_character_updates',
            'settings.notifications.system_notices',
            'settings.notifications.email_notifications',
            'settings.notifications.discord_notifications',
        ]);

    $userNotification = UserNotification::query()->where('notification_event_id', $event->id)->sole();

    expect($userNotification->user_id)->toBe($user->id)
        ->and($user->fresh()->inAppNotifications)->toHaveCount(1);
});

it('forces discord notifications off when the user does not have a discord account linked', function () {
    $user = User::factory()->create([
        'discord_notifications' => false,
    ]);

    $this->actingAs($user);

    $this->post(route('settings.notifications'), [
        'application_notifications' => true,
        'run_and_reminder_notifications' => true,
        'group_update_notifications' => true,
        'assignment_notifications' => true,
        'account_character_notifications' => true,
        'system_notice_notifications' => false,
        'email_notifications' => true,
        'discord_notifications' => true,
    ])->assertRedirect(route('settings'));

    expect($user->fresh()->discord_notifications)->toBeFalse();
});
