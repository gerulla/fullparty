<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlotAssignment;
use App\Models\AuditLog;
use App\Models\Character;
use App\Models\DiscordUserIntegration;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\NotificationEvent;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('anonymizes the account while preserving history-bearing records', function () {
    $user = User::factory()->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'public_profile' => true,
        'public_characters' => true,
        'email_notifications' => true,
        'discord_notifications' => true,
    ]);

    $group = Group::factory()->create();

    $group->memberships()->create([
        'user_id' => $user->id,
        'role' => GroupMembership::ROLE_MEMBER,
        'joined_at' => now(),
    ]);

    SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => 'discord',
        'provider_user_id' => 'discord-123',
    ]);
    DiscordUserIntegration::query()->create([
        'user_id' => $user->id,
        'discord_user_id' => 'discord-123',
        'user_app_installed_at' => now(),
    ]);

    DB::table('sessions')->insert([
        'id' => 'session-delete-test',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'payload' => 'payload',
        'last_activity' => now()->timestamp,
    ]);

    DB::table('password_reset_tokens')->insert([
        'email' => $user->email,
        'token' => 'reset-token',
        'created_at' => now(),
    ]);

    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
        'name' => 'History Character',
    ]);

    $application = ActivityApplication::factory()->create([
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
    ]);

    $this->actingAs($user)
        ->delete(route('settings.account.destroy'))
        ->assertRedirect('/');

    $this->assertGuest();

    $user->refresh();
    $character->refresh();
    $application->refresh();

    expect($user->name)->toBe('Deleted User #'.$user->id)
        ->and($user->email)->not->toBe('test@example.com')
        ->and($user->avatar_url)->toBeNull()
        ->and($user->public_profile)->toBeFalse()
        ->and($user->public_characters)->toBeFalse()
        ->and($user->email_notifications)->toBeFalse()
        ->and($user->discord_notifications)->toBeFalse();

    expect($character->user_id)->toBeNull()
        ->and($character->is_primary)->toBeFalsy()
        ->and($character->verified_at)->toBeNull()
        ->and($character->token)->toBeNull()
        ->and($character->expires_at)->toBeNull()
        ->and($application->user_id)->toBe($user->id)
        ->and($application->selected_character_id)->toBe($character->id)
        ->and($application->status)->toBe(ActivityApplication::STATUS_WITHDRAWN);

    expect($group->memberships()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(SocialAccount::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DiscordUserIntegration::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('password_reset_tokens')->where('email', 'test@example.com')->exists())->toBeFalse();

    $auditLog = AuditLog::query()->where('action', 'user.account.deleted')->sole();

    expect($auditLog->actor_user_id)->toBe($user->id)
        ->and($auditLog->scope_id)->toBe($user->id);
});

it('clears upcoming assigned slots and notifies group moderators when deleting an account', function () {
    $owner = User::factory()->create(['name' => 'Group Owner']);
    $admin = User::factory()->create();
    $moderator = User::factory()->create();
    $member = User::factory()->create();
    $user = User::factory()->create(['name' => 'Retiring Raider']);

    $group = Group::factory()->create([
        'owner_id' => $owner->id,
        'name' => 'Storm Keepers',
    ]);

    foreach ([
        $admin->id => GroupMembership::ROLE_ADMIN,
        $moderator->id => GroupMembership::ROLE_MODERATOR,
        $member->id => GroupMembership::ROLE_MEMBER,
        $user->id => GroupMembership::ROLE_MEMBER,
    ] as $userId => $role) {
        $group->memberships()->create([
            'user_id' => $userId,
            'role' => $role,
            'joined_at' => now(),
        ]);
    }

    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
        'name' => 'Retiring Hero',
    ]);

    $upcomingActivity = Activity::factory()->create([
        'group_id' => $group->id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_SCHEDULED,
        'starts_at' => now()->addDay(),
        'title' => 'Future Prog Night',
    ]);

    $historicalActivity = Activity::factory()->complete()->create([
        'group_id' => $group->id,
        'organized_by_user_id' => $owner->id,
        'starts_at' => now()->subDays(2),
        'title' => 'Past Clear Night',
    ]);

    $upcomingApplication = ActivityApplication::factory()->approved($owner)->create([
        'activity_id' => $upcomingActivity->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
    ]);

    $historicalApplication = ActivityApplication::factory()->approved($owner)->create([
        'activity_id' => $historicalActivity->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
    ]);

    $upcomingSlot = $upcomingActivity->slots()->firstOrFail();
    $upcomingSlot->update([
        'assigned_character_id' => $character->id,
        'assigned_by_user_id' => $owner->id,
        'is_host' => true,
        'is_raid_leader' => true,
        'application_review_required_application_id' => $upcomingApplication->id,
        'application_review_required_at' => now(),
    ]);
    $upcomingFieldValue = $upcomingSlot->fieldValues()->create([
        'field_key' => 'delete_test_raid_position',
        'field_label' => ['en' => 'Raid Position'],
        'field_type' => 'select',
        'source' => 'static_options',
        'value' => ['key' => 'mt', 'label' => ['en' => 'Main Tank']],
    ]);

    ActivitySlotAssignment::query()->create([
        'activity_id' => $upcomingActivity->id,
        'group_id' => $group->id,
        'activity_slot_id' => $upcomingSlot->id,
        'character_id' => $character->id,
        'application_id' => $upcomingApplication->id,
        'field_values_snapshot' => [],
        'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
        'assigned_at' => now()->subHour(),
        'assigned_by_user_id' => $owner->id,
    ]);

    $historicalSlot = $historicalActivity->slots()->firstOrFail();
    $historicalSlot->update([
        'assigned_character_id' => $character->id,
        'assigned_by_user_id' => $owner->id,
        'is_host' => true,
        'is_raid_leader' => true,
    ]);

    $this->actingAs($user)
        ->delete(route('settings.account.destroy'))
        ->assertRedirect('/');

    expect($upcomingApplication->fresh()->status)->toBe(ActivityApplication::STATUS_WITHDRAWN)
        ->and($historicalApplication->fresh()->status)->toBe(ActivityApplication::STATUS_APPROVED)
        ->and($upcomingSlot->fresh()->assigned_character_id)->toBeNull()
        ->and($upcomingSlot->fresh()->assigned_by_user_id)->toBeNull()
        ->and($upcomingSlot->fresh()->is_host)->toBeFalse()
        ->and($upcomingSlot->fresh()->is_raid_leader)->toBeFalse()
        ->and($upcomingSlot->fresh()->application_review_required_application_id)->toBeNull()
        ->and($upcomingSlot->fresh()->application_review_required_at)->toBeNull()
        ->and($upcomingFieldValue->fresh()->value)->toBeNull()
        ->and($historicalSlot->fresh()->assigned_character_id)->toBe($character->id)
        ->and($historicalSlot->fresh()->is_host)->toBeTrue()
        ->and($historicalSlot->fresh()->is_raid_leader)->toBeTrue();

    expect(ActivitySlotAssignment::query()
        ->where('activity_id', $upcomingActivity->id)
        ->where('character_id', $character->id)
        ->whereNull('ended_at')
        ->exists())->toBeFalse();

    $event = NotificationEvent::query()
        ->where('type', 'groups.member_upcoming_assignments_cleared')
        ->sole();

    expect($event->message_params['user'])->toBe('Retiring Raider')
        ->and($event->message_params['group'])->toBe('Storm Keepers')
        ->and($event->message_params['runs'])->toBe('Future Prog Night')
        ->and($event->payload['runs'][0]['title'])->toBe('Future Prog Night')
        ->and($event->action_url)->toBe(route('groups.dashboard.activities.index', $group));

    $recipientIds = UserNotification::query()
        ->where('notification_event_id', $event->id)
        ->pluck('user_id')
        ->sort()
        ->values()
        ->all();

    expect($recipientIds)->toBe(collect([$owner->id, $admin->id, $moderator->id])->sort()->values()->all());
});

it('blocks account deletion while the user still owns groups', function () {
    $user = User::factory()->create();
    Group::factory()->create([
        'owner_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->from(route('settings'))
        ->delete(route('settings.account.destroy'))
        ->assertRedirect(route('settings'))
        ->assertSessionHasErrors([
            'error' => 'account_delete_group_owner',
        ]);

    $user->refresh();

    expect($user->name)->not->toBe('Deleted User #'.$user->id);
    expect(AuditLog::query()->where('action', 'user.account.deleted')->exists())->toBeFalse();
});
