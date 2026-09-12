<?php

use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceCollection;
use App\Models\GroupResourceLibrary;
use App\Models\User;
use App\Services\Groups\Resources\ResourceHomeService;
use App\Services\RichText\RichTextDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->group = Group::factory()->create();
    $this->group->features()->update(['resource_hub_enabled' => true]);
    $this->actingAs($this->group->owner);
    $this->home = GroupResource::where('group_id', $this->group->id)->where('is_home', true)->sole();
    $this->folder = fn (string $slug, ?int $parent = null) => GroupResourceCollection::create(['group_id' => $this->group->id, 'name' => $slug, 'slug' => $slug, 'parent_id' => $parent]);
    $this->content = fn (string $slug) => ['title' => $slug, 'slug' => $slug, 'body' => RichTextDocument::empty(), 'access_level' => 'everyone', 'tags' => [], 'activity_type_ids' => [], 'command' => null];
    $this->createResource = function (string $slug, ?int $parent = null) {
        $id = $this->postJson(route('groups.dashboard.resources.store', $this->group), ['content' => ($this->content)($slug), 'collection_id' => $parent])->assertCreated()->json('data.id');

        return GroupResource::findOrFail($id);
    };
    $this->organize = fn (array $data) => $this->postJson(route('groups.dashboard.resources.organization', $this->group), $data);
    $this->action = fn (GroupResource $resource, string $operation, array $data = []) => $this->postJson(route('groups.dashboard.resources.update', ['group' => $this->group, 'resource' => $resource, 'operation' => $operation]), ['version' => $resource->fresh()->version] + $data);
});

it('creates one published Home for every group and never duplicates it', function () {
    expect($this->home->collection_id)->toBeNull()->and($this->home->status)->toBe('published')
        ->and($this->home->publishedRevision->snapshot['title'])->toBe('Home');
    expect(app(ResourceHomeService::class)->ensure($this->group)->id)->toBe($this->home->id);
    $second = Group::factory()->create();
    expect(GroupResource::where('is_home', true)->count())->toBe(2);
    expect($second->featureEnabled('resource_hub_enabled'))->toBeFalse();
});

it('backfills Home for existing groups without altering resources or colliding with an existing home slug', function () {
    DB::table('group_resources')->where('id', $this->home->id)->delete();
    $existing = ($this->createResource)('home');
    $home = app(ResourceHomeService::class)->ensure($this->group);
    expect($home->is_home)->toBeTrue()->and($home->slug)->not->toBe('home')
        ->and($existing->fresh()->slug)->toBe('home')->and($existing->fresh()->is_home)->toBeFalse();
});

it('creates root resources with null or omitted collection and retains root in revision snapshots', function () {
    $resource = ($this->createResource)('root-guide');
    expect($resource->collection_id)->toBeNull();
    $this->postJson(route('groups.dashboard.resources.store', $this->group), ['content' => ($this->content)('omitted')])->assertCreated()->assertJsonPath('data.collection_id', null);
    $lease = ($this->action)($resource, 'acquire')->assertOk()->json('data.editing_token');
    ($this->action)($resource, 'save', ['editing_token' => $lease, 'collection_id' => null, 'content' => ($this->content)('root-guide'), 'summary' => 'Prepared root guide.'])->assertOk();
    ($this->action)($resource, 'publish', ['editing_token' => $lease])->assertOk()->assertJsonPath('data.resource.published.collection_id', null);
    expect($resource->fresh()->collection_id)->toBeNull()->and($resource->fresh()->publishedRevision->snapshot['collection_id'])->toBeNull();
});

it('moves resources into folders and back to root and persists sibling ordering', function () {
    $folder = ($this->folder)('Guides');
    $first = ($this->createResource)('first');
    $second = ($this->createResource)('second');
    ($this->organize)(['kind' => 'resource', 'id' => $second->id, 'version' => $second->version, 'parent_id' => null, 'before_id' => $first->id])->assertOk();
    expect($second->fresh()->sort_order)->toBeLessThan($first->fresh()->sort_order);
    ($this->organize)(['kind' => 'resource', 'id' => $first->id, 'version' => $first->fresh()->version, 'parent_id' => $folder->id])->assertOk();
    expect($first->fresh()->collection_id)->toBe($folder->id);
    ($this->organize)(['kind' => 'resource', 'id' => $first->id, 'version' => $first->fresh()->version, 'parent_id' => null])->assertOk();
    expect($first->fresh()->collection_id)->toBeNull();
    $this->get(route('groups.dashboard.resources.manage', $this->group))->assertInertia(fn (Assert $page) => $page->where('workspace.resources.0.is_home', true)->where('workspace.resources.1.id', $second->id));
    $this->assertDatabaseHas('audit_logs', ['action' => 'group.resources.organize']);
});

it('moves and reorders nested folders and rejects cycles and invalid destinations atomically', function () {
    $a = ($this->folder)('A');
    $b = ($this->folder)('B');
    $child = ($this->folder)('Child', $a->id);
    ($this->organize)(['kind' => 'collection', 'id' => $b->id, 'parent_id' => null, 'before_id' => $a->id])->assertOk();
    expect($b->fresh()->sort_order)->toBeLessThan($a->fresh()->sort_order);
    ($this->organize)(['kind' => 'collection', 'id' => $a->id, 'parent_id' => $child->id])->assertUnprocessable();
    expect($a->fresh()->parent_id)->toBeNull();
    ($this->organize)(['kind' => 'collection', 'id' => $child->id, 'parent_id' => $b->id])->assertOk();
    ($this->organize)(['kind' => 'collection', 'id' => $child->id, 'parent_id' => null])->assertOk();
    expect($child->fresh()->parent_id)->toBeNull();
    ($this->organize)(['kind' => 'collection', 'id' => $child->id, 'parent_id' => $b->id, 'before_id' => $a->id])->assertUnprocessable();
    expect($child->fresh()->parent_id)->toBeNull();
});

it('rejects stale and leased resource moves but allows moving drafts after editing ends', function () {
    $resource = ($this->createResource)('guide');
    $payload = ['kind' => 'resource', 'id' => $resource->id, 'parent_id' => null, 'version' => $resource->version];
    $lease = ($this->action)($resource, 'acquire')->assertOk()->json('data.editing_token');
    ($this->organize)($payload)->assertConflict();
    ($this->organize)(array_replace($payload, ['version' => $resource->fresh()->version]))->assertConflict();
    ($this->action)($resource, 'release', ['editing_token' => $lease])->assertOk();
    ($this->organize)(array_replace($payload, ['version' => $resource->fresh()->version]))->assertOk();
});

it('enforces permissions and group boundaries for organization', function () {
    $resource = ($this->createResource)('guide');
    $other = Group::factory()->create();
    $foreign = GroupResourceCollection::create(['group_id' => $other->id, 'name' => 'Foreign', 'slug' => 'foreign']);
    ($this->organize)(['kind' => 'resource', 'id' => $resource->id, 'parent_id' => $foreign->id, 'version' => $resource->version])->assertNotFound();
    ($this->organize)(['kind' => 'collection', 'id' => $foreign->id, 'parent_id' => null])->assertNotFound();
    $member = User::factory()->create();
    $this->group->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
    $this->actingAs($member);
    ($this->organize)(['kind' => 'resource', 'id' => $resource->id, 'parent_id' => null, 'version' => $resource->version])->assertForbidden();
});

it('protects Home from moves, deletion, unpublishing, archiving, and access changes', function () {
    foreach (['organize', 'archive', 'unpublish'] as $operation) {
        ($this->action)($this->home, $operation, ['collection_id' => null])->assertUnprocessable();
    }
    ($this->organize)(['kind' => 'resource', 'id' => $this->home->id, 'parent_id' => null, 'version' => $this->home->version])->assertUnprocessable();
    $this->deleteJson(route('groups.dashboard.resources.destroy', ['group' => $this->group, 'resource' => $this->home]), ['version' => $this->home->version])->assertUnprocessable();
    $lease = ($this->action)($this->home, 'acquire')->assertOk()->json('data.editing_token');
    foreach ([['access_level' => 'admin'], ['slug' => 'different']] as $changes) {
        ($this->action)($this->home, 'save', ['editing_token' => $lease, 'content' => array_replace(($this->content)($this->home->slug), $changes), 'summary' => 'Changed home'])->assertUnprocessable();
    }
    $folder = ($this->folder)('Folder');
    ($this->action)($this->home, 'save', ['editing_token' => $lease, 'content' => ($this->content)($this->home->slug), 'collection_id' => $folder->id, 'summary' => 'Move home'])->assertUnprocessable();
    expect($this->home->fresh()->collection_id)->toBeNull()->and($this->home->fresh()->status)->toBe('published');
});

it('allows editing Home and uses its published version as the member and anonymous landing page', function () {
    $lease = ($this->action)($this->home, 'acquire')->json('data.editing_token');
    ($this->action)($this->home, 'save', ['editing_token' => $lease, 'content' => array_replace(($this->content)($this->home->slug), ['title' => 'Welcome']), 'collection_id' => null, 'summary' => 'Welcome everyone'])->assertOk();
    $this->get(route('groups.dashboard.resources.index', $this->group))->assertInertia(fn (Assert $page) => $page->where('resource.title', 'Home'));
    ($this->action)($this->home, 'publish', ['editing_token' => $lease])->assertOk();
    $this->get(route('groups.dashboard.resources.index', $this->group))->assertInertia(fn (Assert $page) => $page->where('resource.title', 'Welcome'));
    GroupResourceLibrary::where('group_id', $this->group->id)->update(['visibility' => 'public']);
    auth()->logout();
    $this->getJson(route('public-resources.index', $this->group))->assertOk()->assertJsonPath('resource.title', 'Welcome')->assertJsonMissingPath('auth');
    $this->get(route('public-resources.index', $this->group))->assertInertia(fn (Assert $page) => $page->component('Resources/Show')->where('resource.title', 'Welcome')->missing('auth'));
    GroupResourceLibrary::where('group_id', $this->group->id)->update(['visibility' => 'private']);
    $this->get(route('public-resources.index', $this->group))->assertNotFound();
});

it('keeps Home and its history when deleting all other resources', function () {
    ($this->createResource)('temporary');
    $revisionId = $this->home->published_revision_id;
    $this->deleteJson(route('groups.dashboard.resources.library.resources.destroy', $this->group), ['confirmation' => 'i am sure'])->assertOk();
    expect(GroupResource::where('group_id', $this->group->id)->pluck('id')->all())->toBe([$this->home->id]);
    $this->assertDatabaseHas('group_resource_revisions', ['id' => $revisionId]);
});
