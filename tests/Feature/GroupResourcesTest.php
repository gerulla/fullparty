<?php

use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->group = Group::factory()->create();
    $this->group->features()->update(['resource_hub_enabled' => true]);
});

it('lets group members open the resources reader', function (string $role) {
    $user = $role === GroupMembership::ROLE_OWNER ? $this->group->owner : User::factory()->create();
    $this->group->memberships()->firstOrCreate(['user_id' => $user->id], [
        'role' => $role,
        'joined_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('groups.dashboard.resources.index', $this->group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Groups/Resources/Index')
            ->where('group.slug', $this->group->slug)
            ->where('group.current_user_role', $role)
            ->where('group.features.resource_hub_enabled', true)
            ->where('group.permissions.can_view_members', true)
            ->where('group.permissions.can_manage_members', $role !== GroupMembership::ROLE_MEMBER)
            ->where('resources.total', 1)->where('resource.is_home', true)
        );
})->with(['owner', 'admin', 'moderator', 'member']);

it('lets group managers open resource management', function (string $role) {
    $user = $role === GroupMembership::ROLE_OWNER ? $this->group->owner : User::factory()->create();
    $this->group->memberships()->firstOrCreate(['user_id' => $user->id], [
        'role' => $role,
        'joined_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('groups.dashboard.resources.manage', $this->group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Groups/Resources/Manage')
            ->where('group.features.resource_hub_enabled', true)
            ->where('group.permissions.can_manage_members', true)
            ->where('resources.total', 1)->where('resources.data.0.is_home', true)
        );
})->with(['owner', 'admin', 'moderator']);

it('does not let ordinary members manage resources', function () {
    $member = User::factory()->create();
    $this->group->memberships()->create([
        'user_id' => $member->id,
        'role' => GroupMembership::ROLE_MEMBER,
        'joined_at' => now(),
    ]);

    $this->actingAs($member)
        ->get(route('groups.dashboard.resources.manage', $this->group))
        ->assertForbidden();
});

it('blocks both resource pages when the feature is disabled', function (string $routeName) {
    $this->group->features()->update(['resource_hub_enabled' => false]);

    $this->actingAs($this->group->owner)
        ->get(route($routeName, $this->group))
        ->assertNotFound();
})->with(['groups.dashboard.resources.index', 'groups.dashboard.resources.manage']);

it('requires authentication for both resource pages', function (string $routeName) {
    $this->get(route($routeName, $this->group))
        ->assertRedirect(route('login'));
})->with(['groups.dashboard.resources.index', 'groups.dashboard.resources.manage']);

it('does not expose resource pages to nonmembers of a visible group', function (string $routeName) {
    $this->actingAs(User::factory()->create())
        ->get(route($routeName, $this->group))
        ->assertRedirect(route('groups.index'));
})->with(['groups.dashboard.resources.index', 'groups.dashboard.resources.manage']);

it('hides resource pages of hidden groups from nonmembers', function (string $routeName) {
    $this->group->update(['is_visible' => false]);

    $this->actingAs(User::factory()->create())
        ->get(route($routeName, $this->group))
        ->assertNotFound();
})->with(['groups.dashboard.resources.index', 'groups.dashboard.resources.manage']);

it('persists the resources toggle and gates both pages after disabling it', function () {
    $this->actingAs($this->group->owner)
        ->from(route('groups.dashboard.settings', $this->group))
        ->put(route('groups.dashboard.settings.update', $this->group), [
            'name' => $this->group->name,
            'description' => $this->group->description,
            'discord_invite_url' => $this->group->discord_invite_url,
            'datacenter' => $this->group->datacenter,
            'join_mode' => $this->group->join_mode,
            'is_visible' => $this->group->is_visible,
            'features' => ['resource_hub_enabled' => false],
        ])
        ->assertRedirect(route('groups.dashboard.settings', $this->group))
        ->assertSessionHasNoErrors();

    expect($this->group->fresh()->featureEnabled('resource_hub_enabled'))->toBeFalse()
        ->and($this->group->fresh()->featureEnabled('statistics_enabled'))->toBeTrue();

    $this->get(route('groups.dashboard.resources.index', $this->group))->assertNotFound();
    $this->get(route('groups.dashboard.resources.manage', $this->group))->assertNotFound();
});
