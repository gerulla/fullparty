<?php

use App\Models\Group;
use App\Models\GroupResourceCollection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->group = Group::factory()->create();
    $this->group->features()->update(['resource_hub_enabled' => true]);
    $this->actingAs($this->group->owner);
    $this->folder = fn (string $name, ?int $parent = null, int $order = 0) => GroupResourceCollection::create([
        'group_id' => $this->group->id, 'name' => $name, 'slug' => strtolower($name), 'parent_id' => $parent, 'sort_order' => $order,
    ]);
    $this->endpoint = fn (string $action, ?GroupResourceCollection $collection = null) => route('groups.dashboard.resources.collections.'.$action, [
        'group' => $this->group, ...($collection ? ['collection' => $collection] : []),
    ]);
});

it('renames only the name without detaching nested collections or changing public slugs', function () {
    $root = ($this->folder)('Root');
    $child = ($this->folder)('Child', $root->id, 5);
    $child->update(['is_featured' => true]);
    $this->putJson(($this->endpoint)('update', $child), ['name' => 'Renamed child'])->assertOk()
        ->assertJsonPath('data.name', 'Renamed child')->assertJsonPath('data.parent_id', $root->id)
        ->assertJsonPath('data.slug', 'child')->assertJsonPath('data.sort_order', 5)->assertJsonPath('data.is_featured', true);
    $this->putJson(($this->endpoint)('update', $child), ['name' => ' '])->assertUnprocessable();
    expect($child->fresh()->name)->toBe('Renamed child');
    $this->assertDatabaseHas('audit_logs', ['action' => 'group.resources.collection_saved', 'subject_id' => $child->id]);
});

it('accepts only the permitted name characters on collection creation and renaming', function () {
    $name = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ abcdefghijklmnopqrstuvwxyz 0123456789 .-(){}[];_&';
    $id = $this->postJson(($this->endpoint)('store'), ['name' => $name, 'slug' => 'allowed'])->assertCreated()
        ->assertJsonPath('data.name', $name)->json('data.id');
    $collection = GroupResourceCollection::findOrFail($id);
    $this->putJson(($this->endpoint)('update', $collection), ['name' => $name.' A'])->assertOk()
        ->assertJsonPath('data.name', $name.' A');
});

it('rejects disallowed characters on create and rename without changing existing collections', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $collection = ($this->folder)('Original');
    $allowed = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789 .-(){}[];_&';
    $invalid = array_filter(range(0, 127), fn ($code) => ! str_contains($allowed, chr($code)));
    $characters = array_merge(array_map(chr(...), $invalid), ['é', '攻略', '😀', "\u{00a0}", "\u{200b}"]);
    foreach ($characters as $char) {
        $name = 'A'.$char.'B';
        $this->postJson(($this->endpoint)('store'), ['name' => $name, 'slug' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->putJson(($this->endpoint)('update', $collection), ['name' => $name])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
    }
    expect($collection->fresh()->name)->toBe('Original');
    $this->assertDatabaseCount('group_resource_collections', 1);
});

it('creates child and root collections at the end of their own siblings', function () {
    $root = ($this->folder)('Root', null, 4);
    ($this->folder)('Existing', $root->id, 8);
    $this->postJson(($this->endpoint)('store'), ['name' => 'Nested', 'slug' => 'nested', 'parent_id' => $root->id])
        ->assertCreated()->assertJsonPath('data.parent_id', $root->id)->assertJsonPath('data.sort_order', 9);
    $this->postJson(($this->endpoint)('store'), ['name' => 'Another root', 'slug' => 'another-root', 'parent_id' => null])
        ->assertCreated()->assertJsonPath('data.parent_id', null)->assertJsonPath('data.sort_order', 5);
});

it('moves only siblings even when their old order values are tied and retains order after reload', function (bool $nested) {
    $parent = $nested ? ($this->folder)('Parent') : null;
    $first = ($this->folder)('First', $parent?->id);
    $second = ($this->folder)('Second', $parent?->id);
    $third = ($this->folder)('Third', $parent?->id);
    $child = ($this->folder)('Child', $second->id, 12);
    $response = $this->postJson(($this->endpoint)('reorder', $second), ['offset' => -1])->assertOk();
    expect(array_column($response->json('data'), 'id'))->toBe([$second->id, $first->id, $third->id]);
    expect($second->fresh()->sort_order)->toBe(0)->and($first->fresh()->sort_order)->toBe(1)
        ->and($child->fresh()->sort_order)->toBe(12)->and($child->fresh()->parent_id)->toBe($second->id);
    $this->postJson(($this->endpoint)('reorder', $second), ['offset' => -1])->assertOk();
    $this->postJson(($this->endpoint)('reorder', $second), ['offset' => 1])->assertOk();
    expect($first->fresh()->sort_order)->toBe(0)->and($second->fresh()->sort_order)->toBe(1);
    $this->get(route('groups.dashboard.resources.manage', $this->group))->assertInertia(fn (Assert $page) => $page
        ->where('collections', fn ($items) => collect($items)->where('parent_id', $parent?->id)->pluck('id')->all() === [$first->id, $second->id, $third->id]));
})->with([false, true]);

it('deletes empty collections but refuses to delete a parent containing nested folders', function () {
    $root = ($this->folder)('Root');
    $child = ($this->folder)('Child', $root->id);
    $this->deleteJson(($this->endpoint)('destroy', $root))->assertUnprocessable()->assertJsonValidationErrors('collection');
    $this->assertDatabaseHas('group_resource_collections', ['id' => $root->id]);
    $this->deleteJson(($this->endpoint)('destroy', $child))->assertNoContent();
    $this->assertDatabaseMissing('group_resource_collections', ['id' => $child->id]);
    $this->deleteJson(($this->endpoint)('destroy', $root))->assertNoContent();
    $this->assertDatabaseHas('audit_logs', ['action' => 'group.resources.collection_deleted', 'subject_id' => $root->id]);
});

it('rejects invalid reorder directions and operations against another group', function () {
    $folder = ($this->folder)('Folder');
    $otherGroup = Group::factory()->create();
    $other = GroupResourceCollection::create(['group_id' => $otherGroup->id, 'name' => 'Private', 'slug' => 'private']);
    foreach ([0, 2, -2, 'up'] as $offset) {
        $this->postJson(($this->endpoint)('reorder', $folder), ['offset' => $offset])->assertUnprocessable();
    }
    $this->postJson(($this->endpoint)('reorder', $other), ['offset' => 1])->assertNotFound();
    $this->putJson(($this->endpoint)('update', $other), ['name' => 'Renamed'])->assertNotFound();
    $this->deleteJson(($this->endpoint)('destroy', $other))->assertNotFound();
    expect($other->fresh()->name)->toBe('Private');
});

it('enforces collection management permissions for every action', function (string $role, bool $banned) {
    $user = User::factory()->create();
    $this->group->memberships()->create(['user_id' => $user->id, 'role' => $role]);
    if ($banned) {
        $this->group->bans()->create(['user_id' => $user->id, 'banned_by' => $this->group->owner_id]);
    }
    $this->actingAs($user);
    $folder = ($this->folder)('Folder');
    $this->putJson(($this->endpoint)('update', $folder), ['name' => 'Renamed'])->assertForbidden();
    $this->postJson(($this->endpoint)('reorder', $folder), ['offset' => 1])->assertForbidden();
    $this->deleteJson(($this->endpoint)('destroy', $folder))->assertForbidden();
    $this->postJson(($this->endpoint)('store'), ['name' => 'New', 'slug' => 'new'])->assertForbidden();
})->with([['member', false], ['moderator', true]]);
