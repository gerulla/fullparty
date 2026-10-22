<?php

use App\Models\Character;
use App\Models\FeaturedGroup;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use App\Support\Groups\GroupDiscoveryBadgePalette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function attachAdditionalMembers(Group $group, int $count): void
{
    User::factory()->count($count)->create()->each(function (User $member) use ($group): void {
        GroupMembership::query()->create([
            'group_id' => $group->id,
            'user_id' => $member->id,
            'role' => GroupMembership::ROLE_MEMBER,
            'joined_at' => now(),
        ]);
    });
}

it('returns the initial discovery results when the query is empty', function () {
    $user = User::factory()->create();

    Group::factory()->open()->withMember($user)->create([
        'name' => 'Joined Group',
        'slug' => 'joingrp',
        'created_at' => now()->subDay(),
    ]);

    Group::factory()->hidden()->create([
        'name' => 'Hidden Group',
        'slug' => 'hiddengp',
    ]);

    $groups = Group::factory()->count(16)->open()->create()->values();

    $groups->each(function (Group $group, int $index): void {
        $group->owner->update([
            'name' => "Owner {$index}",
            'avatar_url' => "https://example.com/avatar-{$index}.png",
        ]);
        Character::factory()->primary()->create([
            'user_id' => $group->owner_id,
            'name' => "Character {$index}",
            'avatar_url' => "https://example.com/character-avatar-{$index}.png",
        ]);

        $group->forceFill([
            'name' => "Search Group {$index}",
            'slug' => "srchgrp{$index}",
            'description' => "Discovery group {$index}",
            'primary_focuses' => ['progression'],
            'experience_expectation' => 'mixed',
            'voice_expectation' => 'preferred',
            'preferred_languages' => ['en'],
            'tags' => ["Tag {$index}"],
            'created_at' => now()->subMinutes(16 - $index),
        ])->save();
    });

    $response = $this->actingAs($user)
        ->getJson(route('groups.search'));

    $response
        ->assertOk()
        ->assertJsonCount(15, 'data')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('meta.per_page', 15)
        ->assertJsonPath('meta.total', 17)
        ->assertJsonPath('data.0.name', 'Search Group 15')
        ->assertJsonPath('data.0.owner.name', 'Character 15')
        ->assertJsonPath('data.0.owner.avatar_url', 'https://example.com/character-avatar-15.png')
        ->assertJsonPath('data.0.badge_meta.tags.0.color', app(GroupDiscoveryBadgePalette::class)->tagColor('Tag 15'))
        ->assertJsonMissing(['name' => 'Hidden Group']);

    $this->get(route('groups.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Groups/Index')
            ->has('discoverGroups.data', 15)
            ->where('discoverGroups.meta.per_page', 15)
            ->where('discoverGroups.meta.last_page', 2)
            ->where('discoverGroups.data.0.name', 'Search Group 15')
        );

    $this->getJson(route('groups.search', ['page' => 2]))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('data.0.name', 'Search Group 0')
        ->assertJsonPath('data.1.name', 'Joined Group');
});

it('paginates filtered discovery results using the requested grid page size', function (int $perPage) {
    $user = User::factory()->create();
    Group::factory()->count(19)->open()->create([
        'name' => 'Matching Group',
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);
    Group::factory()->open()->create(['name' => 'Different Group']);
    Group::factory()->hidden()->create(['name' => 'Matching Group']);

    $firstPage = $this->actingAs($user)
        ->getJson(route('groups.search', ['query' => 'Matching', 'per_page' => $perPage]))
        ->assertOk()
        ->assertJsonCount($perPage, 'data')
        ->assertJsonPath('meta.per_page', $perPage)
        ->assertJsonPath('meta.total', 19)
        ->assertJsonPath('meta.last_page', (int) ceil(19 / $perPage));

    $secondPage = $this->getJson(route('groups.search', [
        'query' => 'Matching',
        'per_page' => $perPage,
        'page' => 2,
    ]))
        ->assertOk()
        ->assertJsonCount(min($perPage, 19 - $perPage), 'data')
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.per_page', $perPage);

    expect(array_intersect(
        array_column($firstPage->json('data'), 'id'),
        array_column($secondPage->json('data'), 'id'),
    ))->toBeEmpty();
})->with([3, 6, 9, 12, 15, 18]);

it('rejects invalid discovery page sizes', function (mixed $perPage) {
    $this->actingAs(User::factory()->create())
        ->getJson(route('groups.search', ['per_page' => $perPage]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
})->with([0, -1, 61, 1.5, 'invalid', [[3]]]);

it('uses the owner primary character in the initial discovery page payload', function () {
    $user = User::factory()->create();
    $group = Group::factory()->open()->create([
        'name' => 'Initial Discovery Group',
        'slug' => 'initdisc',
    ]);

    $group->owner->update([
        'name' => 'Fallback Owner Name',
        'avatar_url' => 'https://example.com/fallback-owner.png',
    ]);

    Character::factory()->primary()->create([
        'user_id' => $group->owner_id,
        'name' => 'Owner Main Character',
        'avatar_url' => 'https://example.com/owner-main-character.png',
    ]);

    $this->actingAs($user)
        ->get(route('groups.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Groups/Index')
            ->where('discoverGroups.data.0.owner.name', 'Owner Main Character')
            ->where('discoverGroups.data.0.owner.avatar_url', 'https://example.com/owner-main-character.png')
        );
});

it('renders group embed meta when discovery opens a specific group', function () {
    $user = User::factory()->create();
    Group::factory()->open()->create([
        'name' => 'FTEL',
        'slug' => 'ftel',
        'description' => 'Forked Tower exploration linkshell.',
        'profile_picture_url' => '/storage/groups/ftel-profile.webp',
        'banner_image_url' => '/storage/groups/ftel-banner.webp',
    ]);

    $response = $this->actingAs($user)
        ->get(route('groups.index', ['group' => 'ftel']));

    $response
        ->assertOk()
        ->assertSee('<meta property="og:title" content="FTEL - FullParty.gg">', false)
        ->assertSee('<meta property="og:image" content="http://fullparty.test/storage/groups/embeds/ftel-', false)
        ->assertDontSee('<meta property="og:image" content="http://fullparty.test/storage/groups/ftel-banner.webp">', false)
        ->assertDontSee('<meta property="og:image" content="http://fullparty.test/storage/groups/ftel-profile.webp">', false);

    expect(substr_count($response->getContent(), '<meta property="og:image"'))->toBe(1);
});

it('shares the sidebar group quick links as my and joined buckets', function () {
    $user = User::factory()->create();

    $ownedGroup = Group::factory()->open()->create([
        'owner_id' => $user->id,
        'name' => 'Owned Sidebar Group',
        'slug' => 'ownedsb',
    ]);

    $adminGroup = Group::factory()->open()->create([
        'name' => 'Admin Sidebar Group',
        'slug' => 'adminsb',
    ]);
    $adminGroup->memberships()->create([
        'user_id' => $user->id,
        'role' => GroupMembership::ROLE_ADMIN,
        'joined_at' => now(),
    ]);

    $memberGroup = Group::factory()->open()->create([
        'name' => 'Member Sidebar Group',
        'slug' => 'membersb',
    ]);
    $memberGroup->memberships()->create([
        'user_id' => $user->id,
        'role' => GroupMembership::ROLE_MEMBER,
        'joined_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('groups.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Groups/Index')
            ->where('navigation.group_quick_links.my.0.slug', 'adminsb')
            ->where('navigation.group_quick_links.my.1.slug', 'ownedsb')
            ->where('navigation.group_quick_links.joined.0.slug', 'membersb')
        );
});

it('includes groups the current user already belongs to in discovery results', function () {
    $user = User::factory()->create();

    Group::factory()->open()->withMember($user)->create([
        'name' => 'Joined Group',
        'slug' => 'joingrp',
        'group_type' => Group::TYPE_STATIC,
    ]);

    $this->actingAs($user)
        ->getJson(route('groups.search', [
            'group_type' => Group::TYPE_STATIC,
        ]))
        ->assertOk()
        ->assertJsonFragment([
            'name' => 'Joined Group',
            'slug' => 'joingrp',
        ]);
});

it('returns curated featured groups before active fallback groups', function () {
    Cache::flush();

    $user = User::factory()->create();
    $curated = Group::factory()->open()->create([
        'name' => 'Curated Group',
        'slug' => 'curated1',
        'description' => 'Ready for launch featuring.',
        'banner_image_url' => '/groups/curated.jpg',
        'primary_focuses' => ['progression'],
        'preferred_languages' => ['en'],
    ]);
    $fallback = Group::factory()->open()->create([
        'name' => 'Fallback Group',
        'slug' => 'fallback',
        'description' => 'Active fallback option.',
        'banner_image_url' => '/groups/fallback.jpg',
        'primary_focuses' => ['farming'],
        'preferred_languages' => ['de'],
    ]);
    $hiddenCurated = Group::factory()->hidden()->create([
        'name' => 'Hidden Curated Group',
        'slug' => 'hiddenfg',
        'description' => 'Should not show.',
        'banner_image_url' => '/groups/hidden.jpg',
    ]);
    $incomplete = Group::factory()->open()->create([
        'name' => 'No Banner Group',
        'slug' => 'nobanner',
        'description' => 'Should not show in featured.',
        'banner_image_url' => null,
    ]);

    FeaturedGroup::factory()->create([
        'group_id' => $curated->id,
        'priority' => 25,
    ]);
    FeaturedGroup::factory()->create([
        'group_id' => $hiddenCurated->id,
        'priority' => 100,
    ]);

    $this->actingAs($user)
        ->getJson(route('groups.featured'))
        ->assertOk()
        ->assertJsonPath('data.0.slug', 'curated1')
        ->assertJsonFragment(['slug' => 'fallback'])
        ->assertJsonMissing(['slug' => 'hiddenfg'])
        ->assertJsonMissing(['slug' => 'nobanner']);

    expect($incomplete->exists)->toBeTrue();
});

it('lets admins curate featured groups', function () {
    Cache::flush();

    $admin = User::factory()->create(['is_admin' => true]);
    $group = Group::factory()->open()->create([
        'name' => 'Admin Featured Group',
        'slug' => 'adminftr',
        'description' => 'Eligible admin curated group.',
        'banner_image_url' => '/groups/admin-featured.jpg',
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.featured-groups.index'))
        ->assertForbidden();

    $this->actingAs($admin)
        ->get(route('admin.featured-groups.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/FeaturedGroups')
            ->has('groupOptions', 1)
            ->where('groupOptions.0.value', $group->id)
        );

    $this->actingAs($admin)
        ->post(route('admin.featured-groups.store'), [
            'group_id' => $group->id,
            'priority' => 42,
            'starts_at' => null,
            'ends_at' => null,
            'internal_note' => 'Launch partner.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'featured_group_created');

    $featuredGroup = FeaturedGroup::query()->sole();

    expect($featuredGroup->group_id)->toBe($group->id)
        ->and($featuredGroup->priority)->toBe(42)
        ->and($featuredGroup->internal_note)->toBe('Launch partner.');

    $this->actingAs($admin)
        ->put(route('admin.featured-groups.update', $featuredGroup), [
            'group_id' => $group->id,
            'priority' => 7,
            'starts_at' => null,
            'ends_at' => null,
            'internal_note' => null,
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'featured_group_updated');

    expect($featuredGroup->fresh()->priority)->toBe(7);

    $this->actingAs($admin)
        ->delete(route('admin.featured-groups.destroy', $featuredGroup))
        ->assertRedirect()
        ->assertSessionHas('success', 'featured_group_deleted');

    expect(FeaturedGroup::query()->count())->toBe(0);
});

it('rejects hidden groups from featured curation', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $group = Group::factory()->hidden()->create([
        'description' => 'Hidden groups are not eligible.',
        'banner_image_url' => '/groups/hidden.jpg',
    ]);

    $this->actingAs($admin)
        ->post(route('admin.featured-groups.store'), [
            'group_id' => $group->id,
            'priority' => 1,
            'starts_at' => null,
            'ends_at' => null,
            'internal_note' => null,
        ])
        ->assertSessionHasErrors('group_id');
});

it('applies discovery filters to the group search results', function () {
    $user = User::factory()->create();

    $matchingGroup = Group::factory()->inviteOnly()->create([
        'name' => 'Night Prog',
        'slug' => 'nightpro',
        'group_type' => Group::TYPE_STATIC,
        'datacenter' => 'Light',
        'primary_focuses' => ['progression'],
        'experience_expectation' => 'hardcore',
        'voice_expectation' => 'required',
        'preferred_languages' => ['en'],
        'tags' => ['Late Night', 'Week 1'],
        'active_days' => ['fri'],
    ]);

    attachAdditionalMembers($matchingGroup, 49);

    Group::factory()->inviteOnly()->create([
        'name' => 'Wrong Region',
        'slug' => 'wrongreg',
        'group_type' => Group::TYPE_STATIC,
        'datacenter' => 'Aether',
        'primary_focuses' => ['progression'],
        'experience_expectation' => 'hardcore',
        'voice_expectation' => 'required',
        'preferred_languages' => ['en'],
        'tags' => ['Late Night'],
        'active_days' => ['fri'],
    ]);

    Group::factory()->inviteOnly()->create([
        'name' => 'Wrong Focus',
        'slug' => 'wrongfoc',
        'group_type' => Group::TYPE_STATIC,
        'datacenter' => 'Light',
        'primary_focuses' => ['maps'],
        'experience_expectation' => 'hardcore',
        'voice_expectation' => 'required',
        'preferred_languages' => ['en'],
        'tags' => ['Late Night'],
        'active_days' => ['fri'],
    ]);

    Group::factory()->open()->create([
        'name' => 'Wrong Method',
        'slug' => 'wrongmet',
        'group_type' => Group::TYPE_STATIC,
        'datacenter' => 'Light',
        'primary_focuses' => ['progression'],
        'experience_expectation' => 'hardcore',
        'voice_expectation' => 'required',
        'preferred_languages' => ['en'],
        'tags' => ['Late Night'],
        'active_days' => ['fri'],
    ]);

    $this->actingAs($user)
        ->getJson(route('groups.search', [
            'group_type' => Group::TYPE_STATIC,
            'join_mode' => Group::JOIN_MODE_INVITE_ONLY,
            'experience_expectation' => 'hardcore',
            'region' => 'EU',
            'size' => '50',
            'sort_by' => 'member_count_desc',
            'primary_focuses' => ['progression'],
            'voice_expectation' => 'required',
            'preferred_languages' => ['en'],
            'active_days' => ['fri'],
            'extra_tags' => 'late',
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.name', 'Night Prog')
        ->assertJsonPath('data.0.region', 'EU')
        ->assertJsonPath('data.0.stats.member_count', 50);
});

it('rejects oversized discovery search input payloads', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('groups.search', [
            'query' => str_repeat('a', 256),
            'extra_tags' => str_repeat('b', 256),
            'preferred_languages' => ['en', 'de', 'fr', 'ja', 'en'],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'query',
            'extra_tags',
            'preferred_languages',
        ]);
});
