<?php

use App\Models\Group;
use App\Models\GroupResource;
use App\Models\User;
use App\Services\Groups\Resources\ResourceReaderService;
use App\Services\RichText\RichTextDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->group = Group::factory()->create();
    $this->group->features()->update(['resource_hub_enabled' => true]);
    $this->actingAs($this->group->owner);
    $this->content = ['title' => 'Guide', 'slug' => 'guide', 'description' => '', 'body' => RichTextDocument::empty(), 'access_level' => 'everyone', 'tags' => [], 'activity_type_ids' => [], 'commands' => [], 'author' => ['name' => 'Author']];
    $this->makeResource = fn (array $data = []) => GroupResource::factory()->create(array_replace([
        'group_id' => $this->group->id, 'collection_id' => null, 'working_copy' => $this->content,
    ], $data));
    $this->action = fn (GroupResource $resource, string $operation, array $data = []) => $this->postJson(route('groups.dashboard.resources.update', [
        'group' => $this->group, 'resource' => $resource, 'operation' => $operation,
    ]), array_replace(['version' => $resource->refresh()->version], $data));
});

it('pins and unpins without modifying content, edit time or revision history', function () {
    $resource = ($this->makeResource)();
    $updated = $resource->updated_at;
    $this->travel(1)->minutes();
    ($this->action)($resource, 'pin', ['is_pinned' => true])->assertOk()->assertJsonPath('data.resource.is_pinned', true);
    expect($resource->refresh()->working_copy)->toBe($this->content)
        ->and($resource->updated_at->equalTo($updated))->toBeTrue()
        ->and($resource->revisions()->count())->toBe(0);
    $workspace = app(ResourceReaderService::class)->workspace($this->group, $this->group->owner);
    expect($workspace['pin_limit'])->toBe(6)
        ->and(collect($workspace['resources'])->firstWhere('id', $resource->id)['is_pinned'])->toBeTrue();
    ($this->action)($resource, 'pin', ['is_pinned' => false])->assertOk()->assertJsonPath('data.resource.is_pinned', false);
    foreach (['pin', 'unpin'] as $action) {
        $this->assertDatabaseHas('audit_logs', ['action' => 'group.resources.'.$action, 'subject_id' => $resource->id]);
    }
});

it('allows exactly six pins per group and permits replacing a pin at the limit', function () {
    $resources = collect(range(1, 7))->map(fn () => ($this->makeResource)());
    foreach ($resources->take(6) as $resource) {
        ($this->action)($resource, 'pin', ['is_pinned' => true])->assertOk();
    }
    ($this->action)($resources[6], 'pin', ['is_pinned' => true])->assertUnprocessable()->assertJsonValidationErrors('is_pinned');
    ($this->action)($resources[0], 'pin', ['is_pinned' => true])->assertOk();
    ($this->action)($resources[0], 'pin', ['is_pinned' => false])->assertOk();
    ($this->action)($resources[6], 'pin', ['is_pinned' => true])->assertOk();
    expect(GroupResource::where('group_id', $this->group->id)->where('is_pinned', true)->count())->toBe(6);
});

it('enforces the limit through creation and organization including restricted pins', function () {
    foreach (range(1, 6) as $number) {
        ($this->makeResource)(['is_pinned' => true, 'access_level' => 'admin', 'management_access_level' => 'admin']);
    }
    $moderator = User::factory()->create();
    $this->group->memberships()->create(['user_id' => $moderator->id, 'role' => 'moderator']);
    $this->actingAs($moderator);
    $resource = ($this->makeResource)();
    ($this->action)($resource, 'organize', ['collection_id' => null, 'is_pinned' => true])->assertUnprocessable()->assertJsonValidationErrors('is_pinned');
    ($this->action)($resource, 'pin', ['is_pinned' => true])->assertUnprocessable()->assertJsonValidationErrors('is_pinned');
    $this->postJson(route('groups.dashboard.resources.store', $this->group), ['content' => array_replace($this->content, ['slug' => 'new-guide']), 'is_pinned' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('is_pinned');
    expect($resource->fresh()->is_pinned)->toBeFalse();
});

it('counts pins only in the current group and supports creating a pinned draft', function () {
    $other = Group::factory()->create();
    GroupResource::factory()->count(6)->create(['group_id' => $other->id, 'collection_id' => null, 'is_pinned' => true]);
    $this->postJson(route('groups.dashboard.resources.store', $this->group), ['content' => $this->content, 'is_pinned' => true])
        ->assertCreated()->assertJsonPath('data.is_pinned', true)->assertJsonPath('data.status', 'draft');
});

it('releases pins on archive and does not silently restore them when unarchiving', function () {
    $resource = ($this->makeResource)(['is_pinned' => true]);
    ($this->action)($resource, 'archive')->assertOk()->assertJsonPath('data.resource.is_pinned', false);
    ($this->action)($resource, 'pin', ['is_pinned' => true])->assertConflict();
    ($this->action)($resource, 'unarchive')->assertOk()->assertJsonPath('data.resource.is_pinned', false);
    ($this->action)($resource, 'pin', ['is_pinned' => true])->assertOk();
});

it('preserves editing leases and rejects stale pin mutations', function () {
    $resource = ($this->makeResource)();
    $version = $resource->refresh()->version;
    $token = ($this->action)($resource, 'acquire')->assertOk()->json('data.editing_token');
    ($this->action)($resource, 'pin', ['is_pinned' => true])->assertConflict();
    ($this->action)($resource, 'pin', ['is_pinned' => true, 'editing_token' => $token, 'version' => $version])->assertConflict();
    ($this->action)($resource, 'pin', ['is_pinned' => true, 'editing_token' => $token])->assertOk();
    ($this->action)($resource, 'autosave', ['editing_token' => $token, 'content' => $this->content])->assertOk();
});

it('protects Home and validates explicit pin state', function () {
    $resource = ($this->makeResource)();
    ($this->action)($resource, 'pin')->assertUnprocessable()->assertJsonValidationErrors('is_pinned');
    ($this->action)($resource, 'pin', ['is_pinned' => 'yes'])->assertUnprocessable()->assertJsonValidationErrors('is_pinned');
    $home = GroupResource::where('group_id', $this->group->id)->where('is_home', true)->firstOrFail();
    ($this->action)($home, 'pin', ['is_pinned' => true])->assertUnprocessable();
});

it('enforces group membership, bans, and restricted resource permissions', function (string $role, bool $banned, string $level) {
    $resource = ($this->makeResource)(['management_access_level' => $level]);
    $user = User::factory()->create();
    $this->group->memberships()->create(['user_id' => $user->id, 'role' => $role]);
    if ($banned) {
        $this->group->bans()->create(['user_id' => $user->id, 'banned_by' => $this->group->owner_id]);
    }
    $this->actingAs($user);
    ($this->action)($resource, 'pin', ['is_pinned' => true])->assertForbidden();
    expect($resource->fresh()->is_pinned)->toBeFalse();
})->with([['member', false, 'everyone'], ['moderator', true, 'everyone'], ['moderator', false, 'admin']]);

it('does not allow pinning a resource from another group', function () {
    $resource = GroupResource::factory()->create();
    ($this->action)($resource, 'pin', ['is_pinned' => true])->assertNotFound();
});
