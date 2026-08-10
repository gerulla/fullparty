<?php

use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupQuickCreateShortcut;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shows the existing calendar quick-create times as the default shortcuts', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->create(['owner_id' => $owner->id]);

    expect($group->quickCreateShortcuts()->exists())->toBeFalse();

    $this->actingAs($owner)
        ->get(route('groups.dashboard.shortcuts.index', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Groups/Settings/Shortcuts')
            ->has('shortcuts', 3)
            ->where('shortcuts.0.time', '18:00')
            ->where('shortcuts.0.time_mode', GroupQuickCreateShortcut::TIME_MODE_SERVER)
            ->where('shortcuts.1.time', '20:00')
            ->where('shortcuts.2.time', '22:00'));

    $this->get(route('groups.dashboard.activities.index', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('quickCreateShortcuts', 3)
            ->where('quickCreateShortcuts.0.time', '18:00')
            ->where('quickCreateShortcuts.0.time_mode', GroupQuickCreateShortcut::TIME_MODE_SERVER));
});

it('allows group admins to replace and reorder quick-create shortcuts', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $group = Group::factory()->create(['owner_id' => $owner->id]);
    $group->memberships()->create([
        'user_id' => $admin->id,
        'role' => GroupMembership::ROLE_ADMIN,
        'joined_at' => now(),
    ]);

    $this->actingAs($admin)
        ->put(route('groups.dashboard.shortcuts.update', $group), [
            'shortcuts' => [
                ['time' => '19:30', 'time_mode' => GroupQuickCreateShortcut::TIME_MODE_LOCAL],
                ['time' => '21:00', 'time_mode' => GroupQuickCreateShortcut::TIME_MODE_SERVER],
            ],
        ])
        ->assertRedirect();

    expect($group->quickCreateShortcuts()->count())->toBe(2);

    $first = $group->quickCreateShortcuts()->firstOrFail();
    $second = $group->quickCreateShortcuts()->skip(1)->firstOrFail();

    expect($first->time_of_day)->toBe('19:30')
        ->and($first->time_mode)->toBe(GroupQuickCreateShortcut::TIME_MODE_LOCAL)
        ->and($first->sort_order)->toBe(0)
        ->and($second->time_of_day)->toBe('21:00')
        ->and($second->time_mode)->toBe(GroupQuickCreateShortcut::TIME_MODE_SERVER)
        ->and($second->sort_order)->toBe(1);

    $this->get(route('groups.dashboard.activities.index', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('quickCreateShortcuts', 2)
            ->where('quickCreateShortcuts.0.time', '19:30')
            ->where('quickCreateShortcuts.0.time_mode', GroupQuickCreateShortcut::TIME_MODE_LOCAL)
            ->where('quickCreateShortcuts.1.time', '21:00'));
});

it('forbids moderators from changing group shortcuts', function () {
    $owner = User::factory()->create();
    $moderator = User::factory()->create();
    $group = Group::factory()->create(['owner_id' => $owner->id]);
    $group->memberships()->create([
        'user_id' => $moderator->id,
        'role' => GroupMembership::ROLE_MODERATOR,
        'joined_at' => now(),
    ]);

    $this->actingAs($moderator)
        ->get(route('groups.dashboard.shortcuts.index', $group))
        ->assertForbidden();

    $this->put(route('groups.dashboard.shortcuts.update', $group), [
        'shortcuts' => [
            ['time' => '19:00', 'time_mode' => GroupQuickCreateShortcut::TIME_MODE_SERVER],
        ],
    ])->assertForbidden();
});

it('validates the shortcut limit and rejects exact duplicates', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->create(['owner_id' => $owner->id]);
    $shortcutsPage = route('groups.dashboard.shortcuts.index', $group);
    $updateRoute = route('groups.dashboard.shortcuts.update', $group);

    $this->actingAs($owner)
        ->from($shortcutsPage)
        ->put($updateRoute, [
            'shortcuts' => [
                ['time' => '18:00', 'time_mode' => 'server'],
                ['time' => '18:00', 'time_mode' => 'server'],
            ],
        ])
        ->assertRedirect($shortcutsPage)
        ->assertSessionHasErrors('shortcuts');

    $this->from($shortcutsPage)
        ->put($updateRoute, [
            'shortcuts' => [
                ['time' => '17:00', 'time_mode' => 'server'],
                ['time' => '18:00', 'time_mode' => 'server'],
                ['time' => '19:00', 'time_mode' => 'server'],
                ['time' => '20:00', 'time_mode' => 'server'],
                ['time' => '21:00', 'time_mode' => 'server'],
                ['time' => '22:00', 'time_mode' => 'server'],
            ],
        ])
        ->assertRedirect($shortcutsPage)
        ->assertSessionHasErrors('shortcuts');

    expect($group->quickCreateShortcuts()->exists())->toBeFalse();
});
