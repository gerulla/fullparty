<?php

use App\Http\Requests\GroupDetailsRequest;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use App\Support\Groups\GroupDiscoveryBadgePalette;
use App\Support\Input\TextInputSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('rejects unsupported profile picture formats when updating group settings', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->create([
        'owner_id' => $owner->id,
        'group_type' => Group::TYPE_COMMUNITY,
    ]);

    $this->actingAs($owner)
        ->from(route('groups.dashboard.settings', $group))
        ->put(route('groups.dashboard.settings.update', $group), [
            'name' => $group->name,
            'description' => $group->description,
            'discord_invite_url' => $group->discord_invite_url,
            'datacenter' => $group->datacenter,
            'join_mode' => $group->join_mode,
            'is_visible' => $group->is_visible,
            'profile_picture' => UploadedFile::fake()->create('animated.gif', 64, 'image/gif'),
        ])
        ->assertRedirect(route('groups.dashboard.settings', $group))
        ->assertSessionHasErrors('profile_picture');
});

it('sanitizes group name and description when updating group settings', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->create([
        'owner_id' => $owner->id,
        'group_type' => Group::TYPE_COMMUNITY,
    ]);
    $sanitizer = app(TextInputSanitizer::class);

    $rawName = "Gru\u{0308}ppe\u{00A0}\u{200B}Test";
    $rawDescription = "First\u{00A0}line\u{200B}\r\nSecond\u{202E} line\t";

    $this->actingAs($owner)
        ->put(route('groups.dashboard.settings.update', $group), [
            'name' => $rawName,
            'description' => $rawDescription,
            'discord_invite_url' => $group->discord_invite_url,
            'datacenter' => $group->datacenter,
            'join_mode' => $group->join_mode,
            'is_visible' => $group->is_visible,
        ])
        ->assertRedirect();

    $group->refresh();

    expect($group->name)->toBe($sanitizer->sanitizeSingleLine($rawName))
        ->and($group->description)->toBe($sanitizer->sanitizeMultiline($rawDescription));
});

it('rejects group descriptions longer than the supported limit when updating group settings', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->create([
        'owner_id' => $owner->id,
        'group_type' => Group::TYPE_COMMUNITY,
    ]);

    $this->actingAs($owner)
        ->from(route('groups.dashboard.settings', $group))
        ->put(route('groups.dashboard.settings.update', $group), [
            'name' => $group->name,
            'description' => str_repeat('a', GroupDetailsRequest::DESCRIPTION_MAX_LENGTH + 1),
            'discord_invite_url' => $group->discord_invite_url,
            'datacenter' => $group->datacenter,
            'join_mode' => $group->join_mode,
            'is_visible' => $group->is_visible,
        ])
        ->assertRedirect(route('groups.dashboard.settings', $group))
        ->assertSessionHasErrors('description');
});

it('rejects non-discord invite links when updating group settings', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->create([
        'owner_id' => $owner->id,
        'group_type' => Group::TYPE_COMMUNITY,
    ]);

    $this->actingAs($owner)
        ->from(route('groups.dashboard.settings', $group))
        ->put(route('groups.dashboard.settings.update', $group), [
            'name' => $group->name,
            'description' => $group->description,
            'discord_invite_url' => 'https://example.com/not-a-discord-invite',
            'datacenter' => $group->datacenter,
            'join_mode' => $group->join_mode,
            'is_visible' => $group->is_visible,
        ])
        ->assertRedirect(route('groups.dashboard.settings', $group))
        ->assertSessionHasErrors('discord_invite_url');
});

it('updates group profile and banner images from general settings', function () {
    Storage::fake('public');

    $owner = User::factory()->create();
    $group = Group::factory()->create([
        'owner_id' => $owner->id,
        'group_type' => Group::TYPE_COMMUNITY,
    ]);

    $this->actingAs($owner)
        ->put(route('groups.dashboard.settings.update', $group), [
            'name' => $group->name,
            'description' => $group->description,
            'discord_invite_url' => $group->discord_invite_url,
            'datacenter' => $group->datacenter,
            'join_mode' => $group->join_mode,
            'is_visible' => $group->is_visible,
            'profile_picture' => UploadedFile::fake()->image('profile.png', 256, 256),
            'banner_image' => UploadedFile::fake()->image('banner.png', 1500, 500),
        ])
        ->assertRedirect();

    $group->refresh();

    expect($group->profile_picture_url)->toContain('/storage/groups/')
        ->and($group->banner_image_url)->toContain('/storage/groups/');

    $profilePath = ltrim((string) parse_url($group->profile_picture_url, PHP_URL_PATH), '/');
    $bannerPath = ltrim((string) parse_url($group->banner_image_url, PHP_URL_PATH), '/');
    $storedProfilePath = str_replace('storage/', '', $profilePath);
    $storedBannerPath = str_replace('storage/', '', $bannerPath);

    expect($profilePath)->toEndWith('.webp')
        ->and($bannerPath)->toEndWith('.webp');

    Storage::disk('public')->assertExists($storedProfilePath);
    Storage::disk('public')->assertExists($storedBannerPath);

    $profileImageInfo = getimagesizefromstring(Storage::disk('public')->get($storedProfilePath));
    $bannerImageInfo = getimagesizefromstring(Storage::disk('public')->get($storedBannerPath));

    expect($profileImageInfo['mime'] ?? null)->toBe('image/webp')
        ->and($bannerImageInfo['mime'] ?? null)->toBe('image/webp');
});

it('allows admins to update general group settings but forbids moderators', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $moderator = User::factory()->create();
    $group = Group::factory()->create([
        'owner_id' => $owner->id,
        'group_type' => Group::TYPE_COMMUNITY,
        'name' => 'Original Group',
    ]);

    $group->memberships()->create([
        'user_id' => $admin->id,
        'role' => GroupMembership::ROLE_ADMIN,
        'joined_at' => now(),
    ]);
    $group->memberships()->create([
        'user_id' => $moderator->id,
        'role' => GroupMembership::ROLE_MODERATOR,
        'joined_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('groups.dashboard.settings', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('group.permissions.can_manage_group', false)
            ->where('group.permissions.can_update_group_settings', true)
        );

    $this->actingAs($admin)
        ->put(route('groups.dashboard.settings.update', $group), [
            'name' => 'Admin Updated Group',
            'description' => $group->description,
            'discord_invite_url' => $group->discord_invite_url,
            'datacenter' => $group->datacenter,
            'join_mode' => $group->join_mode,
            'is_visible' => $group->is_visible,
        ])
        ->assertRedirect();

    expect($group->fresh()->name)->toBe('Admin Updated Group');

    $this->actingAs($moderator)
        ->get(route('groups.dashboard.settings', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('group.permissions.can_manage_group', false)
            ->where('group.permissions.can_update_group_settings', false)
        );

    $this->actingAs($moderator)
        ->put(route('groups.dashboard.settings.update', $group), [
            'name' => 'Moderator Updated Group',
            'description' => $group->description,
            'discord_invite_url' => $group->discord_invite_url,
            'datacenter' => $group->datacenter,
            'join_mode' => $group->join_mode,
            'is_visible' => $group->is_visible,
        ])
        ->assertForbidden();

    expect($group->fresh()->name)->toBe('Admin Updated Group');
});

it('includes group feature settings when viewing settings', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->create([
        'owner_id' => $owner->id,
        'group_type' => Group::TYPE_COMMUNITY,
    ]);

    expect($group->features()->exists())->toBeTrue();

    $this->actingAs($owner)
        ->get(route('groups.dashboard.settings', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('group.features.availability_scheduler_enabled', false)
            ->where('group.features.statistics_enabled', true)
            ->where('group.features.leaderboard_enabled', true)
            ->where('group.features.calendar_sync_enabled', false)
            ->where('group.features.resource_hub_enabled', false)
        );
});

it('allows admins to update group feature settings', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $group = Group::factory()->create([
        'owner_id' => $owner->id,
        'group_type' => Group::TYPE_COMMUNITY,
    ]);
    $group->memberships()->create([
        'user_id' => $admin->id,
        'role' => GroupMembership::ROLE_ADMIN,
        'joined_at' => now(),
    ]);

    $this->actingAs($admin)
        ->put(route('groups.dashboard.settings.update', $group), [
            'name' => $group->name,
            'description' => $group->description,
            'discord_invite_url' => $group->discord_invite_url,
            'datacenter' => $group->datacenter,
            'join_mode' => $group->join_mode,
            'is_visible' => $group->is_visible,
            'features' => [
                'availability_scheduler_enabled' => true,
                'statistics_enabled' => false,
                'leaderboard_enabled' => true,
                'calendar_sync_enabled' => true,
                'resource_hub_enabled' => true,
            ],
        ])
        ->assertRedirect();

    $features = $group->fresh()->features;

    expect($features)->not->toBeNull()
        ->and($features->availability_scheduler_enabled)->toBeTrue()
        ->and($features->statistics_enabled)->toBeFalse()
        ->and($features->leaderboard_enabled)->toBeTrue()
        ->and($features->calendar_sync_enabled)->toBeTrue()
        ->and($features->resource_hub_enabled)->toBeTrue();
});

it('uses group feature toggles to gate statistics access', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->create([
        'owner_id' => $owner->id,
        'group_type' => Group::TYPE_COMMUNITY,
    ]);

    $this->actingAs($owner)
        ->get(route('groups.dashboard.statistics', $group))
        ->assertOk();

    $group->features()->update(['statistics_enabled' => false]);

    $this->actingAs($owner)
        ->get(route('groups.dashboard.statistics', $group))
        ->assertNotFound();

    $this->actingAs($owner)
        ->post(route('groups.dashboard.statistics.refresh', $group))
        ->assertNotFound();
});

it('uses group feature toggles to gate leaderboard access', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->create([
        'owner_id' => $owner->id,
        'group_type' => Group::TYPE_COMMUNITY,
    ]);

    $this->actingAs($owner)
        ->get(route('groups.dashboard.leaderboard', $group))
        ->assertOk();

    $group->features()->update(['leaderboard_enabled' => false]);

    $this->actingAs($owner)
        ->get(route('groups.dashboard.leaderboard', $group))
        ->assertNotFound();

    $this->actingAs($owner)
        ->post(route('groups.dashboard.leaderboard.refresh', $group))
        ->assertNotFound();
});

it('uses the leaderboard feature toggle to gate the legacy leaderboard', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->create([
        'owner_id' => $owner->id,
        'slug' => 'ftel',
        'group_type' => Group::TYPE_COMMUNITY,
    ]);

    $group->features()->update(['leaderboard_enabled' => false]);

    $this->actingAs($owner)
        ->get(route('groups.dashboard.legacy-leaderboard', $group))
        ->assertNotFound();
});

it('allows owners and admins to view the discovery settings page', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $moderator = User::factory()->create();
    $group = Group::factory()->create([
        'owner_id' => $owner->id,
        'group_type' => Group::TYPE_COMMUNITY,
    ]);

    $group->memberships()->create([
        'user_id' => $admin->id,
        'role' => GroupMembership::ROLE_ADMIN,
        'joined_at' => now(),
    ]);
    $group->memberships()->create([
        'user_id' => $moderator->id,
        'role' => GroupMembership::ROLE_MODERATOR,
        'joined_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('groups.dashboard.discovery-settings', $group))
        ->assertOk();

    $this->actingAs($admin)
        ->get(route('groups.dashboard.discovery-settings', $group))
        ->assertOk();

    $this->actingAs($moderator)
        ->get(route('groups.dashboard.discovery-settings', $group))
        ->assertForbidden();
});

it('stores discovery metadata when updating discovery settings', function () {
    Storage::fake('public');

    $owner = User::factory()->create();
    $group = Group::factory()->create([
        'owner_id' => $owner->id,
        'group_type' => Group::TYPE_COMMUNITY,
        'datacenter' => 'Aether',
    ]);

    $this->actingAs($owner)
        ->put(route('groups.dashboard.discovery-settings.update', $group), [
            'primary_focuses' => ['maps'],
            'experience_expectation' => 'casual',
            'voice_expectation' => 'optional',
            'preferred_languages' => ['de', 'fr'],
            'tags' => ['Weekend', 'weekend', 'Maps'],
            'active_timezone' => 'Europe/Berlin',
            'active_days' => ['sat', 'sun'],
            'active_start_time' => '18:00',
            'active_end_time' => '23:00',
        ])
        ->assertRedirect();

    $group->refresh();

    expect($group->datacenter)->toBe('Aether')
        ->and($group->primary_focuses)->toBe(['maps'])
        ->and($group->experience_expectation)->toBe('casual')
        ->and($group->voice_expectation)->toBe('optional')
        ->and($group->preferred_languages)->toBe(['de', 'fr'])
        ->and($group->tags)->toBe(['Weekend', 'Maps'])
        ->and($group->active_timezone)->toBe('Europe/Berlin')
        ->and($group->active_days)->toBe(['sat', 'sun'])
        ->and($group->active_start_time)->toBe('18:00')
        ->and($group->active_end_time)->toBe('23:00');

    $this->actingAs($owner)
        ->get(route('groups.dashboard.discovery-settings', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('group.region', 'NA')
            ->where('group.primary_focuses', ['maps'])
            ->where('group.experience_expectation', 'casual')
            ->where('group.voice_expectation', 'optional')
            ->where('group.preferred_languages', ['de', 'fr'])
            ->where('group.tags', ['Weekend', 'Maps'])
            ->where('group.active_timezone', 'Europe/Berlin')
            ->where('group.active_days', ['sat', 'sun'])
            ->where('group.active_start_time', '18:00')
            ->where('group.active_end_time', '23:00')
            ->where('group.badge_meta.primary_focuses.0.color', '#6366F1')
            ->where('group.badge_meta.experience_expectation.color', '#8CCB7A')
            ->where('group.badge_meta.voice_expectation.color', '#62C98F')
            ->where('group.badge_meta.preferred_languages.0.color', '#8B5CF6')
            ->where('group.badge_meta.active_days.0.color', '#A855F7')
            ->where('group.badge_meta.region.color', app(GroupDiscoveryBadgePalette::class)->badgeMetaForGroup($group)['region']['color'])
            ->where('group.badge_meta.tags.0.color', app(GroupDiscoveryBadgePalette::class)->tagColor('Weekend'))
        );
});

it('allows admins to update discovery settings but forbids moderators', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $moderator = User::factory()->create();
    $group = Group::factory()->create([
        'owner_id' => $owner->id,
        'group_type' => Group::TYPE_COMMUNITY,
    ]);

    $group->memberships()->create([
        'user_id' => $admin->id,
        'role' => GroupMembership::ROLE_ADMIN,
        'joined_at' => now(),
    ]);
    $group->memberships()->create([
        'user_id' => $moderator->id,
        'role' => GroupMembership::ROLE_MODERATOR,
        'joined_at' => now(),
    ]);

    $this->actingAs($admin)
        ->put(route('groups.dashboard.discovery-settings.update', $group), [
            'primary_focuses' => ['progression', 'reclears'],
            'experience_expectation' => 'mixed',
            'voice_expectation' => 'preferred',
            'preferred_languages' => ['en', 'ja'],
            'tags' => ['Weekend Focus'],
            'active_timezone' => 'Europe/London',
            'active_days' => ['fri'],
            'active_start_time' => '21:00',
            'active_end_time' => '01:00',
        ])
        ->assertRedirect();

    $group->refresh();

    expect($group->primary_focuses)->toBe(['progression', 'reclears'])
        ->and($group->active_end_time)->toBe('01:00');

    $this->actingAs($moderator)
        ->put(route('groups.dashboard.discovery-settings.update', $group), [
            'primary_focuses' => ['maps'],
        ])
        ->assertForbidden();
});

it('preserves existing discovery metadata when omitted from a settings update', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->create([
        'owner_id' => $owner->id,
        'group_type' => Group::TYPE_COMMUNITY,
        'primary_focuses' => ['progression'],
        'experience_expectation' => 'mixed',
        'voice_expectation' => 'preferred',
        'preferred_languages' => ['en', 'ja'],
        'tags' => ['Late Night'],
        'active_timezone' => 'Europe/London',
        'active_days' => ['fri'],
        'active_start_time' => '19:00',
        'active_end_time' => '22:00',
    ]);

    $this->actingAs($owner)
        ->put(route('groups.dashboard.settings.update', $group), [
            'name' => 'Updated Name',
            'description' => $group->description,
            'discord_invite_url' => $group->discord_invite_url,
            'datacenter' => $group->datacenter,
            'join_mode' => $group->join_mode,
            'is_visible' => $group->is_visible,
        ])
        ->assertRedirect();

    $group->refresh();

    expect($group->name)->toBe('Updated Name')
        ->and($group->primary_focuses)->toBe(['progression'])
        ->and($group->experience_expectation)->toBe('mixed')
        ->and($group->voice_expectation)->toBe('preferred')
        ->and($group->preferred_languages)->toBe(['en', 'ja'])
        ->and($group->tags)->toBe(['Late Night'])
        ->and($group->active_timezone)->toBe('Europe/London')
        ->and($group->active_days)->toBe(['fri'])
        ->and($group->active_start_time)->toBe('19:00')
        ->and($group->active_end_time)->toBe('22:00');
});

it('allows overnight active schedule windows when updating discovery settings', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->create([
        'owner_id' => $owner->id,
        'group_type' => Group::TYPE_COMMUNITY,
    ]);

    $this->actingAs($owner)
        ->put(route('groups.dashboard.discovery-settings.update', $group), [
            'active_timezone' => 'Europe/London',
            'active_days' => ['fri', 'sat'],
            'active_start_time' => '22:00',
            'active_end_time' => '05:00',
        ])
        ->assertRedirect();

    $group->refresh();

    expect($group->active_timezone)->toBe('Europe/London')
        ->and($group->active_days)->toBe(['fri', 'sat'])
        ->and($group->active_start_time)->toBe('22:00')
        ->and($group->active_end_time)->toBe('05:00');
});
