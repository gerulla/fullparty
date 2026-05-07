<?php

use App\Jobs\SendNotificationEmailDeliveryJob;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\AccountCharacterNotificationService;
use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\NotificationChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function createAccountCharacterNotificationActivity(User $owner): Activity
{
    $group = \App\Models\Group::factory()->public()->create([
        'owner_id' => $owner->id,
    ]);

    $type = ActivityType::factory()->create([
        'created_by_user_id' => $owner->id,
    ]);

    $version = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $type->id,
        'published_by_user_id' => $owner->id,
        'application_schema' => [],
    ]);

    $type->update([
        'current_published_version_id' => $version->id,
    ]);

    return Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $owner->id,
        'organized_by_character_id' => null,
        'status' => Activity::STATUS_PLANNED,
        'needs_application' => true,
        'allow_guest_applications' => true,
        'is_public' => true,
    ]);
}

it('creates in app and off site notifications when a new social account is linked', function () {
    Queue::fake();

    $user = User::factory()->create([
        'account_character_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);

    SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => NotificationChannel::DISCORD,
        'provider_user_id' => 'discord-account-123',
        'provider_name' => 'Linked Discord',
        'provider_email' => $user->email,
    ]);

    app(AccountCharacterNotificationService::class)->notifySocialAccountLinked($user->fresh('socialAccounts'), 'google', $user);

    $event = NotificationEvent::query()->where('type', 'user.social_account.linked')->sole();

    expect($event->category)->toBe(NotificationCategory::ACCOUNT_CHARACTER_UPDATES)
        ->and($event->message_params['provider'])->toBe('Google');

    $notification = UserNotification::query()->where('notification_event_id', $event->id)->sole();

    expect($notification->user_id)->toBe($user->id);

    $emailDelivery = NotificationDelivery::query()
        ->where('notification_event_id', $event->id)
        ->where('channel', NotificationChannel::EMAIL)
        ->sole();

    $discordDelivery = NotificationDelivery::query()
        ->where('notification_event_id', $event->id)
        ->where('channel', NotificationChannel::DISCORD)
        ->sole();

    expect($emailDelivery->status)->toBe(NotificationDelivery::STATUS_PENDING)
        ->and($discordDelivery->status)->toBe(NotificationDelivery::STATUS_SKIPPED)
        ->and($discordDelivery->status_reason)->toBe('discord_transport_unavailable');

    Queue::assertPushed(SendNotificationEmailDeliveryJob::class, 1);
});

it('unlinks a social account and creates in app and off site notifications', function () {
    Queue::fake();

    $user = User::factory()->create([
        'password' => 'password',
        'account_character_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);

    $discordAccount = SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => NotificationChannel::DISCORD,
        'provider_user_id' => 'discord-linked-123',
        'provider_name' => 'Linked Discord',
        'provider_email' => $user->email,
    ]);

    $googleAccount = SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'google-linked-123',
        'provider_name' => 'Linked Google',
        'provider_email' => $user->email,
    ]);

    $this->actingAs($user)
        ->delete(route('settings.social-accounts.destroy', $googleAccount))
        ->assertRedirect(route('settings'));

    expect(SocialAccount::query()->whereKey($googleAccount->id)->exists())->toBeFalse()
        ->and(SocialAccount::query()->whereKey($discordAccount->id)->exists())->toBeTrue();

    $event = NotificationEvent::query()->where('type', 'user.social_account.unlinked')->sole();

    expect($event->message_params['provider'])->toBe('Google');

    expect(UserNotification::query()->where('notification_event_id', $event->id)->sole()->user_id)
        ->toBe($user->id);

    $emailDelivery = NotificationDelivery::query()
        ->where('notification_event_id', $event->id)
        ->where('channel', NotificationChannel::EMAIL)
        ->sole();

    $discordDelivery = NotificationDelivery::query()
        ->where('notification_event_id', $event->id)
        ->where('channel', NotificationChannel::DISCORD)
        ->sole();

    expect($emailDelivery->status)->toBe(NotificationDelivery::STATUS_PENDING)
        ->and($discordDelivery->status)->toBe(NotificationDelivery::STATUS_SKIPPED)
        ->and($discordDelivery->status_reason)->toBe('discord_transport_unavailable');

    Queue::assertPushed(SendNotificationEmailDeliveryJob::class, 1);
});

it('does not allow unlinking the last social account when the user has no password', function () {
    $user = User::factory()->create([
        'password' => null,
        'account_character_notifications' => true,
    ]);

    $discordAccount = SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => NotificationChannel::DISCORD,
        'provider_user_id' => 'discord-only-123',
        'provider_name' => 'Only Discord',
        'provider_email' => $user->email,
    ]);

    $this->actingAs($user)
        ->delete(route('settings.social-accounts.destroy', $discordAccount))
        ->assertRedirect(route('settings'))
        ->assertSessionHasErrors(['error']);

    expect(SocialAccount::query()->whereKey($discordAccount->id)->exists())->toBeTrue()
        ->and(NotificationEvent::query()->where('type', 'user.social_account.unlinked')->doesntExist())->toBeTrue();
});

it('creates in app and off site notifications when a character is added', function () {
    Queue::fake();

    $user = User::factory()->create([
        'account_character_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);

    SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => NotificationChannel::DISCORD,
        'provider_user_id' => 'discord-character-123',
        'provider_name' => 'Linked Discord',
        'provider_email' => $user->email,
    ]);

    $character = Character::factory()->create([
        'user_id' => $user->id,
        'verified_at' => now(),
        'name' => 'Signal Star',
        'world' => 'Twintania',
        'datacenter' => 'Light',
    ]);

    app(AccountCharacterNotificationService::class)->notifyCharacterAdded($character, 'xivauth', $user);

    $event = NotificationEvent::query()->where('type', 'characters.added')->sole();

    expect($event->message_params['character'])->toBe('Signal Star')
        ->and($event->message_params['method'])->toBe('XIVAuth');

    expect(UserNotification::query()->where('notification_event_id', $event->id)->sole()->user_id)
        ->toBe($user->id);

    $emailDelivery = NotificationDelivery::query()
        ->where('notification_event_id', $event->id)
        ->where('channel', NotificationChannel::EMAIL)
        ->sole();

    $discordDelivery = NotificationDelivery::query()
        ->where('notification_event_id', $event->id)
        ->where('channel', NotificationChannel::DISCORD)
        ->sole();

    expect($emailDelivery->status)->toBe(NotificationDelivery::STATUS_PENDING)
        ->and($discordDelivery->status)->toBe(NotificationDelivery::STATUS_SKIPPED)
        ->and($discordDelivery->status_reason)->toBe('discord_transport_unavailable');

    Queue::assertPushed(SendNotificationEmailDeliveryJob::class, 1);
});

it('creates an in app notification when the primary character is changed', function () {
    Queue::fake();

    $user = User::factory()->create([
        'account_character_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);

    SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => NotificationChannel::DISCORD,
        'provider_user_id' => 'discord-primary-123',
        'provider_name' => 'Linked Discord',
        'provider_email' => $user->email,
    ]);

    $firstCharacter = Character::factory()->create([
        'user_id' => $user->id,
        'is_primary' => true,
        'verified_at' => now(),
        'name' => 'First Star',
    ]);

    $secondCharacter = Character::factory()->create([
        'user_id' => $user->id,
        'is_primary' => false,
        'verified_at' => now(),
        'name' => 'Second Star',
    ]);

    $this->actingAs($user)
        ->post(route('characters.make-primary', $secondCharacter))
        ->assertRedirect();

    expect((bool) $firstCharacter->fresh()->is_primary)->toBeFalse()
        ->and((bool) $secondCharacter->fresh()->is_primary)->toBeTrue();

    $event = NotificationEvent::query()->where('type', 'characters.primary_changed')->sole();

    expect($event->message_params['character'])->toBe('Second Star')
        ->and(UserNotification::query()->where('notification_event_id', $event->id)->sole()->user_id)->toBe($user->id);

    $emailDelivery = NotificationDelivery::query()
        ->where('notification_event_id', $event->id)
        ->where('channel', NotificationChannel::EMAIL)
        ->sole();

    $discordDelivery = NotificationDelivery::query()
        ->where('notification_event_id', $event->id)
        ->where('channel', NotificationChannel::DISCORD)
        ->sole();

    expect($emailDelivery->status)->toBe(NotificationDelivery::STATUS_PENDING)
        ->and($discordDelivery->status)->toBe(NotificationDelivery::STATUS_SKIPPED)
        ->and($discordDelivery->status_reason)->toBe('discord_transport_unavailable');

    Queue::assertPushed(SendNotificationEmailDeliveryJob::class, 1);
});

it('unclaims a character and promotes another one to primary without deleting the record', function () {
    Queue::fake();

    $user = User::factory()->create([
        'account_character_notifications' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);

    SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => NotificationChannel::DISCORD,
        'provider_user_id' => 'discord-unclaim-123',
        'provider_name' => 'Linked Discord',
        'provider_email' => $user->email,
    ]);

    $firstCharacter = Character::factory()->create([
        'user_id' => $user->id,
        'is_primary' => true,
        'verified_at' => now(),
        'name' => 'Old Primary',
    ]);

    $secondCharacter = Character::factory()->create([
        'user_id' => $user->id,
        'is_primary' => false,
        'verified_at' => now(),
        'name' => 'New Primary',
    ]);

    $this->actingAs($user)
        ->delete(route('characters.destroy', $firstCharacter))
        ->assertRedirect();

    expect(Character::query()->whereKey($firstCharacter->id)->exists())->toBeTrue()
        ->and($firstCharacter->fresh()->user_id)->toBeNull()
        ->and($firstCharacter->fresh()->verified_at)->toBeNull()
        ->and($firstCharacter->fresh()->token)->not->toBeNull()
        ->and((bool) $firstCharacter->fresh()->is_primary)->toBeFalse()
        ->and((bool) $secondCharacter->fresh()->is_primary)->toBeTrue();

    $event = NotificationEvent::query()->where('type', 'characters.unclaimed')->sole();

    expect($event->message_params['character'])->toBe('Old Primary')
        ->and(UserNotification::query()->where('notification_event_id', $event->id)->sole()->user_id)->toBe($user->id);

    $emailDelivery = NotificationDelivery::query()
        ->where('notification_event_id', $event->id)
        ->where('channel', NotificationChannel::EMAIL)
        ->sole();

    $discordDelivery = NotificationDelivery::query()
        ->where('notification_event_id', $event->id)
        ->where('channel', NotificationChannel::DISCORD)
        ->sole();

    expect($emailDelivery->status)->toBe(NotificationDelivery::STATUS_PENDING)
        ->and($discordDelivery->status)->toBe(NotificationDelivery::STATUS_SKIPPED)
        ->and($discordDelivery->status_reason)->toBe('discord_transport_unavailable');

    Queue::assertPushed(SendNotificationEmailDeliveryJob::class, 1);
});

it('unclaims a character while preserving linked application history', function () {
    $user = User::factory()->create();

    $character = Character::factory()->create([
        'user_id' => $user->id,
        'is_primary' => true,
        'verified_at' => now(),
        'name' => 'History Bound',
    ]);

    $activity = createAccountCharacterNotificationActivity($user);

    ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
    ]);

    $this->actingAs($user)
        ->delete(route('characters.destroy', $character))
        ->assertRedirect();

    expect(Character::query()->whereKey($character->id)->exists())->toBeTrue()
        ->and($character->fresh()->user_id)->toBeNull()
        ->and($character->fresh()->verified_at)->toBeNull()
        ->and($character->fresh()->token)->not->toBeNull()
        ->and($character->fresh()->token)->toStartWith('FP-')
        ->and($character->fresh()->expires_at)->not->toBeNull()
        ->and($activity->applications()->sole()->selected_character_id)->toBe($character->id)
        ->and($activity->applications()->sole()->user_id)->toBe($user->id);
});
