<?php

use App\Models\RaidPosition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders raid positions on the system data admin page', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    RaidPosition::create([
        'key' => 'off_tank',
        'name' => 'Off Tank',
        'sort_order' => 20,
        'is_active' => true,
    ]);
    RaidPosition::create([
        'key' => 'main_tank',
        'name' => 'Main Tank',
        'sort_order' => 10,
        'is_active' => true,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.system-data'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/SystemData')
            ->has('raidPositions', 2)
            ->where('raidPositions.0.key', 'main_tank')
            ->where('raidPositions.1.key', 'off_tank')
        );
});

it('allows admins to manage raid positions', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $this->actingAs($admin)
        ->post(route('admin.raid-positions.store'), [
            'key' => 'main_tank',
            'name' => 'Main Tank',
            'icon_url' => null,
            'sort_order' => 10,
            'is_active' => true,
        ])
        ->assertRedirect();

    $raidPosition = RaidPosition::query()->firstOrFail();

    $this->assertDatabaseHas('raid_positions', [
        'key' => 'main_tank',
        'name' => 'Main Tank',
        'sort_order' => 10,
        'is_active' => true,
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'admin.raid_position.created',
        'subject_type' => RaidPosition::class,
        'subject_id' => $raidPosition->id,
    ]);

    $this->actingAs($admin)
        ->put(route('admin.raid-positions.update', $raidPosition), [
            'key' => 'main_tank',
            'name' => 'Main Tank Updated',
            'icon_url' => null,
            'sort_order' => 5,
            'is_active' => false,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('raid_positions', [
        'id' => $raidPosition->id,
        'name' => 'Main Tank Updated',
        'sort_order' => 5,
        'is_active' => false,
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'admin.raid_position.updated',
        'subject_type' => RaidPosition::class,
        'subject_id' => $raidPosition->id,
    ]);

    $this->actingAs($admin)
        ->delete(route('admin.raid-positions.destroy', $raidPosition))
        ->assertRedirect();

    $this->assertDatabaseMissing('raid_positions', [
        'id' => $raidPosition->id,
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'admin.raid_position.deleted',
        'subject_type' => RaidPosition::class,
        'subject_id' => $raidPosition->id,
    ]);
});

it('prevents non-admin users from managing raid positions', function () {
    $user = User::factory()->create([
        'is_admin' => false,
    ]);

    $this->actingAs($user)
        ->get(route('admin.system-data'))
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('admin.raid-positions.store'), [
            'key' => 'main_tank',
            'name' => 'Main Tank',
            'sort_order' => 10,
            'is_active' => true,
        ])
        ->assertForbidden();
});
