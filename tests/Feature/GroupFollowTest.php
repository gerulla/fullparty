<?php

use App\Models\AuditLog;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows a signed in user to follow and unfollow a public group', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->public()->create([
        'owner_id' => $owner->id,
    ]);
    $follower = User::factory()->create();

    $this->actingAs($follower)
        ->post(route('groups.follow', $group))
        ->assertRedirect();

    expect($group->fresh()->followers()->where('users.id', $follower->id)->exists())->toBeTrue()
        ->and((bool) $group->fresh()->followers()->where('users.id', $follower->id)->first()->pivot->notifications_enabled)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'group.followed')->where('scope_id', $group->id)->exists())->toBeTrue();

    $this->actingAs($follower)
        ->delete(route('groups.unfollow', $group))
        ->assertRedirect();

    expect($group->fresh()->followers()->where('users.id', $follower->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'group.unfollowed')->where('scope_id', $group->id)->exists())->toBeTrue();
});

it('auto follows members when they join and removes the follow when they leave', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->public()->create([
        'owner_id' => $owner->id,
    ]);
    $member = User::factory()->create();

    $this->actingAs($member)
        ->post(route('groups.join', $group))
        ->assertRedirect(route('groups.show', $group));

    expect($group->fresh()->followers()->where('users.id', $member->id)->exists())->toBeTrue();

    $this->actingAs($member)
        ->post(route('groups.leave', $group))
        ->assertRedirect(route('groups.index'));

    expect($group->fresh()->followers()->where('users.id', $member->id)->exists())->toBeFalse();
});

it('does not allow members to unfollow while they still belong to the group', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->public()->create([
        'owner_id' => $owner->id,
    ]);
    $member = User::factory()->create();

    $this->actingAs($member)
        ->post(route('groups.join', $group))
        ->assertRedirect();

    $this->actingAs($member)
        ->delete(route('groups.unfollow', $group))
        ->assertRedirect()
        ->assertSessionHasErrors(['error']);

    expect($group->fresh()->followers()->where('users.id', $member->id)->exists())->toBeTrue();
});

it('allows followed users and members to mute or re-enable group notifications', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->public()->create([
        'owner_id' => $owner->id,
    ]);
    $member = User::factory()->create();
    $follower = User::factory()->create();

    $this->actingAs($member)
        ->post(route('groups.join', $group))
        ->assertRedirect();

    $this->actingAs($follower)
        ->post(route('groups.follow', $group))
        ->assertRedirect();

    $this->actingAs($member)
        ->patch(route('groups.follow-notifications.update', $group), [
            'enabled' => false,
        ])
        ->assertRedirect();

    $this->actingAs($follower)
        ->patch(route('groups.follow-notifications.update', $group), [
            'enabled' => false,
        ])
        ->assertRedirect();

    expect((bool) $group->fresh()->followers()->where('users.id', $member->id)->first()->pivot->notifications_enabled)->toBeFalse()
        ->and((bool) $group->fresh()->followers()->where('users.id', $follower->id)->first()->pivot->notifications_enabled)->toBeFalse();

    $this->actingAs($member)
        ->patch(route('groups.follow-notifications.update', $group), [
            'enabled' => true,
        ])
        ->assertRedirect();

    expect((bool) $group->fresh()->followers()->where('users.id', $member->id)->first()->pivot->notifications_enabled)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'group.notifications.muted')->where('scope_id', $group->id)->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'group.notifications.enabled')->where('scope_id', $group->id)->exists())->toBeTrue();
});
