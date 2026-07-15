<?php

use App\Models\Group;
use App\Models\GroupInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('allows open community groups to be joined directly and keeps a permanent slug invite', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
        'group_type' => Group::TYPE_COMMUNITY,
    ]);

    expect($group->join_mode)->toBe(Group::JOIN_MODE_OPEN)
        ->and($group->systemInvite?->token)->toBe($group->slug);

    $this->actingAs($viewer)
        ->from(route('groups.index'))
        ->post(route('groups.join', $group))
        ->assertRedirect(route('groups.dashboard', $group));

    expect($group->memberships()->where('user_id', $viewer->id)->exists())->toBeTrue();
});

it('redirects root invite badges to the localized invite page', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
        'slug' => 'ftel',
        'group_type' => Group::TYPE_COMMUNITY,
    ]);

    expect($group->systemInvite?->token)->toBe('ftel');

    $this->get('/ftel')
        ->assertRedirect('/en/invite/ftel');
});

it('does not redirect unknown root invite badges', function () {
    $this->get('/notreal')
        ->assertNotFound();
});

it('does not allow direct joins for invite-only or application-based groups', function (string $groupType, string $joinMode) {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $group = Group::factory()->create([
        'owner_id' => $owner->id,
        'group_type' => $groupType,
        'join_mode' => $joinMode,
    ]);

    expect($group->invites()->where('is_system', true)->exists())->toBeFalse();

    $this->actingAs($viewer)
        ->from(route('groups.index'))
        ->post(route('groups.join', $group))
        ->assertRedirect(route('groups.index'))
        ->assertSessionHasErrors('error');

    expect($group->memberships()->where('user_id', $viewer->id)->exists())->toBeFalse();
})->with([
    'community invite-only' => [Group::TYPE_COMMUNITY, Group::JOIN_MODE_INVITE_ONLY],
    'community application-based' => [Group::TYPE_COMMUNITY, Group::JOIN_MODE_APPLICATION],
    'static invite-only' => [Group::TYPE_STATIC, Group::JOIN_MODE_INVITE_ONLY],
    'static application-based' => [Group::TYPE_STATIC, Group::JOIN_MODE_APPLICATION],
]);

it('allows generated invites for static groups', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $group = Group::factory()->create([
        'owner_id' => $owner->id,
        'group_type' => Group::TYPE_STATIC,
        'join_mode' => Group::JOIN_MODE_INVITE_ONLY,
    ]);

    $this->actingAs($owner)
        ->get(route('groups.dashboard.settings', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('group.group_type', Group::TYPE_STATIC)
            ->where('group.join_mode', Group::JOIN_MODE_INVITE_ONLY)
            ->where('group.permissions.can_manage_invites', true)
            ->has('group.invites', 0)
        );

    $this->actingAs($owner)
        ->from(route('groups.dashboard.settings', $group))
        ->post(route('groups.invites.store', $group), [
            'max_uses' => null,
            'expires_at' => null,
        ])
        ->assertRedirect(route('groups.dashboard.settings', $group))
        ->assertSessionDoesntHaveErrors();

    $invite = $group->invites()->where('is_system', false)->firstOrFail();

    $this->get(route('groups.invites.show', $invite->token))
        ->assertOk();

    $this->actingAs($viewer)
        ->post(route('groups.invites.accept', $invite->token))
        ->assertRedirect(route('groups.dashboard', $group));

    expect($group->memberships()->where('user_id', $viewer->id)->exists())->toBeTrue()
        ->and($invite->fresh()->uses)->toBe(1);
});

it('renders server-side embed meta for group invite links', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->create([
        'owner_id' => $owner->id,
        'name' => 'Storm Keepers',
        'slug' => 'storm-keepers',
        'description' => null,
        'datacenter' => 'Light',
        'profile_picture_url' => '/storage/groups/storm-profile.webp',
        'banner_image_url' => '/storage/groups/storm-banner.webp',
    ]);
    $invite = GroupInvite::query()->create([
        'group_id' => $group->id,
        'created_by' => $owner->id,
        'token' => 'stormtoken',
        'is_system' => false,
    ]);

    $response = $this->get(route('groups.invites.show', $invite->token));

    $response
        ->assertOk()
        ->assertSee('<meta property="og:title" content="Join Storm Keepers - FullParty.gg">', false)
        ->assertSee('<meta property="og:description" content="You&#039;ve been invited to join Storm Keepers, an FFXIV group on Light.">', false)
        ->assertSee('<meta property="og:image" content="http://fullparty.test/storage/groups/embeds/storm-keepers-', false)
        ->assertDontSee('<meta property="og:image" content="http://fullparty.test/storage/groups/storm-banner.webp">', false)
        ->assertDontSee('<meta property="og:image" content="http://fullparty.test/storage/groups/storm-profile.webp">', false)
        ->assertDontSee('<meta property="og:image" content="http://fullparty.test/landing.png">', false);

    expect(substr_count($response->getContent(), '<meta property="og:image"'))->toBe(1);
});

it('returns users to an invitation after signing in', function () {
    $owner = User::factory()->create();
    $user = User::factory()->create(['password' => bcrypt('password')]);
    $group = Group::factory()->create(['owner_id' => $owner->id]);
    $invite = GroupInvite::query()->create([
        'group_id' => $group->id,
        'created_by' => $owner->id,
        'token' => 'returnhere',
        'is_system' => false,
    ]);
    $inviteUrl = route('groups.invites.show', $invite->token);

    $this->get(route('login', ['invite' => $invite->token]))
        ->assertOk()
        ->assertSessionHas('url.intended', $inviteUrl);

    $this->post(route('login.store'), [
        'login' => $user->email,
        'password' => 'password',
    ])->assertRedirect($inviteUrl);
});

it('allows generated invites for application-based groups without creating a permanent slug invite', function (string $groupType) {
    $owner = User::factory()->create();
    $group = Group::factory()->applicationBased()->create([
        'owner_id' => $owner->id,
        'group_type' => $groupType,
    ]);

    expect($group->invites()->where('is_system', true)->exists())->toBeFalse();

    $this->actingAs($owner)
        ->from(route('groups.dashboard.settings', $group))
        ->post(route('groups.invites.store', $group), [
            'max_uses' => null,
            'expires_at' => null,
        ])
        ->assertRedirect(route('groups.dashboard.settings', $group))
        ->assertSessionDoesntHaveErrors();

    expect($group->invites()->where('is_system', false)->count())->toBe(1);
})->with([
    'community' => [Group::TYPE_COMMUNITY],
    'static' => [Group::TYPE_STATIC],
]);

it('rejects invite max uses above the supported limit', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
        'group_type' => Group::TYPE_COMMUNITY,
    ]);

    $this->actingAs($owner)
        ->from(route('groups.dashboard.settings', $group))
        ->post(route('groups.invites.store', $group), [
            'max_uses' => 100000,
            'expires_at' => null,
        ])
        ->assertRedirect(route('groups.dashboard.settings', $group))
        ->assertSessionHasErrors(['max_uses']);

    expect($group->invites()->count())->toBe(1);
});

it('rejects invite max uses values that contain non-digit characters', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
        'group_type' => Group::TYPE_COMMUNITY,
    ]);

    $this->actingAs($owner)
        ->from(route('groups.dashboard.settings', $group))
        ->post(route('groups.invites.store', $group), [
            'max_uses' => '1e3',
            'expires_at' => null,
        ])
        ->assertRedirect(route('groups.dashboard.settings', $group))
        ->assertSessionHasErrors(['max_uses']);

    expect($group->invites()->count())->toBe(1);
});
