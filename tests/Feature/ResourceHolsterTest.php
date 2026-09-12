<?php

use App\Models\BozjaHolster;
use App\Models\BozjaItem;
use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceCollection;
use App\Models\GroupResourceLibrary;
use App\Models\User;
use App\Services\RichText\RichTextDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function holster_internal_url(string $name, mixed $parameters): string
{
    return rtrim(config('app.url'), '/').route($name, $parameters, false);
}

beforeEach(function () {
    $this->group = Group::factory()->create();
    $this->group->features()->update(['resource_hub_enabled' => true]);
    $this->folder = GroupResourceCollection::create(['group_id' => $this->group->id, 'name' => 'Holsters', 'slug' => 'holsters']);
    $this->holster = BozjaHolster::create([
        'group_id' => $this->group->id, 'name' => ['en' => 'Moonlit tank', 'ja' => '月光タンク'],
        'notes' => 'Bring consumables', 'role' => 'tank',
        'guide' => ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Preparation']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Meet at the north gate']]],
        ]],
    ]);
    $this->library = GroupResourceLibrary::create(['group_id' => $this->group->id, 'holster_collection_id' => $this->folder->id]);
    $this->settingsUrl = holster_internal_url('groups.dashboard.resources.library.holsters.update', $this->group);
    $this->articleUrl = route('public-resources.holsters.show', ['group' => $this->group, 'holster' => $this->holster]);
});

it('lets managers select a same-group collection and remove the listing without copying or deleting holsters', function (string $role) {
    $user = $this->group->owner;
    if ($role !== 'owner') {
        $user = User::factory()->create();
        $this->group->memberships()->create(['user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);
    }
    $this->actingAs($user);
    $count = GroupResource::count();
    $this->putJson($this->settingsUrl, ['collection_id' => $this->folder->id])->assertOk()
        ->assertJsonPath('data.collection_id', $this->folder->id)->assertJsonPath('data.active_count', 1)
        ->assertJsonPath('data.resources.0.title', 'Moonlit tank')->assertJsonMissingPath('data.resources.0.body');
    $this->putJson($this->settingsUrl, ['collection_id' => null])->assertOk()
        ->assertJsonPath('data.collection_id', null)->assertJsonPath('data.resources', []);
    expect(GroupResource::count())->toBe($count)->and($this->holster->fresh()->is_active)->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'group.resources.settings_updated']);
    $this->getJson($this->articleUrl)->assertNotFound();
})->with(['owner', 'admin', 'moderator']);

it('rejects members and invalid or foreign collection assignments', function () {
    $this->actingAs($this->group->owner);
    $foreign = GroupResourceCollection::create(['group_id' => Group::factory()->create()->id, 'name' => 'Foreign', 'slug' => 'foreign']);
    foreach ([[], ['collection_id' => $foreign->id], ['collection_id' => 999999], ['collection_id' => 'wrong']] as $input) {
        $this->putJson($this->settingsUrl, $input)->assertUnprocessable()->assertJsonValidationErrors('collection_id');
    }
    $member = User::factory()->create();
    $this->group->memberships()->create(['user_id' => $member->id, 'role' => 'member', 'joined_at' => now()]);
    $this->actingAs($member)->putJson($this->settingsUrl, ['collection_id' => null])->assertForbidden();
    expect($this->library->fresh()->holster_collection_id)->toBe($this->folder->id);
});

it('lists only this groups active holsters and includes a collection containing only holsters', function () {
    BozjaHolster::create(['group_id' => $this->group->id, 'name' => ['en' => 'Inactive secret'], 'is_active' => false]);
    BozjaHolster::create(['group_id' => Group::factory()->create()->id, 'name' => ['en' => 'Foreign secret']]);
    $this->getJson(route('public-resources.collections.show', ['group' => $this->group, 'collectionSlug' => 'holsters']))->assertOk()
        ->assertJsonPath('collections.0.resource_count', 1)->assertJsonPath('resources.total', 1)
        ->assertJsonPath('resources.data.0.source_type', 'holster')->assertJsonPath('resources.data.0.title', 'Moonlit tank')
        ->assertJsonMissingPath('resources.data.0.body')->assertDontSee('secret');
    $this->actingAs($this->group->owner)->get(holster_internal_url('groups.dashboard.resources.manage', $this->group))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('workspace.holsters.active_count', 1)
            ->where('workspace.holsters.resources.0.holster_id', $this->holster->id));
});

it('renders the current localized guide and quantified loadout on both reader surfaces', function (bool $public) {
    $item = BozjaItem::create(['key' => 'lost-test', 'category' => 'lost_actions', 'name' => ['en' => 'Lost Test'], 'classification' => 'lost_action', 'cache_weight' => 3]);
    $this->holster->items()->attach($item, ['quantity' => 2]);
    $this->holster->update(['notes' => 'Updated description']);
    $url = $public ? $this->articleUrl : holster_internal_url('groups.dashboard.resources.holsters.show', ['locale' => 'ja', 'group' => $this->group, 'holster' => $this->holster]);
    if (! $public) {
        $this->actingAs($this->group->owner);
    }
    $this->withSession(['locale' => 'ja'])->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('resource.title', '月光タンク')->where('resource.description', 'Updated description')
        ->where('resource.holster.capacity_used', 6)->where('resource.holster.items.0.quantity', 2)
        ->where('resource.body.content.0.content.0.text', 'Preparation')->where('resource.history.has_more', false));
    if ($public) {
        $this->get($url)->assertSee('<link rel="canonical" href="'.$url.'"', false);
    }
})->with([true, false]);

it('searches names descriptions guide text and holster tags', function (string $search) {
    $this->getJson(route('public-resources.index', $this->group).'?'.http_build_query(['q' => $search]))->assertOk()
        ->assertJsonPath('resources.total', 1)->assertJsonPath('resources.data.0.holster_id', $this->holster->id);
})->with(['moonlit', 'CONSUMABLES', 'north gate', '月光', 'tank', '#holster', 'drs']);

it('immediately removes inactive deleted and unlisted holsters from browse and direct links', function (string $change) {
    match ($change) {
        'inactive' => $this->holster->update(['is_active' => false]),
        'deleted' => $this->holster->delete(),
        'unlisted' => $this->library->update(['holster_collection_id' => null]),
    };
    $this->getJson($this->articleUrl)->assertNotFound();
    $this->getJson(route('public-resources.index', $this->group).'?q=moonlit')->assertOk()->assertJsonPath('resources.total', 0);
})->with(['inactive', 'deleted', 'unlisted']);

it('protects foreign holsters private libraries disabled hubs and nonmembers', function () {
    $foreign = BozjaHolster::create(['group_id' => Group::factory()->create()->id]);
    $this->getJson(route('public-resources.holsters.show', ['group' => $this->group, 'holster' => $foreign]))->assertNotFound();
    $this->library->update(['visibility' => 'private']);
    $this->getJson($this->articleUrl)->assertNotFound();
    $internal = holster_internal_url('groups.dashboard.resources.holsters.show', ['group' => $this->group, 'holster' => $this->holster]);
    $this->getJson($internal)->assertUnauthorized();
    $this->actingAs($this->group->owner)->get($internal)->assertOk();
    $this->group->features()->update(['resource_hub_enabled' => false]);
    $this->getJson($this->articleUrl)->assertNotFound();
    $this->get($internal)->assertNotFound();
    $this->putJson($this->settingsUrl, ['collection_id' => null])->assertForbidden();
});

it('keeps combined resource and holster pagination complete without duplicates', function () {
    for ($i = 0; $i < 49; $i++) {
        $resource = GroupResource::factory()->create(['group_id' => $this->group->id, 'collection_id' => $this->folder->id, 'status' => 'published']);
        $revision = $resource->revisions()->create(['summary' => 'Initial guide', 'state' => 'published', 'editor' => ['name' => 'Editor'], 'snapshot' => ['title' => 'Resource '.$i, 'access_level' => 'everyone', 'body' => RichTextDocument::empty()]]);
        $resource->update(['published_revision_id' => $revision->id]);
    }
    for ($i = 0; $i < 52; $i++) {
        BozjaHolster::create(['group_id' => $this->group->id, 'name' => ['en' => 'Holster '.$i]]);
    }
    $ids = [];
    foreach ([1 => 50, 2 => 50, 3 => 3] as $page => $count) {
        $response = $this->getJson(route('public-resources.index', $this->group).'?page='.$page)->assertOk()
            ->assertJsonPath('resources.total', 103)->assertJsonPath('resources.last_page', 3)->assertJsonCount($count, 'resources.data');
        $ids = array_merge($ids, array_column($response->json('resources.data'), 'id'));
    }
    expect(array_unique($ids))->toHaveCount(103);
});

it('moves the listing when a collection is replaced and clears it when the collection is deleted', function () {
    $destination = GroupResourceCollection::create(['group_id' => $this->group->id, 'name' => 'New', 'slug' => 'new']);
    $this->actingAs($this->group->owner)->deleteJson(holster_internal_url('groups.dashboard.resources.collections.destroy', ['group' => $this->group, 'collection' => $this->folder]), ['destination_id' => $destination->id])->assertNoContent();
    expect($this->library->fresh()->holster_collection_id)->toBe($destination->id);
    $this->getJson($this->articleUrl)->assertOk()->assertJsonPath('data.collection_id', $destination->id);
    $this->deleteJson(holster_internal_url('groups.dashboard.resources.collections.destroy', ['group' => $this->group, 'collection' => $destination]))->assertNoContent();
    expect($this->library->fresh()->holster_collection_id)->toBeNull()->and($this->holster->fresh())->not->toBeNull();
    $this->getJson($this->articleUrl)->assertNotFound();
});

it('keeps legacy markdown readable and sanitizes its HTML until guides are converted', function () {
    $this->holster->update(['guide' => "## Legacy guide\n\n<script>alert('x')</script>\n\n[Unsafe](javascript:alert)"]);
    $html = $this->getJson($this->articleUrl)->assertOk()->json('data.legacy_body_html');
    expect($html)->toContain('<h2>Legacy guide</h2>')->not->toContain('<script>', 'href="javascript:');
});

it('shows other active holsters in the same collection', function () {
    $other = BozjaHolster::create(['group_id' => $this->group->id, 'name' => ['en' => 'Refill'], 'type' => 'refill']);
    $this->getJson($this->articleUrl)->assertOk()->assertJsonPath('data.related_resources.0.holster_id', $other->id)
        ->assertJsonCount(1, 'data.related_resources');
});

it('shows only active same-group refills beside their pre-pop loadout', function (bool $public) {
    $refill = BozjaHolster::create([
        'group_id' => $this->group->id, 'parent_holster_id' => $this->holster->id,
        'type' => 'refill', 'name' => ['en' => 'Tank refill'], 'notes' => 'Bring extra actions',
    ]);
    $item = BozjaItem::create(['key' => 'planner-action', 'category' => 'lost_actions', 'name' => ['en' => 'Lost Action'], 'classification' => 'lost_action', 'cache_weight' => 4]);
    $refill->items()->attach($item, ['quantity' => 3]);
    foreach (['inactive', 'foreign'] as $hidden) {
        BozjaHolster::create([
            'group_id' => $hidden === 'foreign' ? Group::factory()->create()->id : $this->group->id,
            'parent_holster_id' => $this->holster->id, 'type' => 'refill',
            'name' => ['en' => 'Secret refill'], 'is_active' => $hidden !== 'inactive',
        ]);
    }
    $url = $public ? $this->articleUrl : holster_internal_url('groups.dashboard.resources.holsters.show', ['group' => $this->group, 'holster' => $this->holster]);
    if (! $public) {
        $this->actingAs($this->group->owner);
    }
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('resource.holster.prepop.id', $this->holster->id)
        ->has('resource.holster.refills', 1)->where('resource.holster.refills.0.id', $refill->id)
        ->where('resource.holster.refills.0.notes', 'Bring extra actions')
        ->where('resource.holster.refills.0.capacity_used', 12)
        ->where('resource.holster.refills.0.items.0.quantity', 3)
        ->missing('resource.holster.refills.0.guide'));
})->with([true, false]);

it('keeps a refill guide focused on its pair and hides unavailable parent content', function (string $parentState) {
    $refill = BozjaHolster::create(['group_id' => $this->group->id, 'parent_holster_id' => $this->holster->id, 'type' => 'refill']);
    BozjaHolster::create(['group_id' => $this->group->id, 'parent_holster_id' => $this->holster->id, 'type' => 'refill']);
    if ($parentState === 'inactive') {
        $this->holster->update(['is_active' => false]);
    } elseif ($parentState === 'foreign') {
        $this->holster->update(['group_id' => Group::factory()->create()->id]);
    }
    $response = $this->getJson(route('public-resources.holsters.show', ['group' => $this->group, 'holster' => $refill]))->assertOk()
        ->assertJsonCount(1, 'data.holster.refills')->assertJsonPath('data.holster.refills.0.id', $refill->id);
    if ($parentState === 'active') {
        $response->assertJsonPath('data.holster.prepop.id', $this->holster->id);
    } else {
        $response->assertJsonPath('data.holster.prepop', null);
    }
})->with(['active', 'inactive', 'foreign']);
