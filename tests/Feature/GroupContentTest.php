<?php

use App\Models\BozjaHolster;
use App\Models\BozjaItem;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\PhantomJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('allows moderators to open Delubrum Reginae content', function () {
    $owner = User::factory()->create();
    $moderator = User::factory()->create();
    $group = Group::factory()->create(['owner_id' => $owner->id]);

    $group->memberships()->create([
        'user_id' => $moderator->id,
        'role' => GroupMembership::ROLE_MODERATOR,
        'joined_at' => now(),
    ]);

    $holster = BozjaHolster::query()->create([
        'group_id' => $group->id,
        'name' => ['en' => 'Progression Holster'],
    ]);
    BozjaHolster::query()->create([
        'group_id' => Group::factory()->create()->id,
        'name' => ['en' => 'Another Group Holster'],
    ]);
    $item = BozjaItem::query()->create([
        'key' => 'lost-cure-test',
        'category' => 'lost_actions',
        'name' => ['en' => 'Lost Cure'],
        'classification' => 'lost_action',
        'cache_weight' => 3,
    ]);

    $this->actingAs($moderator)
        ->get(route('groups.dashboard.content.delubrum-reginae-savage', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Groups/Content/DelubrumReginaeSavage')
            ->where('group.slug', $group->slug)
            ->where('group.permissions.can_manage_members', true)
            ->has('holsters', 1)
            ->where('holsters.0.id', $holster->id)
            ->where('holsters.0.display_name', 'Progression Holster')
            ->where('holsters.0.name.en', 'Progression Holster')
            ->has('bozja_items', 1)
            ->where('bozja_items.0.id', $item->id)
            ->where('bozja_items.0.display_name', 'Lost Cure')
        );
});

it('allows moderators to create and update group holsters', function () {
    $owner = User::factory()->create();
    $moderator = User::factory()->create();
    $group = Group::factory()->create(['owner_id' => $owner->id]);
    $group->memberships()->create([
        'user_id' => $moderator->id,
        'role' => GroupMembership::ROLE_MODERATOR,
        'joined_at' => now(),
    ]);
    $item = BozjaItem::query()->create([
        'key' => 'test-essence',
        'category' => 'essences',
        'name' => ['en' => 'Test Essence'],
        'classification' => 'essence',
        'cache_weight' => 4,
    ]);

    $this->actingAs($moderator)
        ->postJson(route('groups.dashboard.content.delubrum-reginae-savage.holsters.store', $group), [
            'name' => ['en' => 'Custom Capacity'],
            'role' => 'healer',
            'max_capacity' => 50,
            'items' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('max_capacity');

    $createResponse = $this->actingAs($moderator)
        ->postJson(route('groups.dashboard.content.delubrum-reginae-savage.holsters.store', $group), [
            'name' => ['en' => 'Progression'],
            'role' => 'healer',
            'notes' => 'Bring these actions.',
            'guide' => '## Opener',
            'items' => [
                ['id' => $item->id, 'quantity' => 3],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.display_name', 'Progression')
        ->assertJsonPath('data.role', 'healer')
        ->assertJsonPath('data.is_default', false)
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.max_capacity', 99)
        ->assertJsonPath('data.capacity_used', 12)
        ->assertJsonPath('data.items.0.quantity', 3);

    $holster = BozjaHolster::query()->findOrFail($createResponse->json('data.id'));

    $this->actingAs($moderator)
        ->putJson(route('groups.dashboard.content.delubrum-reginae-savage.holsters.update', [
            'group' => $group,
            'bozjaHolster' => $holster,
        ]), [
            'name' => ['en' => 'Revised Progression'],
            'role' => 'tank',
            'notes' => null,
            'guide' => null,
            'items' => [
                ['id' => $item->id, 'quantity' => 4],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.display_name', 'Revised Progression')
        ->assertJsonPath('data.role', 'tank')
        ->assertJsonPath('data.capacity_used', 16);

    $this->assertDatabaseHas('bozja_holster_items', [
        'bozja_holster_id' => $holster->id,
        'bozja_item_id' => $item->id,
        'quantity' => 4,
    ]);

    $this->actingAs($moderator)
        ->patchJson(route('groups.dashboard.content.delubrum-reginae-savage.holsters.status.update', [
            'group' => $group,
            'bozjaHolster' => $holster,
        ]), [
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.id', $holster->id)
        ->assertJsonPath('data.is_active', false);

    $this->assertDatabaseHas('bozja_holsters', [
        'id' => $holster->id,
        'is_active' => false,
    ]);

    $otherHolster = BozjaHolster::query()->create([
        'group_id' => $group->id,
        'name' => ['en' => 'Alternative'],
        'role' => 'melee dps',
    ]);

    $this->actingAs($moderator)
        ->patchJson(route('groups.dashboard.content.delubrum-reginae-savage.holsters.default.update', [
            'group' => $group,
            'bozjaHolster' => $holster,
        ]))
        ->assertOk()
        ->assertJsonPath('data.is_default', true);

    $this->actingAs($moderator)
        ->patchJson(route('groups.dashboard.content.delubrum-reginae-savage.holsters.default.update', [
            'group' => $group,
            'bozjaHolster' => $otherHolster,
        ]))
        ->assertOk()
        ->assertJsonPath('data.is_default', true);

    $this->assertDatabaseHas('bozja_holsters', [
        'id' => $holster->id,
        'is_default' => false,
    ]);
    $this->assertDatabaseHas('bozja_holsters', [
        'id' => $otherHolster->id,
        'is_default' => true,
    ]);

    $this->actingAs($moderator)
        ->deleteJson(route('groups.dashboard.content.delubrum-reginae-savage.holsters.destroy', [
            'group' => $group,
            'bozjaHolster' => $otherHolster,
        ]))
        ->assertNoContent();

    $this->assertDatabaseMissing('bozja_holsters', [
        'id' => $otherHolster->id,
    ]);
});

it('rejects holster contents that exceed maximum capacity', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->create(['owner_id' => $owner->id]);
    $item = BozjaItem::query()->create([
        'key' => 'heavy-action',
        'category' => 'lost_actions',
        'name' => ['en' => 'Heavy Action'],
        'classification' => 'lost_action',
        'cache_weight' => 6,
    ]);

    $this->actingAs($owner)
        ->postJson(route('groups.dashboard.content.delubrum-reginae-savage.holsters.store', $group), [
            'name' => ['en' => 'Too Heavy'],
            'role' => 'tank',
            'items' => [
                ['id' => $item->id, 'quantity' => 17],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('items');

    $this->assertDatabaseMissing('bozja_holsters', [
        'group_id' => $group->id,
    ]);
});

it('allows moderators to open Forked Tower Blood content', function () {
    $owner = User::factory()->create();
    $moderator = User::factory()->create();
    $group = Group::factory()->create(['owner_id' => $owner->id]);

    $group->memberships()->create([
        'user_id' => $moderator->id,
        'role' => GroupMembership::ROLE_MODERATOR,
        'joined_at' => now(),
    ]);
    $phantomJob = PhantomJob::query()->create([
        'name' => 'Phantom Bard',
        'max_level' => 99,
        'icon_url' => '/reference-icons/phantom-jobs/icons/phantom-bard.webp',
        'transparent_icon_url' => '/reference-icons/phantom-jobs/transparent-icons/phantom-bard.webp',
        'sprite_url' => '/reference-icons/phantom-jobs/sprites/phantom-bard.webp',
    ]);

    $this->actingAs($moderator)
        ->get(route('groups.dashboard.content.forked-tower-blood', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Groups/Content/ForkedTowerBlood')
            ->where('group.slug', $group->slug)
            ->where('group.permissions.can_manage_members', true)
            ->has('phantom_jobs', 1)
            ->where('phantom_jobs.0.id', $phantomJob->id)
            ->where('phantom_jobs.0.name', 'Phantom Bard')
            ->where('phantom_jobs.0.max_level', 99)
            ->where('phantom_jobs.0.transparent_icon_url', '/reference-icons/phantom-jobs/transparent-icons/phantom-bard.webp')
        );
});

it('prevents regular members from opening Delubrum Reginae content', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $group = Group::factory()->create(['owner_id' => $owner->id]);

    $group->memberships()->create([
        'user_id' => $member->id,
        'role' => GroupMembership::ROLE_MEMBER,
        'joined_at' => now(),
    ]);

    $this->actingAs($member)
        ->get(route('groups.dashboard.content.delubrum-reginae-savage', $group))
        ->assertForbidden();
});

it('prevents regular members from opening Forked Tower Blood content', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $group = Group::factory()->create(['owner_id' => $owner->id]);

    $group->memberships()->create([
        'user_id' => $member->id,
        'role' => GroupMembership::ROLE_MEMBER,
        'joined_at' => now(),
    ]);

    $this->actingAs($member)
        ->get(route('groups.dashboard.content.forked-tower-blood', $group))
        ->assertForbidden();
});
