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

function validCreateGroupPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Static Full Party',
        'description' => 'A fixed roster for weekly clears.',
        'datacenter' => 'Light',
        'join_mode' => Group::JOIN_MODE_INVITE_ONLY,
        'is_visible' => true,
        'slug' => 'staticfp',
        'group_type' => Group::TYPE_STATIC,
        'primary_focuses' => ['progression'],
        'experience_expectation' => 'mixed',
        'voice_expectation' => 'preferred',
        'preferred_languages' => ['en'],
        'tags' => [],
    ], $overrides);
}

it('stores the selected group type when creating a group', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('groups.store'), validCreateGroupPayload())
        ->assertRedirect(route('groups.dashboard', 'staticfp'));

    $group = Group::query()->where('slug', 'staticfp')->firstOrFail();

    expect($group->group_type)->toBe(Group::TYPE_STATIC)
        ->and($group->join_mode)->toBe(Group::JOIN_MODE_INVITE_ONLY)
        ->and($group->is_visible)->toBeTrue()
        ->and($group->profile_picture_url)->toContain('/storage/groups/generated-profiles/staticfp.')
        ->and($group->banner_image_url)->toContain('/storage/groups/generated-banners/staticfp.')
        ->and($group->memberships()
            ->where('user_id', $user->id)
            ->where('role', GroupMembership::ROLE_OWNER)
            ->exists())->toBeTrue();
});

it('rejects unknown group types when creating a group', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('groups.index'))
        ->post(route('groups.store'), validCreateGroupPayload([
            'name' => 'Odd Group',
            'slug' => 'oddgroup',
            'join_mode' => Group::JOIN_MODE_OPEN,
            'group_type' => 'raid-team',
        ]))
        ->assertRedirect(route('groups.index'))
        ->assertSessionHasErrors('group_type');

    expect(Group::query()->where('slug', 'oddgroup')->exists())->toBeFalse();
});

it('rejects extra tags already represented by a structured group setting', function (string $tag) {
    $this->actingAs(User::factory()->create())
        ->post(route('groups.store'), validCreateGroupPayload(['tags' => [$tag]]))
        ->assertSessionHasErrors(['tags.0' => __('groups.common.validation.tag_covered_by_field')]);

    expect(Group::query()->where('slug', 'staticfp')->exists())->toBeFalse();
})->with(['English', ' EN ', 'Deutsch', 'Français', '日本語', 'Voice Preferred', 'voice-preferred', 'Progression', 'Reclears', 'Beginner Friendly', 'BEGINNER_FRIENDLY', 'Light', 'Static']);

it('accepts open join mode for static groups', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('groups.index'))
        ->post(route('groups.store'), validCreateGroupPayload([
            'name' => 'Open Static',
            'slug' => 'openstat',
            'group_type' => Group::TYPE_STATIC,
            'join_mode' => Group::JOIN_MODE_OPEN,
        ]))
        ->assertRedirect(route('groups.dashboard', 'openstat'))
        ->assertSessionDoesntHaveErrors();

    expect(Group::query()->where('slug', 'openstat')->firstOrFail()->join_mode)->toBe(Group::JOIN_MODE_OPEN);
});

it('rejects unsupported profile picture formats when creating a group', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('groups.index'))
        ->post(route('groups.store'), validCreateGroupPayload([
            'name' => 'Gif Group',
            'slug' => 'gifgroup',
            'group_type' => Group::TYPE_COMMUNITY,
            'profile_picture' => UploadedFile::fake()->create('animated.gif', 64, 'image/gif'),
        ]))
        ->assertRedirect(route('groups.index'))
        ->assertSessionHasErrors('profile_picture');

    expect(Group::query()->where('slug', 'gifgroup')->exists())->toBeFalse();
});

it('sanitizes group name and description when creating a group', function () {
    $user = User::factory()->create();
    $sanitizer = app(TextInputSanitizer::class);

    $rawName = "E\u{0301}quipe\u{00A0}\u{200B}Raid";
    $rawDescription = "Line\u{00A0}one\u{200B}\r\nSecond\u{202E} line\t";

    $this->actingAs($user)
        ->post(route('groups.store'), validCreateGroupPayload([
            'name' => $rawName,
            'description' => $rawDescription,
            'slug' => 'langraid',
            'group_type' => Group::TYPE_COMMUNITY,
        ]))
        ->assertRedirect(route('groups.dashboard', 'langraid'));

    $group = Group::query()->where('slug', 'langraid')->firstOrFail();

    expect($group->name)->toBe($sanitizer->sanitizeSingleLine($rawName))
        ->and($group->description)->toBe($sanitizer->sanitizeMultiline($rawDescription));
});

it('rejects group descriptions longer than the supported limit when creating a group', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('groups.index'))
        ->post(route('groups.store'), validCreateGroupPayload([
            'slug' => 'longdesc',
            'group_type' => Group::TYPE_COMMUNITY,
            'description' => str_repeat('a', GroupDetailsRequest::DESCRIPTION_MAX_LENGTH + 1),
        ]))
        ->assertRedirect(route('groups.index'))
        ->assertSessionHasErrors('description');
});

it('rejects non-discord invite links when creating a group', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('groups.index'))
        ->post(route('groups.store'), validCreateGroupPayload([
            'slug' => 'badlinkg',
            'group_type' => Group::TYPE_COMMUNITY,
            'discord_invite_url' => 'https://example.com/not-a-discord-invite',
        ]))
        ->assertRedirect(route('groups.index'))
        ->assertSessionHasErrors('discord_invite_url');
});

it('stores discovery metadata and exposes inferred region on the group dashboard settings page', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('groups.store'), validCreateGroupPayload([
            'name' => 'Chaos Prog',
            'description' => 'Late night progression group.',
            'join_mode' => Group::JOIN_MODE_OPEN,
            'slug' => 'chaospro',
            'group_type' => Group::TYPE_COMMUNITY,
            'banner_image' => UploadedFile::fake()->image('banner.png', 1500, 500),
            'primary_focuses' => ['progression', 'mount_farming'],
            'experience_expectation' => 'mixed',
            'voice_expectation' => 'preferred',
            'preferred_languages' => ['en', 'ja'],
            'tags' => ['Late Night', 'late night', 'Blind Prog'],
            'active_timezone' => 'Europe/London',
            'active_days' => ['fri', 'sat'],
            'active_start_time' => '19:00',
            'active_end_time' => '22:30',
        ]))
        ->assertRedirect(route('groups.dashboard', 'chaospro'));

    $group = Group::query()->where('slug', 'chaospro')->firstOrFail();

    expect($group->banner_image_url)->not->toBeNull()
        ->and($group->banner_image_url)->toContain('/storage/groups/')
        ->and($group->primary_focuses)->toBe(['progression', 'mount_farming'])
        ->and($group->experience_expectation)->toBe('mixed')
        ->and($group->voice_expectation)->toBe('preferred')
        ->and($group->preferred_languages)->toBe(['en', 'ja'])
        ->and($group->tags)->toBe(['Late Night', 'Blind Prog'])
        ->and($group->active_timezone)->toBe('Europe/London')
        ->and($group->active_days)->toBe(['fri', 'sat'])
        ->and($group->active_start_time)->toBe('19:00')
        ->and($group->active_end_time)->toBe('22:30')
        ->and($group->inferredRegion())->toBe('EU');

    $this->actingAs($user)
        ->get(route('groups.dashboard.settings', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('group.banner_image_url', $group->banner_image_url)
            ->where('group.region', 'EU')
            ->where('group.primary_focuses', ['progression', 'mount_farming'])
            ->where('group.experience_expectation', 'mixed')
            ->where('group.voice_expectation', 'preferred')
            ->where('group.preferred_languages', ['en', 'ja'])
            ->where('group.tags', ['Late Night', 'Blind Prog'])
            ->where('group.active_timezone', 'Europe/London')
            ->where('group.active_days', ['fri', 'sat'])
            ->where('group.active_start_time', '19:00')
            ->where('group.active_end_time', '22:30')
            ->where('group.badge_meta.primary_focuses.0.color', '#7A5AF8')
            ->where('group.badge_meta.experience_expectation.color', '#64748B')
            ->where('group.badge_meta.voice_expectation.color', '#6FA7E8')
            ->where('group.badge_meta.preferred_languages.0.color', '#4C7DFF')
            ->where('group.badge_meta.active_days.0.color', '#8B5CF6')
            ->where('group.badge_meta.region.color', '#38BDF8')
            ->where('group.badge_meta.tags.0.color', app(GroupDiscoveryBadgePalette::class)->tagColor('Late Night'))
        );
});

it('generates default group profile and banner images when none are uploaded', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('groups.store'), validCreateGroupPayload([
            'name' => 'Generated Assets',
            'slug' => 'genasset',
        ]))
        ->assertRedirect(route('groups.dashboard', 'genasset'));

    $group = Group::query()->where('slug', 'genasset')->firstOrFail();

    expect($group->profile_picture_url)->not->toBeNull()
        ->and($group->banner_image_url)->not->toBeNull()
        ->and($group->profile_picture_url)->toContain('/storage/groups/generated-profiles/genasset.')
        ->and($group->banner_image_url)->toContain('/storage/groups/generated-banners/genasset.');

    $profilePath = ltrim((string) parse_url($group->profile_picture_url, PHP_URL_PATH), '/');
    $bannerPath = ltrim((string) parse_url($group->banner_image_url, PHP_URL_PATH), '/');

    expect($profilePath)->toEndWith('.png')
        ->and($bannerPath)->toEndWith('.png')
        ->and($profilePath)->not->toEndWith('.svg')
        ->and($bannerPath)->not->toEndWith('.svg');

    Storage::disk('public')->assertExists(str_replace('storage/', '', $profilePath));
    Storage::disk('public')->assertExists(str_replace('storage/', '', $bannerPath));
});

it('requires a timezone and complete time pair when active schedule metadata is provided', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('groups.index'))
        ->post(route('groups.store'), validCreateGroupPayload([
            'name' => 'Schedule Group',
            'join_mode' => Group::JOIN_MODE_OPEN,
            'slug' => 'schedgrp',
            'group_type' => Group::TYPE_COMMUNITY,
            'active_days' => ['fri'],
            'active_start_time' => '19:00',
        ]))
        ->assertRedirect(route('groups.index'))
        ->assertSessionHasErrors(['active_end_time', 'active_timezone']);
});

it('allows overnight active schedule windows when creating a group', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('groups.store'), validCreateGroupPayload([
            'name' => 'Night Group',
            'slug' => 'nightgrp',
            'group_type' => Group::TYPE_COMMUNITY,
            'active_timezone' => 'Europe/London',
            'active_days' => ['fri', 'sat'],
            'active_start_time' => '22:00',
            'active_end_time' => '05:00',
        ]))
        ->assertRedirect(route('groups.dashboard', 'nightgrp'));

    $group = Group::query()->where('slug', 'nightgrp')->firstOrFail();

    expect($group->active_timezone)->toBe('Europe/London')
        ->and($group->active_days)->toBe(['fri', 'sat'])
        ->and($group->active_start_time)->toBe('22:00')
        ->and($group->active_end_time)->toBe('05:00');
});

it('stores activity window presets without changing their local hours or days', function (string $start, string $end, string $timezone, array $days) {
    Storage::fake('public');
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('groups.store'), validCreateGroupPayload([
            'active_timezone' => $timezone,
            'active_days' => $days,
            'active_start_time' => $start,
            'active_end_time' => $end,
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('groups.dashboard', 'staticfp'));

    $group = Group::query()->where('slug', 'staticfp')->firstOrFail();

    expect($group->active_timezone)->toBe($timezone)
        ->and($group->active_days)->toBe($days)
        ->and($group->active_start_time)->toBe($start)
        ->and($group->active_end_time)->toBe($end);
})->with([
    'all day, every day' => ['00:00', '23:59', 'UTC', ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']],
    'morning, weekends' => ['08:00', '12:00', 'Europe/London', ['sat', 'sun']],
    'custom midday, half-hour timezone' => ['12:00', '14:00', 'Asia/Kolkata', ['mon', 'wed']],
    'custom afternoon, quarter-hour timezone' => ['14:00', '18:00', 'Asia/Kathmandu', ['tue', 'thu']],
    'evening, daylight saving timezone' => ['18:00', '22:00', 'America/New_York', ['fri']],
    'late night, crossing midnight' => ['22:00', '03:00', 'Australia/Adelaide', ['sat', 'sun']],
    'custom early hours, beyond UTC plus twelve' => ['03:00', '08:00', 'Pacific/Kiritimati', ['sun']],
    'custom daytime slider window' => ['11:00', '18:00', 'Europe/London', ['mon']],
    'custom overnight slider window' => ['22:00', '05:00', 'Europe/London', ['fri']],
]);

it('allows the UTC minus twelve active timezone when creating a group', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('groups.store'), validCreateGroupPayload([
            'name' => 'Date Line Group',
            'slug' => 'dateline',
            'group_type' => Group::TYPE_COMMUNITY,
            'active_timezone' => 'Etc/GMT+12',
            'active_days' => ['fri'],
            'active_start_time' => '19:00',
            'active_end_time' => '23:00',
        ]))
        ->assertRedirect(route('groups.dashboard', 'dateline'));

    $group = Group::query()->where('slug', 'dateline')->firstOrFail();

    expect($group->active_timezone)->toBe('Etc/GMT+12');
});

it('requires the discovery and group fit fields when creating a group', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('groups.index'))
        ->post(route('groups.store'), validCreateGroupPayload([
            'slug' => 'nofitgrp',
            'primary_focuses' => [],
            'experience_expectation' => '',
            'voice_expectation' => '',
            'preferred_languages' => [],
        ]))
        ->assertRedirect(route('groups.index'))
        ->assertSessionHasErrors([
            'primary_focuses',
            'experience_expectation',
            'voice_expectation',
            'preferred_languages',
        ]);
});
