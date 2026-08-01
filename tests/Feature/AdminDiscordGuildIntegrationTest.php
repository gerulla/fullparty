<?php

use App\Models\AuditLog;
use App\Models\DiscordGuildIntegration;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shows active group Discord links to site admins only', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $regularUser = User::factory()->create();
    $group = Group::factory()->create([
        'name' => 'Linked Group',
        'slug' => 'linked',
    ]);
    DiscordGuildIntegration::query()->create([
        'group_id' => $group->id,
        'discord_guild_id' => '123456789012345678',
        'name' => 'Linked Guild',
        'guild_installed_at' => now(),
    ]);
    DiscordGuildIntegration::query()->create([
        'discord_guild_id' => '223456789012345678',
        'name' => 'Unlinked Guild',
        'guild_installed_at' => now(),
    ]);

    $this->actingAs($regularUser)
        ->get(route('admin.discord-guild-links.index'))
        ->assertForbidden();

    $this->actingAs($admin)
        ->get(route('admin.discord-guild-links.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/DiscordGuildIntegrations')
            ->has('links', 1)
            ->where('links.0.discord_guild_id', '123456789012345678')
            ->where('links.0.group.name', 'Linked Group')
            ->where('links.0.group.slug', 'linked'));
});

it('lets site admins force unlink a group Discord server without removing its installation', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $regularUser = User::factory()->create();
    $group = Group::factory()->create([
        'discord_link_token_hash' => hash('sha256', 'PENDING-TOKEN'),
        'discord_link_token_expires_at' => now()->addMinutes(10),
    ]);
    $integration = DiscordGuildIntegration::query()->create([
        'group_id' => $group->id,
        'discord_guild_id' => '323456789012345678',
        'name' => 'Managed Guild',
        'guild_installed_at' => now(),
    ]);

    $this->actingAs($regularUser)
        ->delete(route('admin.discord-guild-links.destroy', $integration))
        ->assertForbidden();

    expect($integration->fresh()->group_id)->toBe($group->id);

    $this->actingAs($admin)
        ->delete(route('admin.discord-guild-links.destroy', $integration))
        ->assertRedirect()
        ->assertSessionHas('success', 'discord_guild_force_unlinked');

    expect($integration->fresh())->not->toBeNull()
        ->and($integration->fresh()->group_id)->toBeNull()
        ->and($integration->fresh()->removed_at)->toBeNull()
        ->and($group->fresh()->discord_link_token_hash)->toBeNull()
        ->and($group->fresh()->discord_link_token_expires_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'group.discord_guild.force_unlinked')->exists())->toBeTrue();
});
