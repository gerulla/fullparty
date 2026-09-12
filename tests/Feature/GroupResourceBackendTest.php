<?php

use App\Models\ActivityType;
use App\Models\Character;
use App\Models\DiscordGuildIntegration;
use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceCollection;
use App\Models\GroupResourceImage;
use App\Models\GroupResourceLibrary;
use App\Models\IntegrationClient;
use App\Models\User;
use App\Services\Groups\Resources\ResourceImageService;
use App\Services\Groups\Resources\ResourceLibraryService;
use App\Services\Groups\Resources\ResourcePublicationService;
use App\Services\Groups\Resources\ResourceReaderService;
use App\Services\Groups\Resources\ResourceWorkflowService;
use App\Services\RichText\MarkdownGuideConverter;
use App\Services\RichText\RichTextDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function resource_content(array $overrides = []): array
{
    $content = array_replace([
        'title' => 'DRS Bridges', 'slug' => 'drs-bridges', 'description' => 'Bridge strategy', 'body' => '# Bridge guide',
        'access_level' => 'everyone', 'tags' => ['DRS', ' Strategy ', 'drs'], 'activity_type_ids' => [],
        'command' => ['name' => 'Bridges', 'enabled' => true, 'embed' => ['title' => 'Bridge positions', 'description' => 'Manually written embed']],
    ], $overrides);
    if (is_string($content['body'])) {
        $content['body'] = app(MarkdownGuideConverter::class)->convert($content['body']);
    }
    $content['commands'] ??= isset($content['command']) ? [$content['command']] : [];
    unset($content['command']);

    return $content;
}

function resource_create($test, array $overrides = []): GroupResource
{
    return app(ResourceWorkflowService::class)->create($test->group, $test->owner, ['collection_id' => $test->collection->id, 'content' => resource_content($overrides)]);
}

function resource_action($test, GroupResource $resource, string $operation, array $data = [])
{
    $resource->refresh();
    if (in_array($operation, ['save', 'restore'], true)) {
        $data += ['summary' => 'Updated the guide.'];
    }

    return $test->postJson(rtrim(config('app.url'), '/').route('groups.dashboard.resources.update', ['group' => $test->group, 'resource' => $resource, 'operation' => $operation], false), ['version' => $resource->version] + $data);
}

function resource_publish($test, GroupResource $resource): void
{
    $lease = resource_action($test, $resource, 'acquire')->assertOk()->json('data.editing_token');
    if (! app(ResourcePublicationService::class)->savedRevision($resource->fresh())) {
        resource_action($test, $resource, 'save', ['editing_token' => $lease, 'content' => $resource->fresh()->working_copy, 'summary' => 'Initial guide.'])->assertOk();
    }
    resource_action($test, $resource, 'release', ['editing_token' => $lease, 'summary' => 'Initial guide.'])->assertOk();
    resource_action($test, $resource, 'publish')->assertOk();
    $resource->refresh();
}

beforeEach(function () {
    $this->group = Group::factory()->create();
    $this->group->features()->update(['resource_hub_enabled' => true]);
    GroupResourceLibrary::create(['group_id' => $this->group->id, 'visibility' => 'private']);
    $this->owner = $this->group->owner;
    $this->collection = GroupResourceCollection::create(['group_id' => $this->group->id, 'name' => 'DRS', 'slug' => 'drs']);
    $this->actingAs($this->owner);
    Storage::fake('local');
});

it('converts pending revisions to editable drafts without changing live content or retained history', function () {
    $resource = resource_create($this);
    resource_publish($this, $resource);
    $live = $resource->publishedRevision;
    $snapshot = array_replace($live->snapshot, ['title' => 'Previously pending', 'description' => 'Keep this draft']);
    $pending = $resource->revisions()->create(['snapshot' => $snapshot, 'state' => 'pending', 'editor' => ['name' => 'Original editor'], 'summary' => 'Prepared new instructions.']);
    $resource->update(['pending_revision_id' => $pending->id, 'working_copy' => null]);
    $version = $resource->version;
    $untouched = resource_create($this, ['slug' => 'untouched', 'command' => null]);
    $untouchedCopy = $untouched->working_copy;
    $migration = require database_path('migrations/2026_09_11_000004_convert_pending_resource_revisions_to_drafts.php');
    $migration->up();
    $migration->up();
    expect($resource->fresh()->working_copy)->toBe($snapshot)
        ->and($resource->fresh()->pending_revision_id)->toBeNull()
        ->and($resource->fresh()->published_revision_id)->toBe($live->id)
        ->and($resource->fresh()->version)->toBe($version + 1)
        ->and($live->fresh()->snapshot)->toBe($live->snapshot)
        ->and($pending->fresh()->state)->toBe('draft')
        ->and($pending->fresh()->summary)->toBe('Prepared new instructions.')
        ->and($resource->revisions()->count())->toBe(2)
        ->and($untouched->fresh()->working_copy)->toBe($untouchedCopy);
    resource_action($this, $resource, 'acquire')->assertOk()
        ->assertJsonPath('data.resource.working_copy.title', 'Previously pending')
        ->assertJsonPath('data.resource.published.title', 'DRS Bridges')
        ->assertJsonPath('data.resource.has_unpublished_changes', true);
});

it('only reports unpublished changes when saved content differs from live and ignores generated metadata', function () {
    $resource = resource_create($this);
    resource_publish($this, $resource);
    $lease = resource_action($this, $resource, 'acquire')->assertOk()->json('data.editing_token');
    $snapshot = $resource->fresh()->working_copy;
    $snapshot['commands'][0]['embed']['author'] = ['name' => 'Refreshed metadata'];
    $snapshot['commands'][0]['embed']['timestamp'] = now()->addDay()->toIso8601String();
    $snapshot['commands'][0]['updated_at'] = now()->addDay()->toIso8601String();
    $snapshot['collection_id'] = null;
    $resource->update(['working_copy' => $snapshot]);
    $reader = app(ResourceReaderService::class);
    expect($reader->managementDetail($this->group, $resource->fresh(), $this->owner)['has_unpublished_changes'])->toBeFalse();
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content(['title' => 'Draft title'])])->assertOk()
        ->assertJsonPath('data.resource.has_unpublished_changes', true)->assertJsonPath('data.resource.published.title', 'DRS Bridges');
    expect(collect($reader->workspace($this->group, $this->owner)['resources'])->firstWhere('id', $resource->id)['has_unpublished_changes'])->toBeTrue();
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content()])->assertOk()
        ->assertJsonPath('data.resource.has_unpublished_changes', false);
    $count = $resource->revisions()->count();
    resource_action($this, $resource, 'publish', ['editing_token' => $lease])->assertOk();
    expect($resource->revisions()->count())->toBe($count);
});

it('rolls back draft saving and publication together if an edit summary is missing', function () {
    $resource = resource_create($this);
    resource_publish($this, $resource);
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    $before = $resource->fresh()->working_copy;
    resource_action($this, $resource, 'save', [
        'editing_token' => $lease, 'summary' => null, 'publish' => true, 'content' => resource_content(['title' => 'Invalid save']),
    ])->assertUnprocessable()->assertJsonValidationErrors('summary');
    expect($resource->fresh()->working_copy)->toBe($before)
        ->and($resource->fresh()->publishedRevision->snapshot['title'])->toBe('DRS Bridges')
        ->and($resource->revisions()->count())->toBe(1);
});

it('no longer exposes the submission and pending-discard endpoints', function () {
    $resource = resource_create($this);
    resource_action($this, $resource, 'submit')->assertNotFound();
    resource_action($this, $resource, 'discard')->assertNotFound();
});

it('provides live reader links for management views without exposing draft slugs or widening resource access', function (string $access) {
    $resource = resource_create($this, ['access_level' => $access]);
    $reader = app(ResourceReaderService::class);
    expect($reader->managementDetail($this->group, $resource, $this->owner)['reader_urls'])->toBeNull();
    expect(collect($reader->workspace($this->group, $this->owner)['resources'])->firstWhere('id', $resource->id)['reader_urls'])->toBeNull();
    resource_publish($this, $resource);
    $urls = [
        'group' => route('groups.dashboard.resources.show', ['group' => $this->group->slug, 'slug' => $resource->uuid]),
        'public' => $access === 'everyone' ? route('public-resources.show', ['group' => $this->group->slug, 'slug' => $resource->uuid]) : null,
    ];
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content(['slug' => 'unpublished-slug', 'access_level' => $access === 'everyone' ? 'admin' : 'everyone'])])
        ->assertOk()->assertJsonPath('data.resource.reader_urls', $urls);
    foreach (['private', 'public'] as $visibility) {
        GroupResourceLibrary::where('group_id', $this->group->id)->update(['visibility' => $visibility]);
        expect($reader->managementDetail($this->group, $resource->fresh(), $this->owner)['reader_urls'])->toBe($urls)
            ->and(collect($reader->workspace($this->group, $this->owner)['resources'])->firstWhere('id', $resource->id)['reader_urls'])->toBe($urls);
    }
})->with(['everyone', 'moderator', 'admin']);

it('provides a public homepage shortcut only for public libraries and uses homepage routes for Home', function () {
    $libraries = app(ResourceLibraryService::class);
    expect($libraries->payload($this->group, true)['public_url'])->toBeNull();
    GroupResourceLibrary::where('group_id', $this->group->id)->update(['visibility' => 'public']);
    $homeUrl = route('public-resources.index', ['group' => $this->group->slug]);
    expect($libraries->payload($this->group, true)['public_url'])->toBe($homeUrl);
    $home = GroupResource::where('group_id', $this->group->id)->where('is_home', true)->sole();
    expect(app(ResourceReaderService::class)->managementDetail($this->group, $home, $this->owner)['reader_urls'])->toBe([
        'group' => route('groups.dashboard.resources.index', ['group' => $this->group->slug]), 'public' => $homeUrl,
    ]);
});

it('creates a private library and sanitized draft with normalized tags and independent embed', function () {
    $response = $this->postJson(route('groups.dashboard.resources.store', $this->group), ['collection_id' => $this->collection->id, 'content' => resource_content(['body' => '<script>alert(1)</script>'])])->assertCreated();
    $resource = GroupResource::findOrFail($response->json('data.id'));
    expect($resource->status)->toBe('draft')->and($resource->working_copy['tags'])->toBe(['drs', 'strategy'])
        ->and(app(RichTextDocument::class)->html($resource->working_copy['body']))->not->toContain('<script>')
        ->and($resource->working_copy['commands'][0]['name'])->toBe('bridges')
        ->and(GroupResourceLibrary::first()->visibility)->toBe('private');
    $this->assertDatabaseHas('audit_logs', ['action' => 'group.resources.created']);
});

it('preserves rich resource document formatting through saving and publishing', function () {
    $resource = resource_create($this);
    $body = "# Heading\n\nBody with **bold**, *italic*, ~~strikethrough~~ and `code`.\n\n> Ready check\n> > Nested quote\n\n- [ ] Confirm party\n- [x] Check gear\n\n1. First\n2. Second\n\n| Party | Side |\n| --- | --- |\n| A | West |\n\n---\n\n```text\nParty A\n```";
    $body = app(MarkdownGuideConverter::class)->convert($body);
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content(['body' => $body])])->assertOk();
    expect($resource->fresh()->working_copy['body'])->toBe($body);
    resource_action($this, $resource, 'release', ['editing_token' => $lease])->assertOk();
    resource_action($this, $resource, 'publish')->assertOk();
    expect($resource->fresh()->publishedRevision->snapshot['body'])->toBe($body);
});

it('rejects raw Markdown and unsafe JSON rather than publishing executable content', function () {
    $payload = resource_content();
    $payload['body'] = '# Legacy text';
    $this->postJson(route('groups.dashboard.resources.store', $this->group), ['collection_id' => $this->collection->id, 'content' => $payload])->assertUnprocessable();
    $payload['body'] = ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => 'javascript:alert(1)']]]];
    $this->postJson(route('groups.dashboard.resources.store', $this->group), ['collection_id' => $this->collection->id, 'content' => $payload])->assertUnprocessable();
    expect(GroupResource::where('is_home', false)->count())->toBe(0);
});

it('requires a sentence for every saved version and adds a separate publication event', function () {
    $resource = resource_create($this);
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    $character = Character::factory()->primary()->create(['user_id' => $this->owner->id]);
    resource_action($this, $resource, 'save', [
        'editing_token' => $lease, 'summary' => 'Prepared bridge assignments.',
        'content' => resource_content(['title' => 'Bridge Assignments', 'character_id' => $character->id]),
    ])->assertOk()->assertJsonPath('data.resource.history.0.summary', 'Prepared bridge assignments.')
        ->assertJsonPath('data.resource.history.0.editor.avatar_url', $character->avatar_url);
    $savedVersion = $resource->fresh()->version;
    resource_action($this, $resource, 'save', [
        'editing_token' => $lease, 'summary' => null, 'content' => resource_content(['title' => 'Should not save']),
    ])->assertUnprocessable()->assertJsonValidationErrors('summary');
    expect($resource->fresh()->version)->toBe($savedVersion)
        ->and($resource->fresh()->working_copy['title'])->toBe('Bridge Assignments');

    resource_action($this, $resource, 'save', [
        'editing_token' => $lease, 'summary' => 'Clarified the east group.', 'content' => resource_content(['title' => 'Bridge Assignments']),
    ])->assertOk()->assertJsonCount(2, 'data.resource.history')
        ->assertJsonPath('data.resource.history.0.summary', 'Clarified the east group.')
        ->assertJsonPath('data.resource.working_copy.author.id', $character->id);
    resource_action($this, $resource, 'publish', ['editing_token' => $lease])->assertOk()->assertJsonCount(3, 'data.resource.history')
        ->assertJsonPath('data.resource.published.title', 'Bridge Assignments');
});

it('publishes the saved collection activities and disabled embed while retaining the lease', function () {
    $resource = resource_create($this);
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    $collection = GroupResourceCollection::create(['group_id' => $this->group->id, 'name' => 'Strats', 'slug' => 'strats']);
    $activities = ActivityType::factory()->count(2)->create()->pluck('id')->all();
    $content = resource_content(['title' => 'Saved title', 'activity_type_ids' => $activities]);
    $content['commands'][0]['enabled'] = false;
    resource_action($this, $resource, 'save', [
        'editing_token' => $lease, 'summary' => 'Prepared guide.', 'collection_id' => $collection->id, 'content' => $content,
    ])->assertOk()->assertJsonPath('data.resource.working_copy.title', 'Saved title')->assertJsonPath('data.resource.published', null);
    resource_action($this, $resource, 'publish', ['editing_token' => $lease])->assertOk()
        ->assertJsonPath('data.resource.published.title', 'Saved title')
        ->assertJsonPath('data.resource.published.activity_type_ids', $activities)
        ->assertJsonPath('data.resource.published.commands.0.enabled', false)
        ->assertJsonPath('data.resource.has_unpublished_changes', false)
        ->assertJsonPath('data.resource.collection_id', $collection->id);
    resource_action($this, $resource, 'acquire')->assertConflict();
    resource_action($this, $resource, 'heartbeat', ['editing_token' => $lease])->assertOk();
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => array_replace($content, ['title' => 'Next draft'])])->assertOk()
        ->assertJsonPath('data.resource.working_copy.title', 'Next draft')->assertJsonPath('data.resource.published.title', 'Saved title')
        ->assertJsonPath('data.resource.has_unpublished_changes', true);
    $this->assertDatabaseHas('audit_logs', ['action' => 'group.resources.publish']);
});

it('resumes an editing session only with its token and leaves last edit unchanged by heartbeats', function () {
    $resource = resource_create($this);
    $lastEdit = $resource->updated_at->toIso8601String();
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    $this->travel(2)->minutes();
    resource_action($this, $resource, 'acquire', ['editing_token' => $lease])->assertOk()->assertJsonPath('data.editing_token', $lease);
    resource_action($this, $resource, 'heartbeat', ['editing_token' => $lease])->assertOk();
    expect($resource->fresh()->updated_at->toIso8601String())->toBe($lastEdit);
    resource_action($this, $resource, 'acquire', ['editing_token' => str_repeat('x', 64)])->assertConflict();
});

it('keeps a saved unpublished admin draft restricted', function () {
    $resource = resource_create($this);
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content(['access_level' => 'admin'])])->assertOk();
    resource_action($this, $resource, 'release', ['editing_token' => $lease])->assertOk();
    $moderator = User::factory()->create();
    $this->group->memberships()->create(['user_id' => $moderator->id, 'role' => 'moderator']);
    $this->actingAs($moderator);
    resource_action($this, $resource, 'acquire')->assertForbidden();
});

it('deletes only the selected resource and releases its images command and quota', function () {
    $resource = resource_create($this);
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    $image = $this->postJson(route('groups.dashboard.resources.images.store', $this->group), [
        'image' => UploadedFile::fake()->image('diagram.png'), 'resource_id' => $resource->id,
        'version' => $resource->fresh()->version, 'editing_token' => $lease, 'alt_text' => 'Bridge diagram',
    ])->assertCreated()->json('data');
    $imagePath = GroupResourceImage::where('uuid', $image['uuid'])->value('path');
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content(), 'summary' => 'Prepared guide.'])->assertOk();
    resource_action($this, $resource, 'release', ['editing_token' => $lease])->assertOk();
    resource_action($this, $resource, 'publish')->assertOk();
    $other = resource_create($this, ['slug' => 'other-resource', 'command' => null]);
    $library = GroupResourceLibrary::where('group_id', $this->group->id)->firstOrFail();
    $library->update(['customization' => ['title' => 'Keep this', 'start_resource_id' => $resource->id]]);
    $this->deleteJson(route('groups.dashboard.resources.destroy', ['group' => $this->group, 'resource' => $resource]), [
        'version' => $resource->fresh()->version,
    ])->assertNoContent();
    foreach (['group_resource_revisions', 'group_resource_commands', 'group_resource_slugs', 'group_resource_images'] as $table) {
        $this->assertDatabaseMissing($table, ['resource_id' => $resource->id]);
    }
    $this->assertDatabaseHas('group_resources', ['id' => $other->id]);
    $this->assertDatabaseHas('group_resource_collections', ['id' => $this->collection->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'group.resources.deleted']);
    expect($library->fresh()->storage_used_bytes)->toBe(0)
        ->and($library->fresh()->customization)->toBe(['title' => 'Keep this']);
    Storage::disk('local')->assertMissing($imagePath);
});

it('protects deletion with versions leases and resource permissions', function () {
    $resource = resource_create($this, ['access_level' => 'admin']);
    $url = route('groups.dashboard.resources.destroy', ['group' => $this->group, 'resource' => $resource]);
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    $version = $resource->fresh()->version;
    $this->deleteJson($url, ['version' => $version - 1])->assertConflict();
    $this->deleteJson($url, ['version' => $version])->assertConflict();
    $moderator = User::factory()->create();
    $this->group->memberships()->create(['user_id' => $moderator->id, 'role' => 'moderator']);
    $this->actingAs($moderator)->deleteJson($url, ['version' => $version])->assertForbidden();
    $this->actingAs($this->owner)->deleteJson($url, ['version' => $version, 'editing_token' => $lease])->assertNoContent();
    $other = GroupResource::factory()->create();
    $this->deleteJson(route('groups.dashboard.resources.destroy', ['group' => $this->group, 'resource' => $other]), ['version' => $other->version])->assertNotFound();
});

it('creates an untitled draft immediately and includes it in the management workspace after reload', function () {
    $payload = ['collection_id' => $this->collection->id, 'content' => resource_content([
        'title' => 'Untitled resource', 'slug' => 'untitled-'.str()->uuid(), 'description' => '', 'body' => '', 'tags' => [], 'command' => null,
    ])];
    $response = $this->postJson(route('groups.dashboard.resources.store', $this->group), $payload)->assertCreated()
        ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.working_copy.title', 'Untitled resource')
        ->assertJsonPath('data.working_copy.body', RichTextDocument::empty())->assertJsonPath('data.working_copy.author.name', $this->owner->name)
        ->assertJsonMissingPath('data.pending')->assertJsonPath('data.published', null)->assertJsonPath('data.has_unpublished_changes', true)->assertJsonCount(0, 'data.history');
    $id = $response->json('data.id');
    $this->assertDatabaseHas('group_resources', ['id' => $id, 'collection_id' => $this->collection->id, 'status' => 'draft']);
    $this->assertDatabaseMissing('group_resource_revisions', ['resource_id' => $id]);
    $this->get(route('groups.dashboard.resources.manage', $this->group))->assertInertia(fn (Assert $page) => $page
        ->where('workspace.resources.0.is_home', true)
        ->where('workspace.resources.1.id', $id)->where('workspace.resources.1.summary.title', 'Untitled resource')
        ->missing('workspace.resources.1.summary.body')->missing('workspace.resources.1.working_copy'));
    $this->getJson(route('groups.dashboard.resources.edit', ['group' => $this->group, 'resource' => $id]))
        ->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.working_copy.title', 'Untitled resource');
});

it('allows moderators to create drafts but rejects members and foreign collections', function () {
    $moderator = User::factory()->create();
    $this->group->memberships()->create(['user_id' => $moderator->id, 'role' => 'moderator']);
    $this->actingAs($moderator);
    $this->postJson(route('groups.dashboard.resources.store', $this->group), ['collection_id' => $this->collection->id, 'content' => resource_content(['command' => null])])->assertCreated();
    $otherCollection = GroupResourceCollection::create(['group_id' => Group::factory()->create()->id, 'name' => 'Other', 'slug' => 'other']);
    $this->postJson(route('groups.dashboard.resources.store', $this->group), ['collection_id' => $otherCollection->id, 'content' => resource_content(['slug' => 'other'])])->assertNotFound();
    $member = User::factory()->create();
    $this->group->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
    $this->actingAs($member)->postJson(route('groups.dashboard.resources.store', $this->group), ['collection_id' => $this->collection->id, 'content' => resource_content(['slug' => 'member'])])->assertForbidden();
    expect(GroupResource::where('is_home', false)->count())->toBe(1);
});

it('keeps restricted resource details out of the workspace and on-demand JSON', function () {
    $secret = resource_create($this, ['slug' => 'admin-guide', 'access_level' => 'admin', 'command' => null]);
    $visible = resource_create($this, ['slug' => 'everyone-guide']);
    $moderator = User::factory()->create();
    $this->group->memberships()->create(['user_id' => $moderator->id, 'role' => 'moderator']);
    $verified = Character::factory()->create(['user_id' => $moderator->id, 'verified_at' => now()]);
    Character::factory()->create(['user_id' => $moderator->id, 'verified_at' => null]);
    $this->actingAs($moderator);
    $this->get(route('groups.dashboard.resources.manage', $this->group))->assertInertia(fn (Assert $page) => $page
        ->has('workspace.resources', 2)->where('workspace.resources.0.is_home', true)->where('workspace.resources.1.id', $visible->id)
        ->missing('workspace.resources.1.commands.0.embed')->missing('workspace.resources.1.summary.body')
        ->has('workspace.authors', 2)->where('workspace.authors.1.name', $verified->name));
    $this->getJson(route('groups.dashboard.resources.edit', ['group' => $this->group, 'resource' => $secret]))->assertForbidden();
    $other = GroupResource::factory()->create();
    $this->getJson(route('groups.dashboard.resources.edit', ['group' => $this->group, 'resource' => $other]))->assertNotFound();
});

it('keeps published content live while saved drafts remain editable and directly publishable', function () {
    $resource = resource_create($this);
    resource_publish($this, $resource);
    $lease = resource_action($this, $resource, 'acquire')->assertOk()->json('data.editing_token');
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content(['title' => 'Updated bridges'])])->assertOk();
    resource_action($this, $resource, 'release', ['editing_token' => $lease, 'summary' => 'Clarified bridge instructions.'])->assertOk();
    $nextLease = resource_action($this, $resource, 'acquire')->assertOk()->json('data.editing_token');
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content()])->assertConflict();
    $this->get(route('groups.dashboard.resources.show', ['group' => $this->group, 'slug' => $resource->uuid]))->assertInertia(fn (Assert $page) => $page->where('resource.title', 'DRS Bridges'));
    resource_action($this, $resource, 'save', ['editing_token' => $nextLease, 'content' => resource_content(['title' => 'Updated bridges'])])->assertOk();
    resource_action($this, $resource, 'publish')->assertConflict();
    resource_action($this, $resource, 'publish', ['editing_token' => $nextLease])->assertOk()->assertJsonPath('data.resource.has_unpublished_changes', false);
    expect($resource->fresh()->publishedRevision->snapshot['title'])->toBe('Updated bridges');
});

it('enforces exclusive expiring leases and optimistic versions even for the same editor', function () {
    $resource = resource_create($this);
    $lease = resource_action($this, $resource, 'acquire')->assertOk()->json('data.editing_token');
    resource_action($this, $resource, 'acquire')->assertConflict();
    resource_action($this, $resource, 'save', ['editing_token' => str_repeat('a', 64), 'content' => resource_content()])->assertConflict();
    $staleVersion = $resource->fresh()->version;
    resource_action($this, $resource, 'heartbeat', ['editing_token' => $lease])->assertOk();
    $this->postJson(route('groups.dashboard.resources.update', ['group' => $this->group, 'resource' => $resource, 'operation' => 'release']), ['version' => $staleVersion, 'editing_token' => $lease])->assertConflict();
    $this->travel(16)->minutes();
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content()])->assertConflict();
    resource_action($this, $resource, 'acquire')->assertOk();
});

it('lets another authorized manager publish a saved draft without an approver role', function () {
    $resource = resource_create($this);
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content(), 'summary' => 'Ready for publication.'])->assertOk();
    resource_action($this, $resource, 'release', ['editing_token' => $lease, 'summary' => 'Ready for publication.'])->assertOk();
    $moderator = User::factory()->create();
    $this->group->memberships()->create(['user_id' => $moderator->id, 'role' => 'moderator']);
    $this->actingAs($moderator);
    resource_action($this, $resource, 'publish')->assertOk();
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    resource_action($this, $resource, 'release', ['editing_token' => $lease, 'summary' => 'Pending revision.'])->assertOk();
    resource_action($this, $resource, 'acquire')->assertOk();
});

it('enforces reader and manager role boundaries', function (string $role, array $readable, bool $manage) {
    foreach (['everyone', 'moderator', 'admin'] as $level) {
        $resource = resource_create($this, ['slug' => $level, 'access_level' => $level, 'command' => null]);
        resource_publish($this, $resource);
    }
    $user = $role === 'owner' ? $this->owner : User::factory()->create();
    if ($role !== 'owner') {
        $this->group->memberships()->create(['user_id' => $user->id, 'role' => $role]);
    }
    $this->actingAs($user);
    foreach (['everyone', 'moderator', 'admin'] as $level) {
        $resource = GroupResource::where('slug', $level)->first();
        $this->get(route('groups.dashboard.resources.show', ['group' => $this->group, 'slug' => $resource->uuid]))->assertStatus(in_array($level, $readable) ? 200 : 404);
        resource_action($this, $resource, 'acquire')->assertStatus($manage && in_array($level, $readable) ? 200 : 403);
    }
})->with([
    ['member', ['everyone'], false], ['moderator', ['everyone', 'moderator'], true], ['admin', ['everyone', 'moderator', 'admin'], true], ['owner', ['everyone', 'moderator', 'admin'], true],
]);

it('prevents cross-group operations and character attribution forgery', function () {
    $other = Group::factory()->create();
    $resource = GroupResource::factory()->create(['group_id' => $other->id]);
    resource_action($this, $resource, 'acquire')->assertNotFound();
    $character = Character::factory()->create(['verified_at' => now()]);
    $this->postJson(route('groups.dashboard.resources.store', $this->group), ['collection_id' => $this->collection->id, 'content' => resource_content(['character_id' => $character->id])])->assertUnprocessable();
    $this->postJson(route('groups.dashboard.resources.store', $this->group), ['collection_id' => $resource->collection_id, 'content' => resource_content()])->assertNotFound();
});

it('recursively prunes restricted branches and never exposes admin data on the public host', function () {
    $child = GroupResourceCollection::create(['group_id' => $this->group->id, 'parent_id' => $this->collection->id, 'name' => 'Secret guides', 'slug' => 'secret']);
    $this->collection = $child;
    $resource = resource_create($this, ['access_level' => 'admin']);
    resource_publish($this, $resource);
    GroupResourceLibrary::where('group_id', $this->group->id)->update(['visibility' => 'public']);
    $this->getJson(route('public-resources.index', $this->group))->assertOk()->assertJsonCount(0, 'collections')->assertJsonPath('resources.total', 1)->assertJsonPath('resource.is_home', true)->assertJsonMissingPath('auth');
    $this->getJson(route('public-resources.show', ['group' => $this->group, 'slug' => $resource->slug]))->assertNotFound();
    $this->getJson(route('public-resources.collections.show', ['group' => $this->group, 'collectionSlug' => 'secret']))->assertNotFound();
    $public = resource_create($this, ['slug' => 'open-guide', 'command' => null]);
    resource_publish($this, $public);
    $this->getJson(route('public-resources.index', $this->group))->assertOk()->assertJsonCount(2, 'collections')->assertJsonPath('resources.total', 2);
    GroupResourceLibrary::where('group_id', $this->group->id)->update(['visibility' => 'private']);
    $this->getJson(route('public-resources.index', $this->group))->assertNotFound();
});

it('prevents collection cycles and deletes only empty or safely relocated contents', function () {
    $child = $this->postJson(route('groups.dashboard.resources.collections.store', $this->group), ['name' => 'Child', 'slug' => 'child', 'parent_id' => $this->collection->id])->assertCreated()->json('data.id');
    $this->putJson(route('groups.dashboard.resources.collections.update', ['group' => $this->group, 'collection' => $this->collection]), ['name' => 'DRS', 'slug' => 'drs', 'parent_id' => $child])->assertUnprocessable();
    $url = route('groups.dashboard.resources.collections.destroy', ['group' => $this->group, 'collection' => $this->collection]);
    $this->deleteJson($url)->assertUnprocessable();
    $this->deleteJson($url, ['destination_id' => $child])->assertUnprocessable();
    $dest = GroupResourceCollection::create(['group_id' => $this->group->id, 'name' => 'Other', 'slug' => 'other']);
    $resource = resource_create($this);
    $this->deleteJson($url, ['destination_id' => $dest->id])->assertNoContent();
    expect($resource->fresh()->collection_id)->toBe($dest->id)->and(GroupResourceCollection::find($child)->parent_id)->toBe($dest->id);
});

it('reserves slugs and command names case-insensitively per group including drafts', function () {
    resource_create($this);
    $this->postJson(route('groups.dashboard.resources.store', $this->group), ['collection_id' => $this->collection->id, 'content' => resource_content(['slug' => 'another', 'command' => ['name' => 'BRIDGES', 'enabled' => false, 'embed' => ['title' => 'Other']]])])->assertUnprocessable()->assertJsonValidationErrors('content.commands.0.name');
});

it('validates embed limits and rejects author-controlled footers', function (array $embed) {
    $this->postJson(route('groups.dashboard.resources.store', $this->group), ['collection_id' => $this->collection->id, 'content' => resource_content(['command' => ['name' => 'example', 'enabled' => true, 'embed' => $embed]])])->assertUnprocessable();
})->with([
    [['title' => 'Guide', 'footer' => ['text' => 'Forged']]],
    [['title' => str_repeat('a', 257)]],
    [['description' => str_repeat('a', 4096), 'fields' => [['name' => 'A', 'value' => str_repeat('b', 1024)], ['name' => 'B', 'value' => str_repeat('b', 1024)]]]],
    [['fields' => array_fill(0, 26, ['name' => 'A', 'value' => 'B'])]],
]);

it('uses the existing guild group link without relinking and computes current public links', function () {
    $resource = resource_create($this, ['access_level' => 'admin']);
    resource_publish($this, $resource);
    DiscordGuildIntegration::create(['group_id' => $this->group->id, 'discord_guild_id' => '123456', 'guild_installed_at' => now()]);
    $token = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()->create(['api_token_hash' => IntegrationClient::hashApiToken($token), 'scopes' => [IntegrationClient::SCOPE_RESOURCES_READ]]);
    $list = route('api.integrations.resource-commands.index');
    $show = route('api.integrations.resource-commands.show', ['commandName' => 'BRIDGES']);
    $body = ['discord_guild_id' => '123456'];
    $this->postJson($list, $body)->assertUnauthorized();
    $this->withToken($token)->postJson($list, $body)->assertOk()->assertJsonPath('data.0.command_name', 'bridges')->assertJsonPath('data.0.title', 'Bridge positions')->assertJsonMissingPath('data.0.embed');
    $this->withToken($token)->postJson($show, $body)->assertOk()->assertJsonPath('data.embed.title', 'Bridge positions')->assertJsonPath('data.embed.footer.text', 'FullParty')->assertJsonCount(0, 'data.components')->assertJsonMissingPath('data.body');
    $otherToken = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()->create(['api_token_hash' => IntegrationClient::hashApiToken($otherToken), 'scopes' => [IntegrationClient::SCOPE_RESOURCES_READ]]);
    $this->withToken($otherToken)->postJson($show, $body)->assertOk()->assertJsonPath('data.command_name', 'bridges');
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content(['command' => ['name' => 'renamed', 'enabled' => true, 'embed' => ['title' => 'Pending message']]])])->assertOk();
    resource_action($this, $resource, 'release', ['editing_token' => $lease, 'summary' => 'Share a public version.'])->assertOk();
    $this->withToken($token)->postJson($list, $body)->assertOk()->assertJsonPath('data.0.command_name', 'bridges')->assertJsonPath('data.0.title', 'Bridge positions');
    $this->withToken($token)->postJson($show, $body)->assertOk()->assertJsonPath('data.embed.title', 'Bridge positions');
    resource_action($this, $resource, 'publish')->assertOk();
    $this->withToken($token)->postJson($show, $body)->assertOk()->assertJsonPath('found', false)->assertJsonCount(0, 'data');
    GroupResourceLibrary::where('group_id', $this->group->id)->update(['visibility' => 'public']);
    $newShow = route('api.integrations.resource-commands.show', ['commandName' => 'renamed']);
    $this->withToken($token)->postJson($list, $body)->assertOk()->assertJsonPath('data.0.command_name', 'renamed')->assertJsonPath('data.0.title', 'Pending message');
    $this->withToken($token)->postJson($newShow, $body)->assertOk()->assertJsonCount(1, 'data.components');
    GroupResourceLibrary::where('group_id', $this->group->id)->update(['visibility' => 'private']);
    $this->withToken($token)->postJson($newShow, $body)->assertOk()->assertJsonCount(0, 'data.components');
    resource_action($this, $resource, 'archive')->assertOk();
    $this->withToken($token)->postJson($newShow, $body)->assertOk()->assertJsonPath('found', false)->assertJsonCount(0, 'data');
    $this->withToken($token)->postJson($list, $body)->assertJsonCount(0, 'data');
});

it('uploads protected images, enforces quota, and retains images in old revisions', function () {
    $resource = resource_create($this);
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    $upload = route('groups.dashboard.resources.images.store', $this->group);
    $image = $this->postJson($upload, ['image' => UploadedFile::fake()->image('diagram.png', 400, 400), 'resource_id' => $resource->id, 'version' => $resource->fresh()->version, 'editing_token' => $lease, 'alt_text' => 'Positions'])->assertCreated()->json('data');
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content(['body' => '![Positions]('.$image['url'].')'])])->assertOk();
    resource_action($this, $resource, 'release', ['editing_token' => $lease, 'summary' => 'Add a diagram.'])->assertOk();
    resource_action($this, $resource, 'publish')->assertOk();
    $this->get($image['url'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->getJson(route('public-resources.images.show', ['image' => $image['uuid']]))->assertNotFound();
    GroupResourceLibrary::where('group_id', $this->group->id)->update(['visibility' => 'public']);
    $this->get(route('public-resources.images.show', ['image' => $image['uuid']]))->assertOk();
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    config(['group_resources.quota_bytes' => 1]);
    $this->postJson($upload, ['image' => UploadedFile::fake()->image('extra.png'), 'resource_id' => $resource->id, 'version' => $resource->fresh()->version, 'editing_token' => $lease, 'alt_text' => ''])->assertUnprocessable();
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content()])->assertOk();
    resource_action($this, $resource, 'release', ['editing_token' => $lease, 'summary' => 'Remove live diagram.'])->assertOk();
    resource_action($this, $resource, 'publish')->assertOk();
    $this->travel(2)->days();
    expect(app(ResourceImageService::class)->cleanup())->toBe(0);
    $this->assertDatabaseHas('group_resource_images', ['uuid' => $image['uuid']]);
    $this->getJson(route('public-resources.images.show', ['image' => $image['uuid']]))->assertNotFound();
});

it('rejects oversized and invalid images without charging storage', function (string $kind) {
    $file = match ($kind) {
        'wide' => UploadedFile::fake()->image('wide.png', 4097, 2),
        'tall' => UploadedFile::fake()->image('tall.png', 2, 4097),
        'large' => UploadedFile::fake()->image('large.png')->size(5121),
        default => UploadedFile::fake()->create('bad.svg', 10, 'image/svg+xml'),
    };
    $this->postJson(route('groups.dashboard.resources.images.store', $this->group), ['image' => $file, 'alt_text' => ''])->assertUnprocessable();
    expect(GroupResourceImage::count())->toBe(0);
})->with(['wide', 'tall', 'large', 'svg']);

it('preserves GIF uploads and removes abandoned images after the grace period', function () {
    $binary = hex2bin('47494638396101000100800000000000ffffff21f904000a0000002c000000000100010000020244010021f904000a0000002c00000000010001000002024c01003b');
    $file = UploadedFile::fake()->createWithContent('animated.gif', $binary);
    $image = $this->postJson(route('groups.dashboard.resources.images.store', $this->group), ['image' => $file, 'alt_text' => 'Banner'])->assertCreated()->json('data');
    expect($image['mime_type'])->toBe('image/gif');
    expect(Storage::disk('local')->get(GroupResourceImage::where('uuid', $image['uuid'])->first()->path))->toBe($binary);
    expect(app(ResourceImageService::class)->cleanup())->toBe(0);
    $this->travel(25)->hours();
    expect(app(ResourceImageService::class)->cleanup())->toBe(1)->and(GroupResourceLibrary::first()->storage_used_bytes)->toBe(0);
});

it('searches only published content and persists multiple activity relationships', function () {
    $activities = ActivityType::factory()->count(2)->create();
    $resource = resource_create($this, ['activity_type_ids' => $activities->modelKeys()]);
    resource_publish($this, $resource);
    expect($resource->activityTypes()->count())->toBe(2)->and($resource->tags()->pluck('name')->sort()->values()->all())->toBe(['drs', 'strategy']);
    GroupResourceLibrary::where('group_id', $this->group->id)->update(['visibility' => 'public']);
    $url = route('public-resources.index', $this->group);
    $this->getJson($url.'?q=bridge&tag=drs&activity_type_id='.$activities->first()->id)->assertOk()->assertJsonPath('resources.total', 1);
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content(['title' => 'Confidential pending title'])])->assertOk();
    $this->getJson($url.'?q=Confidential')->assertJsonPath('resources.total', 0);
    $this->getJson($url.'?q=bridge')->assertJsonPath('resources.total', 1);
});

it('restores published revisions into an exclusive working copy and redirects old public slugs', function () {
    $resource = resource_create($this);
    resource_publish($this, $resource);
    $originalRevision = $resource->published_revision_id;
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content(['slug' => 'new-slug', 'title' => 'Revised guide'])])->assertOk();
    resource_action($this, $resource, 'release', ['editing_token' => $lease, 'summary' => 'Rename guide.'])->assertOk();
    resource_action($this, $resource, 'publish')->assertOk();
    GroupResourceLibrary::where('group_id', $this->group->id)->update(['visibility' => 'public']);
    $oldUrl = route('public-resources.show', ['group' => $this->group, 'slug' => 'drs-bridges']);
    $this->getJson($oldUrl)->assertRedirect(route('public-resources.show', ['group' => $this->group, 'slug' => $resource->uuid]));
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    resource_action($this, $resource, 'restore', ['editing_token' => $lease, 'revision_id' => $originalRevision])->assertOk();
    expect($resource->fresh()->working_copy['title'])->toBe('DRS Bridges')->and($resource->fresh()->publishedRevision->snapshot['title'])->toBe('Revised guide');
    resource_action($this, $resource, 'release', ['editing_token' => $lease, 'summary' => 'Restore the original guide.'])->assertOk();
    resource_action($this, $resource, 'publish')->assertOk();
    expect($resource->revisions()->count())->toBe(3);
});

it('loads saved versions without mutation and saves them as new revisions with their original attribution', function (string $state) {
    $author = Character::factory()->create(['user_id' => $this->owner->id]);
    $resource = resource_create($this, ['character_id' => $author->id]);
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content(['character_id' => $author->id])])->assertOk();
    $old = $resource->revisions()->firstOrFail();
    resource_action($this, $resource, 'release', ['editing_token' => $lease])->assertOk();
    if ($state === 'published') {
        resource_action($this, $resource, 'publish')->assertOk();
    }
    if ($state === 'discarded') {
        $old->update(['state' => 'discarded']);
    }
    $old->refresh();
    $originalSnapshot = $old->snapshot;
    $collection = GroupResourceCollection::create(['group_id' => $this->group->id, 'name' => 'Other', 'slug' => 'other']);
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    resource_action($this, $resource, 'save', [
        'editing_token' => $lease, 'collection_id' => $collection->id,
        'content' => resource_content(['title' => 'Current guide', 'character_id' => null]),
    ])->assertOk();
    resource_action($this, $resource, 'release', ['editing_token' => $lease])->assertOk();
    resource_action($this, $resource, 'publish')->assertOk();
    $publishedId = $resource->fresh()->published_revision_id;
    $version = $resource->fresh()->version;

    $moderator = User::factory()->create();
    $this->group->memberships()->create(['user_id' => $moderator->id, 'role' => 'moderator']);
    $this->actingAs($moderator);
    $response = $this->getJson(route('groups.dashboard.resources.revisions.show', ['group' => $this->group, 'resource' => $resource, 'revisionId' => $old->id]))
        ->assertOk()->assertJsonPath('data.state', $state)
        ->assertJsonPath('data.snapshot.collection_id', $this->collection->id)
        ->assertJsonPath('data.snapshot.author.id', $author->id);
    expect($resource->fresh()->version)->toBe($version)->and($resource->revisions()->count())->toBe(2);
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    $payload = [
        'editing_token' => $lease, 'source_revision_id' => $old->id,
        'content' => $response->json('data.snapshot'), 'collection_id' => $this->collection->id,
    ];
    resource_action($this, $resource, 'save', $payload + ['summary' => null])->assertUnprocessable()->assertJsonValidationErrors('summary');
    resource_action($this, $resource, 'save', $payload + ['summary' => 'Reused the original guide.'])->assertOk()
        ->assertJsonPath('data.resource.working_copy.author.id', $author->id)
        ->assertJsonPath('data.resource.working_copy.title', 'DRS Bridges');
    expect($resource->fresh()->published_revision_id)->toBe($publishedId)
        ->and($resource->publishedRevision->snapshot['title'])->toBe('Current guide')
        ->and($resource->revisions()->count())->toBe(3)
        ->and($old->fresh()->snapshot)->toBe($originalSnapshot)
        ->and($old->fresh()->state)->toBe($state);
    $new = $resource->revisions()->latest('id')->firstOrFail();
    expect($new->state)->toBe('draft')->and($new->editor_user_id)->toBe($moderator->id)
        ->and($new->snapshot['collection_id'])->toBe($this->collection->id);
})->with(['draft', 'published', 'discarded']);

it('rejects a revision source from another resource without changing the draft', function () {
    $other = resource_create($this, ['slug' => 'other', 'command' => null]);
    resource_publish($this, $other);
    $resource = resource_create($this);
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    $original = $resource->fresh()->working_copy;
    resource_action($this, $resource, 'save', [
        'editing_token' => $lease, 'source_revision_id' => $other->published_revision_id, 'content' => resource_content(),
    ])->assertNotFound();
    expect($resource->fresh()->working_copy)->toBe($original)->and($resource->revisions()->count())->toBe(0);
});

it('does not expose historical admin revisions or allow moderators to change admin access', function () {
    $resource = resource_create($this, ['access_level' => 'admin', 'body' => 'Admin secret']);
    resource_publish($this, $resource);
    $adminRevision = $resource->published_revision_id;
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content()])->assertOk();
    resource_action($this, $resource, 'release', ['editing_token' => $lease, 'summary' => 'Publish safe copy.'])->assertOk();
    resource_action($this, $resource, 'publish')->assertOk();
    $moderator = User::factory()->create();
    $this->group->memberships()->create(['user_id' => $moderator->id, 'role' => 'moderator']);
    $this->actingAs($moderator);
    $this->getJson(route('groups.dashboard.resources.revisions.show', ['group' => $this->group, 'resource' => $resource, 'revisionId' => $adminRevision]))->assertNotFound();
    $this->get(route('groups.dashboard.resources.edit', ['group' => $this->group, 'resource' => $resource]))->assertInertia(fn (Assert $page) => $page->has('resource.history', 2));
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'source_revision_id' => $adminRevision, 'content' => resource_content()])->assertNotFound();
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content(['access_level' => 'admin'])])->assertUnprocessable();
});

it('blocks moderator access when an admin-only draft exists', function () {
    $resource = resource_create($this);
    resource_publish($this, $resource);
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content(['access_level' => 'admin', 'body' => 'Pending admin secret'])])->assertOk();
    $moderator = User::factory()->create();
    $this->group->memberships()->create(['user_id' => $moderator->id, 'role' => 'moderator']);
    $this->actingAs($moderator);
    $this->get(route('groups.dashboard.resources.manage', $this->group))->assertInertia(fn (Assert $page) => $page->where('resources.total', 1)->where('resources.data.0.is_home', true));
    $this->get(route('groups.dashboard.resources.edit', ['group' => $this->group, 'resource' => $resource]))->assertForbidden();
    resource_action($this, $resource, 'publish')->assertForbidden();
});

it('ignores site admin status and preserves group bans', function () {
    $resource = resource_create($this);
    $user = User::factory()->create(['is_admin' => true]);
    $this->group->memberships()->create(['user_id' => $user->id, 'role' => 'member']);
    $this->actingAs($user);
    resource_action($this, $resource, 'acquire')->assertForbidden();
    $this->group->bans()->create(['user_id' => $user->id, 'banned_by_user_id' => $this->owner->id, 'reason' => 'Test']);
    $this->get(route('groups.dashboard.resources.index', $this->group))->assertForbidden();
});

it('does not serve authenticated application routes on the public wiki host', function () {
    $this->get('https://'.config('group_resources.public_host').'/en/groups/'.$this->group->slug.'/dashboard/content/resources')->assertNotFound();
});

it('stores branding only for admins and enforces ownership of branding assets', function () {
    $image = $this->postJson(route('groups.dashboard.resources.images.store', $this->group), ['image' => UploadedFile::fake()->image('banner.jpg'), 'alt_text' => 'Banner'])->assertCreated()->json('data');
    $url = route('groups.dashboard.resources.library.update', $this->group);
    $this->putJson($url, ['visibility' => 'public', 'customization' => ['title' => 'Group wiki', 'banner_image_id' => $image['uuid'], 'accent_color' => '#aabbcc', 'appearance' => 'dark', 'links' => [['label' => 'Website', 'url' => 'https://example.com']]]])->assertOk();
    $this->get(route('public-resources.images.show', ['image' => $image['uuid']]))->assertOk();
    $this->putJson($url, ['visibility' => 'public', 'customization' => ['css' => 'body { display: none }']])->assertUnprocessable();
    $moderator = User::factory()->create();
    $this->group->memberships()->create(['user_id' => $moderator->id, 'role' => 'moderator']);
    $this->actingAs($moderator)->putJson($url, ['visibility' => 'private', 'customization' => []])->assertForbidden();
});

it('requires resource scope and an active guild link and preserves disabled configurations', function () {
    $resource = resource_create($this);
    resource_publish($this, $resource);
    $token = IntegrationClient::makePlainApiToken();
    $client = IntegrationClient::factory()->create(['api_token_hash' => IntegrationClient::hashApiToken($token), 'scopes' => [IntegrationClient::SCOPE_RUNS_READ]]);
    $link = DiscordGuildIntegration::create(['group_id' => $this->group->id, 'discord_guild_id' => '123456', 'guild_installed_at' => now()]);
    $url = route('api.integrations.resource-commands.show', ['commandName' => 'bridges']);
    $body = ['discord_guild_id' => '123456'];
    $this->withToken($token)->postJson($url, $body)->assertForbidden();
    $client->update(['scopes' => [IntegrationClient::SCOPE_RESOURCES_READ]]);
    $link->update(['removed_at' => now()]);
    $this->withToken($token)->postJson($url, $body)->assertNotFound();
    $link->update(['removed_at' => null]);
    $this->group->features()->update(['resource_hub_enabled' => false]);
    $this->withToken($token)->postJson($url, $body)->assertNotFound();
    $this->group->features()->update(['resource_hub_enabled' => true]);
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    $content = resource_content();
    $content['commands'][0]['enabled'] = false;
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => $content])->assertOk();
    resource_action($this, $resource, 'release', ['editing_token' => $lease, 'summary' => 'Disable command.'])->assertOk();
    resource_action($this, $resource, 'publish')->assertOk();
    $this->withToken($token)->postJson($url, $body)->assertOk()->assertJsonPath('found', false)->assertJsonCount(0, 'data');
    expect($resource->fresh()->commands->first()->embed['title'])->toBe('Bridge positions')->and($resource->fresh()->commands->first()->enabled)->toBeFalse();
});

it('delivers private embed assets only through the authorized current command', function () {
    $resource = resource_create($this, ['access_level' => 'admin']);
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    $image = $this->postJson(route('groups.dashboard.resources.images.store', $this->group), ['image' => UploadedFile::fake()->image('embed.png'), 'resource_id' => $resource->id, 'version' => $resource->fresh()->version, 'editing_token' => $lease, 'alt_text' => ''])->assertCreated()->json('data');
    $content = resource_content(['access_level' => 'admin']);
    $content['commands'][0]['embed']['image'] = ['asset_id' => $image['uuid']];
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => $content])->assertOk();
    resource_action($this, $resource, 'release', ['editing_token' => $lease, 'summary' => 'Share embed image.'])->assertOk();
    resource_action($this, $resource, 'publish')->assertOk();
    $token = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()->create(['api_token_hash' => IntegrationClient::hashApiToken($token), 'scopes' => [IntegrationClient::SCOPE_RESOURCES_READ]]);
    DiscordGuildIntegration::create(['group_id' => $this->group->id, 'discord_guild_id' => '123456', 'guild_installed_at' => now()]);
    $data = $this->withToken($token)->postJson(route('api.integrations.resource-commands.show', ['commandName' => 'bridges']), ['discord_guild_id' => '123456'])->assertOk()->json('data');
    expect($data['embed']['image']['url'])->toBe('attachment://'.$image['uuid'].'.png');
    $this->withToken($token)->get($data['assets'][0]['url'])->assertOk();
    $this->withToken($token)->postJson(route('api.integrations.resource-commands.show', ['commandName' => 'bridge']), ['discord_guild_id' => '123456'])
        ->assertOk()->assertJsonPath('found', false)->assertJsonPath('data.0.command_name', 'bridges');
    $this->withToken($token)->get(route('api.integrations.resource-commands.images.show', ['discordGuildId' => '123456', 'commandName' => 'bridge', 'image' => $image['uuid']]))->assertNotFound();
    $this->getJson(route('public-resources.images.show', ['image' => $image['uuid']]))->assertNotFound();
    resource_action($this, $resource, 'unpublish')->assertOk();
    $this->withToken($token)->get($data['assets'][0]['url'])->assertNotFound();
});

it('allows deleting a group with nested resource collections without breaking the existing workflow', function () {
    $this->collection = GroupResourceCollection::create(['group_id' => $this->group->id, 'parent_id' => $this->collection->id, 'name' => 'Child', 'slug' => 'child']);
    $resource = resource_create($this);
    resource_publish($this, $resource);
    $this->group->delete();
    expect(GroupResource::count())->toBe(0)->and(GroupResourceCollection::count())->toBe(0);
});

it('prevents reusing an old admin image through a lower-access resource edit', function () {
    $resource = resource_create($this, ['access_level' => 'admin']);
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    $image = $this->postJson(route('groups.dashboard.resources.images.store', $this->group), ['image' => UploadedFile::fake()->image('secret.png'), 'resource_id' => $resource->id, 'version' => $resource->fresh()->version, 'editing_token' => $lease, 'alt_text' => ''])->assertCreated()->json('data');
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content(['access_level' => 'admin', 'body' => '![Secret]('.$image['url'].')'])])->assertOk();
    resource_action($this, $resource, 'release', ['editing_token' => $lease, 'summary' => 'Save admin diagram.'])->assertOk();
    resource_action($this, $resource, 'publish')->assertOk();
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content()])->assertOk();
    resource_action($this, $resource, 'release', ['editing_token' => $lease, 'summary' => 'Publish a safe guide.'])->assertOk();
    resource_action($this, $resource, 'publish')->assertOk();
    $moderator = User::factory()->create();
    $this->group->memberships()->create(['user_id' => $moderator->id, 'role' => 'moderator']);
    $this->actingAs($moderator)->getJson($image['url'])->assertNotFound();
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content(['body' => '![Secret]('.$image['url'].')'])])->assertUnprocessable();
});

it('resolves command access from the requested guild rather than a supplied group id', function () {
    $resource = resource_create($this);
    resource_publish($this, $resource);
    $otherGroup = Group::factory()->create();
    $otherGroup->features()->update(['resource_hub_enabled' => true]);
    DiscordGuildIntegration::create(['group_id' => $this->group->id, 'discord_guild_id' => '123456', 'guild_installed_at' => now()]);
    DiscordGuildIntegration::create(['group_id' => $otherGroup->id, 'discord_guild_id' => '654321', 'guild_installed_at' => now()]);
    $token = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()->create(['api_token_hash' => IntegrationClient::hashApiToken($token), 'scopes' => [IntegrationClient::SCOPE_RESOURCES_READ]]);

    $body = ['discord_guild_id' => '654321', 'group_id' => $this->group->id];
    $this->withToken($token)->postJson(route('api.integrations.resource-commands.index'), $body)
        ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.group_id', $otherGroup->id);
    $this->withToken($token)->postJson(route('api.integrations.resource-commands.show', ['commandName' => 'bridges']), $body)->assertOk()->assertJsonPath('found', false)->assertJsonCount(0, 'data')->assertJsonPath('meta.group_id', $otherGroup->id);
    $this->withToken($token)->postJson(route('api.integrations.resource-commands.show', ['commandName' => 'bridges']), ['discord_guild_id' => '123456'])->assertOk();
    $this->withToken($token)->postJson(route('api.integrations.resource-commands.index'), ['discord_guild_id' => '999999'])->assertNotFound();
});

it('removes the redundant client binding without changing existing guild links', function () {
    $link = DiscordGuildIntegration::create(['group_id' => $this->group->id, 'discord_guild_id' => '123456', 'guild_installed_at' => now(), 'name' => 'Existing server']);
    $before = $link->fresh()->getAttributes();
    $migration = require database_path('migrations/2026_09_09_231500_remove_client_binding_from_discord_guild_integrations.php');
    $migration->down();
    $client = IntegrationClient::factory()->create();
    DB::table('discord_guild_integrations')->where('id', $link->id)->update(['integration_client_id' => $client->id]);
    $migration->up();

    expect(Schema::hasColumn('discord_guild_integrations', 'integration_client_id'))->toBeFalse()
        ->and($link->fresh()->getAttributes())->toBe($before);
});

it('validates the guild id in the POST body for both resource endpoints', function (array $body) {
    $token = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()->create(['api_token_hash' => IntegrationClient::hashApiToken($token), 'scopes' => [IntegrationClient::SCOPE_RESOURCES_READ]]);
    $this->withToken($token)->postJson(route('api.integrations.resource-commands.index'), $body)->assertUnprocessable()->assertJsonValidationErrors('discord_guild_id');
    $this->withToken($token)->postJson(route('api.integrations.resource-commands.show', ['commandName' => 'bridges']), $body)->assertUnprocessable()->assertJsonValidationErrors('discord_guild_id');
})->with([
    [[]],
    [['discord_guild_id' => null]],
    [['discord_guild_id' => 'not-a-guild']],
    [['discord_guild_id' => str_repeat('1', 33)]],
    [['discord_guild_id' => ['123456']]],
]);

it('accepts form POST data but not a query-only guild id and lists untitled embeds explicitly', function () {
    $resource = resource_create($this, ['command' => ['name' => 'bridges', 'enabled' => true, 'embed' => ['description' => 'No embed title']]]);
    resource_publish($this, $resource);
    DiscordGuildIntegration::create(['group_id' => $this->group->id, 'discord_guild_id' => '123456', 'guild_installed_at' => now()]);
    $token = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()->create(['api_token_hash' => IntegrationClient::hashApiToken($token), 'scopes' => [IntegrationClient::SCOPE_RESOURCES_READ]]);
    $this->withToken($token)->post(route('api.integrations.resource-commands.index'), ['discord_guild_id' => '123456'])
        ->assertOk()->assertJsonPath('data.0', ['command_name' => 'bridges', 'title' => null]);
    $this->withToken($token)->postJson(route('api.integrations.resource-commands.index', ['discord_guild_id' => '123456']), [])
        ->assertUnprocessable()->assertJsonValidationErrors('discord_guild_id');
});

it('reserves the list command name for the list endpoint', function () {
    $content = resource_content(['command' => ['name' => 'LiSt', 'enabled' => true, 'embed' => ['title' => 'Reserved name']]]);
    $this->postJson(route('groups.dashboard.resources.store', $this->group), ['collection_id' => $this->collection->id, 'content' => $content])
        ->assertUnprocessable()->assertJsonValidationErrors('commands.0.name');
});

it('publishes multiple independent commands and paginates them without changing bot payloads', function () {
    $commands = [
        ['name' => 'west', 'enabled' => true, 'embed' => ['title' => 'West bridge', 'description' => str_repeat('w', 3500)]],
        ['name' => 'east', 'enabled' => true, 'embed' => ['title' => 'East bridge', 'description' => str_repeat('e', 3500)]],
        ['name' => 'backup', 'enabled' => false, 'embed' => ['title' => 'Backup plan']],
    ];
    $resource = resource_create($this, ['commands' => $commands]);
    resource_publish($this, $resource);
    expect($resource->commands()->count())->toBe(3);
    DiscordGuildIntegration::create(['group_id' => $this->group->id, 'discord_guild_id' => '123456', 'guild_installed_at' => now()]);
    $token = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()->create(['api_token_hash' => IntegrationClient::hashApiToken($token), 'scopes' => [IntegrationClient::SCOPE_RESOURCES_READ]]);
    $body = ['discord_guild_id' => '123456'];
    $this->withToken($token)->postJson(route('api.integrations.resource-commands.index'), $body + ['per_page' => 1])
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.command_name', 'east')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.next_page', 2);
    $show = fn ($name) => $this->withToken($token)->postJson(route('api.integrations.resource-commands.show', ['commandName' => $name]), $body);
    $show('west')->assertOk()->assertJsonPath('data.embed.title', 'West bridge')->assertJsonPath('data.embed.footer.text', 'FullParty')->assertJsonMissingPath('data.embeds');
    $show('east')->assertOk()->assertJsonPath('data.embed.title', 'East bridge');
    $show('backup')->assertOk()->assertJsonPath('found', false)->assertJsonCount(0, 'data');

    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content(['commands' => [$commands[0]]])])->assertOk();
    $show('east')->assertOk();
    resource_action($this, $resource, 'release', ['editing_token' => $lease])->assertOk();
    resource_action($this, $resource, 'publish')->assertOk();
    $show('east')->assertOk()->assertJsonPath('found', false)->assertJsonCount(0, 'data');
    $show('west')->assertOk();
    expect($resource->commands()->pluck('name')->all())->toBe(['west'])
        ->and($resource->revisions()->oldest('id')->first()->snapshot['commands'])->toHaveCount(3);
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content(['commands' => []])])->assertOk();
    resource_action($this, $resource, 'release', ['editing_token' => $lease])->assertOk();
    resource_action($this, $resource, 'publish')->assertOk();
    $show('west')->assertOk()->assertJsonPath('found', false)->assertJsonCount(0, 'data');
    expect($resource->commands()->count())->toBe(0);
});

it('validates every embed and reserves every command name within and across resources', function () {
    $command = fn ($name) => ['name' => $name, 'enabled' => true, 'embed' => ['title' => $name]];
    $store = fn ($commands, $slug = 'drs-bridges') => $this->postJson(route('groups.dashboard.resources.store', $this->group), ['content' => resource_content(['slug' => $slug, 'commands' => $commands])]);
    $store([$command('west'), $command('WEST')])->assertUnprocessable()->assertJsonValidationErrors('commands.1.name');
    $store([$command('west'), $command('list')])->assertUnprocessable()->assertJsonValidationErrors('commands.1.name');
    $bad = $command('east');
    $bad['embed']['footer'] = ['text' => 'Spoofed'];
    $store([$command('west'), $bad])->assertUnprocessable()->assertJsonValidationErrors('commands.1.embed');
    $store([$command('west'), []])->assertUnprocessable();
    $store(array_fill(0, 16, $command('west')))->assertUnprocessable()->assertJsonValidationErrors('commands');
    $store([$command('west'), $command('east')])->assertCreated();
    $store([$command('new'), $command('EAST')], 'other')->assertUnprocessable()->assertJsonValidationErrors('content.commands.1.name');
});

it('accepts fifteen embeds but rejects a sixteenth on creation and saving without changing published commands', function () {
    $commands = array_map(fn ($index) => ['name' => 'plan-'.$index, 'enabled' => true, 'embed' => ['title' => 'Plan '.$index]], range(1, 16));
    $this->postJson(route('groups.dashboard.resources.store', $this->group), ['content' => resource_content(['commands' => $commands])])
        ->assertUnprocessable()->assertJsonValidationErrors('commands');
    $resource = resource_create($this, ['commands' => array_slice($commands, 0, 15)]);
    resource_publish($this, $resource);
    expect($resource->commands()->count())->toBe(15);
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    $version = $resource->fresh()->version;
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content(['commands' => $commands])])
        ->assertUnprocessable()->assertJsonValidationErrors('commands');
    expect($resource->fresh()->version)->toBe($version)
        ->and($resource->fresh()->working_copy['commands'])->toHaveCount(15)
        ->and($resource->commands()->count())->toBe(15);
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content(['commands' => array_slice($commands, 0, 15)])])->assertOk();
});

it('prevents older oversized drafts from bypassing the embed limit on direct publication', function () {
    $resource = resource_create($this);
    resource_publish($this, $resource);
    $publishedId = $resource->published_revision_id;
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    $snapshot = $resource->fresh()->working_copy;
    $snapshot['commands'] = array_map(fn ($index) => ['name' => 'plan-'.$index, 'enabled' => true, 'embed' => ['title' => 'Plan '.$index]], range(1, 16));
    $resource->update(['working_copy' => $snapshot]);
    resource_action($this, $resource, 'publish', ['editing_token' => $lease])->assertUnprocessable()->assertJsonValidationErrors('commands');
    resource_action($this, $resource, 'release', ['editing_token' => $lease])->assertOk();
    resource_action($this, $resource, 'publish')->assertUnprocessable()->assertJsonValidationErrors('commands');
    expect($resource->fresh()->published_revision_id)->toBe($publishedId)
        ->and($resource->fresh()->working_copy['commands'])->toHaveCount(16)
        ->and($resource->commands()->pluck('name')->all())->toBe(['bridges']);
});

it('forces embed attribution and edit timestamps regardless of supplied metadata', function () {
    $this->freezeTime();
    $this->group->update(['profile_picture_url' => '/storage/group.png']);
    GroupResourceLibrary::where('group_id', $this->group->id)->update(['visibility' => 'public']);
    $commands = [['name' => 'plan', 'enabled' => true, 'updated_at' => '2000-01-01T00:00:00Z', 'embed' => [
        'title' => 'Plan', 'timestamp' => 'not-a-date', 'author' => ['name' => 'Forged', 'url' => 'javascript:alert(1)', 'icon_url' => 'https://example.com/forged.png'],
    ]]];
    $resource = resource_create($this, ['commands' => $commands]);
    $saved = $resource->working_copy['commands'][0];
    $url = route('public-resources.show', ['group' => $this->group, 'slug' => $resource->uuid]);
    expect($saved['updated_at'])->toBe(now()->toIso8601String())
        ->and($saved['embed']['timestamp'])->toBe($saved['updated_at'])
        ->and($saved['embed']['author'])->toBe(['name' => 'DRS Bridges', 'url' => $url, 'icon_url' => rtrim(config('app.url'), '/').'/storage/group.png']);
    $context = app(ResourceReaderService::class)->workspace($this->group, $this->owner)['embed_context'];
    expect($context['group_icon_url'])->toBe($saved['embed']['author']['icon_url'])
        ->and($context['public_base_url'])->toBe(route('public-resources.index', ['group' => $this->group]));
});

it('only advances the timestamp of an edited embed and treats restored content as a new edit', function () {
    $this->freezeTime();
    $commands = array_map(fn ($name) => ['name' => $name, 'enabled' => true, 'embed' => ['title' => $name]], ['west', 'east']);
    $resource = resource_create($this, ['commands' => $commands]);
    $initial = $resource->working_copy['commands'][0]['updated_at'];
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    $this->travel(1)->minutes();
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content(['body' => 'Different body', 'title' => 'New resource title', 'commands' => $commands])])->assertOk();
    expect(array_column($resource->fresh()->working_copy['commands'], 'updated_at'))->toBe([$initial, $initial]);
    $original = $resource->revisions()->firstOrFail();
    $this->travel(1)->minutes();
    $commands[0]['embed']['description'] = 'Updated west';
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content(['commands' => $commands])])->assertOk();
    $edited = now()->toIso8601String();
    expect(array_column($resource->fresh()->working_copy['commands'], 'updated_at'))->toBe([$edited, $initial]);
    $this->travel(1)->minutes();
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'source_revision_id' => $original->id, 'content' => $original->snapshot])->assertOk();
    $restored = now()->toIso8601String();
    expect(array_column($resource->fresh()->working_copy['commands'], 'updated_at'))->toBe([$restored, $initial]);
    $this->travel(1)->minutes();
    resource_action($this, $resource, 'release', ['editing_token' => $lease])->assertOk();
    resource_action($this, $resource, 'publish')->assertOk();
    expect($resource->fresh()->publishedRevision->snapshot['commands'][0]['updated_at'])->toBe($restored);
});

it('bot attribution follows published content and current group settings without leaking pending resource titles', function () {
    $this->freezeTime();
    $this->group->update(['profile_picture_url' => 'https://example.com/group.png']);
    GroupResourceLibrary::where('group_id', $this->group->id)->update(['visibility' => 'public']);
    $resource = resource_create($this);
    $savedAt = $resource->working_copy['commands'][0]['updated_at'];
    resource_publish($this, $resource);
    DiscordGuildIntegration::create(['group_id' => $this->group->id, 'discord_guild_id' => '123456', 'guild_installed_at' => now()]);
    $token = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()->create(['api_token_hash' => IntegrationClient::hashApiToken($token), 'scopes' => [IntegrationClient::SCOPE_RESOURCES_READ]]);
    $fetch = fn () => $this->withToken($token)->postJson(route('api.integrations.resource-commands.show', ['commandName' => 'bridges']), ['discord_guild_id' => '123456']);
    $publicUrl = route('public-resources.show', ['group' => $this->group, 'slug' => $resource->uuid]);
    $fetch()->assertOk()->assertJsonPath('data.embed.author.name', 'DRS Bridges')->assertJsonPath('data.embed.author.url', $publicUrl)
        ->assertJsonPath('data.embed.author.icon_url', 'https://example.com/group.png')->assertJsonPath('data.embed.timestamp', $savedAt);
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    $this->travel(1)->minutes();
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content(['title' => 'Pending title', 'access_level' => 'moderator'])])->assertOk();
    $fetch()->assertOk()->assertJsonPath('data.embed.author.name', 'DRS Bridges');
    $this->group->update(['profile_picture_url' => null]);
    GroupResourceLibrary::where('group_id', $this->group->id)->update(['visibility' => 'private']);
    $fetch()->assertOk()->assertJsonMissingPath('data.embed.author.url')->assertJsonMissingPath('data.embed.author.icon_url')->assertJsonCount(0, 'data.components');
    GroupResourceLibrary::where('group_id', $this->group->id)->update(['visibility' => 'public']);
    resource_action($this, $resource, 'publish', ['editing_token' => $lease])->assertOk();
    $fetch()->assertOk()->assertJsonPath('data.embed.author.name', 'Pending title')->assertJsonMissingPath('data.embed.author.url')->assertJsonPath('data.embed.timestamp', $savedAt);
});

it('counts the forced resource author toward the Discord embed text limit', function () {
    $command = ['name' => 'plan', 'enabled' => true, 'embed' => ['description' => str_repeat('a', 4096), 'fields' => [['name' => 'A', 'value' => str_repeat('b', 1024)], ['name' => 'B', 'value' => str_repeat('c', 670)]]]];
    $this->postJson(route('groups.dashboard.resources.store', $this->group), ['content' => resource_content(['title' => str_repeat('R', 200), 'commands' => [$command]])])
        ->assertUnprocessable()->assertJsonValidationErrors('commands.0.embed');
});

it('tracks assets from every embed and keeps the lightweight tree payload free of full embeds', function () {
    $resource = resource_create($this, ['commands' => []]);
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    $images = [];
    foreach (['west', 'east'] as $name) {
        $images[] = $this->postJson(route('groups.dashboard.resources.images.store', $this->group), [
            'image' => UploadedFile::fake()->image($name.'.png'), 'resource_id' => $resource->id,
            'version' => $resource->fresh()->version, 'editing_token' => $lease, 'alt_text' => $name,
        ])->assertCreated()->json('data');
    }
    $commands = array_map(fn ($image, $index) => ['name' => 'side-'.$index, 'enabled' => true, 'embed' => ['title' => 'Side '.$index, 'image' => ['asset_id' => $image['uuid']]]], $images, [0, 1]);
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content(['commands' => $commands])])->assertOk();
    expect($resource->fresh()->working_copy['image_ids'])->toBe(array_column($images, 'uuid'));
    $workspace = app(ResourceReaderService::class)->workspace($this->group, $this->owner);
    $summary = collect($workspace['resources'])->firstWhere('id', $resource->id);
    expect($summary['commands'])->toBe([['name' => 'side-0', 'enabled' => true], ['name' => 'side-1', 'enabled' => true]]);
    resource_action($this, $resource, 'release', ['editing_token' => $lease])->assertOk();
    resource_action($this, $resource, 'publish')->assertOk();
    DiscordGuildIntegration::create(['group_id' => $this->group->id, 'discord_guild_id' => '123456', 'guild_installed_at' => now()]);
    $token = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()->create(['api_token_hash' => IntegrationClient::hashApiToken($token), 'scopes' => [IntegrationClient::SCOPE_RESOURCES_READ]]);
    $payload = $this->withToken($token)->postJson(route('api.integrations.resource-commands.show', ['commandName' => 'side-1']), ['discord_guild_id' => '123456'])->assertOk()->json('data');
    expect($payload['assets'])->toHaveCount(1)->and($payload['assets'][0]['id'])->toBe($images[1]['uuid']);
    $this->withToken($token)->get($payload['assets'][0]['url'])->assertOk();
    $this->withToken($token)->get(route('api.integrations.resource-commands.images.show', ['discordGuildId' => '123456', 'commandName' => 'side-1', 'image' => $images[0]['uuid']]))->assertNotFound();
});
