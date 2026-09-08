<?php

use App\Models\Activity;
use App\Models\Character;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('lets users add and remove an accessible group from my runs', function () {
    $user = User::factory()->create();
    $group = Group::factory()->open()->create();

    $this->actingAs($user)
        ->post(route('groups.run-list.store', $group))
        ->assertOk()
        ->assertJsonPath('is_in_my_runs', true);

    $this->assertDatabaseHas('user_run_list_groups', [
        'user_id' => $user->id,
        'group_id' => $group->id,
    ]);

    $this->get(route('groups.dashboard', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('group.is_in_my_runs', true));

    $this->delete(route('groups.run-list.destroy', $group))
        ->assertOk()
        ->assertJsonPath('is_in_my_runs', false);

    $this->assertDatabaseMissing('user_run_list_groups', [
        'user_id' => $user->id,
        'group_id' => $group->id,
    ]);
});

it('does not let users add an inaccessible group to my runs', function () {
    $user = User::factory()->create();
    $group = Group::factory()->inviteOnly()->create();

    $this->actingAs($user)
        ->post(route('groups.run-list.store', $group))
        ->assertNotFound();

    $this->assertDatabaseMissing('user_run_list_groups', [
        'user_id' => $user->id,
        'group_id' => $group->id,
    ]);
});

it('shows visible runs from selected groups with their host group metadata', function () {
    $user = User::factory()->create();
    $selectedGroup = Group::factory()->inviteOnly()->create(['name' => 'Selected Static']);
    $otherGroup = Group::factory()->open()->create(['name' => 'Other Group']);

    $selectedGroup->memberships()->create([
        'user_id' => $user->id,
        'role' => GroupMembership::ROLE_MEMBER,
        'joined_at' => now(),
    ]);

    $host = Character::factory()->create([
        'user_id' => $selectedGroup->owner_id, 'name' => 'Genesis Govette',
        'world' => 'Lich', 'datacenter' => 'Light', 'avatar_url' => '/host-avatar.png',
    ]);
    $scheduledRun = Activity::factory()->for($selectedGroup)->create([
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Selected Run',
        'organized_by_character_id' => $host->id,
        'datacenter' => 'Chaos',
    ]);

    Activity::factory()->for($selectedGroup)->create([
        'status' => Activity::STATUS_DRAFT,
        'title' => 'Hidden Draft',
    ]);

    Activity::factory()->for($otherGroup)->create([
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Unselected Run',
    ]);

    $user->runListGroups()->attach($selectedGroup->id);

    $this->actingAs($user)
        ->get(route('account.runs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Runs/MyRuns')
            ->has('groups', 1)
            ->where('groups.0.id', $selectedGroup->id)
            ->where('groups.0.slug', $selectedGroup->slug)
            ->has('activities', 1)
            ->where('activities.0.id', $scheduledRun->id)
            ->where('activities.0.organized_by_character.name', 'Genesis Govette')
            ->where('activities.0.organized_by_character.avatar_url', '/host-avatar.png')
            ->where('activities.0.organized_by_character.world', 'Lich')
            ->where('activities.0.organized_by_character.datacenter', 'Light')
            ->where('activities.0.group.id', $selectedGroup->id)
            ->where('activities.0.group.name', 'Selected Static')
            ->where('activities.0.group.slug', $selectedGroup->slug)
            ->where('activities.0.group.can_manage_activities', false));
});
