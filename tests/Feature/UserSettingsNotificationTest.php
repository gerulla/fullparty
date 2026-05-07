<?php

use App\Models\AuditLog;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Notifications\NotificationCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates an in app notification when the username is updated', function () {
    $user = User::factory()->create([
        'name' => 'Before Name',
    ]);

    $this->actingAs($user);

    $this->post(route('settings.username'), [
        'username' => 'After Name',
    ])->assertRedirect(route('settings'));

    $user->refresh();

    expect($user->name)->toBe('After Name');

    $auditLog = AuditLog::query()->where('action', 'user.settings.username_updated')->sole();

    expect($auditLog->actor_user_id)->toBe($user->id)
        ->and($auditLog->metadata['changes']['name']['old'])->toBe('Before Name')
        ->and($auditLog->metadata['changes']['name']['new'])->toBe('After Name');

    $event = NotificationEvent::query()->where('type', 'user.settings.username_updated')->sole();

    expect($event->category)->toBe(NotificationCategory::ACCOUNT_CHARACTER_UPDATES)
        ->and($event->is_mandatory)->toBeTrue()
        ->and($event->actor_user_id)->toBe($user->id)
        ->and($event->subject_type)->toBe(User::class)
        ->and($event->subject_id)->toBe($user->id)
        ->and($event->title_key)->toBe('notifications.user.settings.username_updated.title')
        ->and($event->body_key)->toBe('notifications.user.settings.username_updated.body')
        ->and($event->action_url)->toBe(route('settings'))
        ->and($event->message_params['changed_setting_label_keys'])->toBe([
            'general.username',
        ]);

    $userNotification = UserNotification::query()->where('notification_event_id', $event->id)->sole();

    expect($userNotification->user_id)->toBe($user->id);
});

it('creates an in app notification when privacy settings are updated', function () {
    $user = User::factory()->create([
        'public_profile' => true,
        'public_characters' => true,
    ]);

    $this->actingAs($user);

    $this->post(route('settings.privacy'), [
        'public_profile' => false,
        'public_characters' => false,
    ])->assertRedirect(route('settings'));

    $user->refresh();

    expect($user->public_profile)->toBeFalse()
        ->and($user->public_characters)->toBeFalse();

    $auditLog = AuditLog::query()->where('action', 'user.settings.privacy_updated')->sole();

    expect($auditLog->actor_user_id)->toBe($user->id)
        ->and($auditLog->metadata['changes']['public_profile']['old'])->toBeTrue()
        ->and($auditLog->metadata['changes']['public_profile']['new'])->toBeFalse()
        ->and($auditLog->metadata['changes']['public_characters']['old'])->toBeTrue()
        ->and($auditLog->metadata['changes']['public_characters']['new'])->toBeFalse();

    $event = NotificationEvent::query()->where('type', 'user.settings.privacy_updated')->sole();

    expect($event->category)->toBe(NotificationCategory::ACCOUNT_CHARACTER_UPDATES)
        ->and($event->is_mandatory)->toBeTrue()
        ->and($event->actor_user_id)->toBe($user->id)
        ->and($event->subject_type)->toBe(User::class)
        ->and($event->subject_id)->toBe($user->id)
        ->and($event->title_key)->toBe('notifications.user.settings.privacy_updated.title')
        ->and($event->body_key)->toBe('notifications.user.settings.privacy_updated.body')
        ->and($event->action_url)->toBe(route('settings'))
        ->and($event->message_params['changed_setting_label_keys'])->toBe([
            'settings.privacy.profile_visibility',
            'settings.privacy.show_character_data',
        ]);

    $userNotification = UserNotification::query()->where('notification_event_id', $event->id)->sole();

    expect($userNotification->user_id)->toBe($user->id);
});
