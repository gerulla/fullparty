<?php

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('lists only the authenticated users groups as homepage options', function () {
    $user = User::factory()->create();
    $memberGroup = Group::factory()->withMember($user)->create(['name' => 'My Group']);
    Group::factory()->create(['name' => 'Other Group']);

    $this->actingAs($user)
        ->get(route('settings'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Settings/Index')
            ->has('homepageGroups', 1)
            ->where('homepageGroups.0.id', $memberGroup->id)
            ->where('homepageGroups.0.name', 'My Group')
        );
});

it('persists a group homepage override for a current member', function () {
    $user = User::factory()->create();
    $group = Group::factory()->withMember($user)->create();

    $this->actingAs($user)
        ->patchJson(route('settings.homepage'), [
            'homepage_group_id' => $group->id,
        ])
        ->assertOk()
        ->assertJsonPath('homepage_group_id', $group->id);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'homepage_group_id' => $group->id,
    ]);
});

it('rejects a homepage override for a group the user is not in', function () {
    $user = User::factory()->create();
    $group = Group::factory()->create();

    $this->actingAs($user)
        ->patchJson(route('settings.homepage'), [
            'homepage_group_id' => $group->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('homepage_group_id');
});

it('clears the homepage override when the user leaves the group', function () {
    $user = User::factory()->create();
    $group = Group::factory()->withMember($user)->create();
    $user->update(['homepage_group_id' => $group->id]);

    $this->actingAs($user)
        ->post(route('groups.leave', $group))
        ->assertRedirect();

    expect($user->fresh()->homepage_group_id)->toBeNull();
});

it('allows the user to return Home to their profile page', function () {
    $user = User::factory()->create();
    $group = Group::factory()->withMember($user)->create();
    $user->update(['homepage_group_id' => $group->id]);

    $this->actingAs($user)
        ->patchJson(route('settings.homepage'), [
            'homepage_group_id' => null,
        ])
        ->assertOk()
        ->assertJsonPath('homepage_group_id', null);

    expect($user->fresh()->homepage_group_id)->toBeNull();
});
