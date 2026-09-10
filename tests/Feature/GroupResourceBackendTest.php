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
use App\Services\Groups\Resources\ResourceWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function resource_content(array $overrides = []): array
{
    return array_replace([
        'title' => 'DRS Bridges', 'slug' => 'drs-bridges', 'description' => 'Bridge strategy', 'body' => '# Bridge guide',
        'access_level' => 'everyone', 'tags' => ['DRS', ' Strategy ', 'drs'], 'activity_type_ids' => [],
        'command' => ['name' => 'Bridges', 'enabled' => true, 'embed' => ['title' => 'Bridge positions', 'description' => 'Manually written embed']],
    ], $overrides);
}

function resource_create($test, array $overrides = []): GroupResource
{
    return app(ResourceWorkflowService::class)->create($test->group, $test->owner, ['collection_id' => $test->collection->id, 'content' => resource_content($overrides)]);
}

function resource_action($test, GroupResource $resource, string $operation, array $data = [])
{
    $resource->refresh();

    return $test->postJson(rtrim(config('app.url'), '/').route('groups.dashboard.resources.update', ['group' => $test->group, 'resource' => $resource, 'operation' => $operation], false), ['version' => $resource->version] + $data);
}

function resource_publish($test, GroupResource $resource): void
{
    $lease = resource_action($test, $resource, 'acquire')->assertOk()->json('data.editing_token');
    resource_action($test, $resource, 'submit', ['editing_token' => $lease, 'summary' => 'Initial guide.'])->assertOk();
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

it('creates a private library and sanitized draft with normalized tags and independent embed', function () {
    $response = $this->postJson(route('groups.dashboard.resources.store', $this->group), ['collection_id' => $this->collection->id, 'content' => resource_content(['body' => '<script>alert(1)</script> [bad](javascript:alert)'])])->assertCreated();
    $resource = GroupResource::findOrFail($response->json('data.id'));
    expect($resource->status)->toBe('draft')->and($resource->working_copy['tags'])->toBe(['drs', 'strategy'])
        ->and($resource->working_copy['body'])->not->toContain('<script>', 'javascript:')
        ->and($resource->working_copy['command']['name'])->toBe('bridges')
        ->and(GroupResourceLibrary::first()->visibility)->toBe('private');
    $this->assertDatabaseHas('audit_logs', ['action' => 'group.resources.created']);
});

it('keeps published content live until pending changes are published and blocks all new edits', function () {
    $resource = resource_create($this);
    resource_publish($this, $resource);
    $lease = resource_action($this, $resource, 'acquire')->assertOk()->json('data.editing_token');
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content(['title' => 'Updated bridges'])])->assertOk();
    resource_action($this, $resource, 'submit', ['editing_token' => $lease, 'summary' => 'Clarified bridge instructions.'])->assertOk();
    resource_action($this, $resource, 'acquire')->assertConflict();
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content()])->assertConflict();
    $this->get(route('groups.dashboard.resources.show', ['group' => $this->group, 'slug' => $resource->slug]))->assertInertia(fn (Assert $page) => $page->where('resource.title', 'DRS Bridges'));
    $this->travel(2)->days();
    resource_action($this, $resource, 'acquire')->assertConflict();
    resource_action($this, $resource, 'publish')->assertOk();
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

it('lets another authorized manager publish or discard without an approver role', function () {
    $resource = resource_create($this);
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    resource_action($this, $resource, 'submit', ['editing_token' => $lease, 'summary' => 'Ready for publication.'])->assertOk();
    $moderator = User::factory()->create();
    $this->group->memberships()->create(['user_id' => $moderator->id, 'role' => 'moderator']);
    $this->actingAs($moderator);
    resource_action($this, $resource, 'publish')->assertOk();
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    resource_action($this, $resource, 'submit', ['editing_token' => $lease, 'summary' => 'Pending revision.'])->assertOk();
    resource_action($this, $resource, 'discard')->assertOk();
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
        $this->get(route('groups.dashboard.resources.show', ['group' => $this->group, 'slug' => $level]))->assertStatus(in_array($level, $readable) ? 200 : 404);
        $resource = GroupResource::where('slug', $level)->first();
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
    $this->getJson(route('public-resources.index', $this->group))->assertOk()->assertJsonCount(0, 'collections')->assertJsonPath('resources.total', 0)->assertJsonMissingPath('auth');
    $this->getJson(route('public-resources.show', ['group' => $this->group, 'slug' => $resource->slug]))->assertNotFound();
    $this->getJson(route('public-resources.collections.show', ['group' => $this->group, 'collectionSlug' => 'secret']))->assertNotFound();
    $public = resource_create($this, ['slug' => 'open-guide', 'command' => null]);
    resource_publish($this, $public);
    $this->getJson(route('public-resources.index', $this->group))->assertOk()->assertJsonCount(2, 'collections')->assertJsonPath('resources.total', 1);
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
    $this->postJson(route('groups.dashboard.resources.store', $this->group), ['collection_id' => $this->collection->id, 'content' => resource_content(['slug' => 'another', 'command' => ['name' => 'BRIDGES', 'enabled' => false, 'embed' => ['title' => 'Other']]])])->assertUnprocessable()->assertJsonValidationErrors('content.command.name');
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
    resource_action($this, $resource, 'submit', ['editing_token' => $lease, 'summary' => 'Share a public version.'])->assertOk();
    $this->withToken($token)->postJson($list, $body)->assertOk()->assertJsonPath('data.0.command_name', 'bridges')->assertJsonPath('data.0.title', 'Bridge positions');
    $this->withToken($token)->postJson($show, $body)->assertOk()->assertJsonPath('data.embed.title', 'Bridge positions');
    resource_action($this, $resource, 'publish')->assertOk();
    $this->withToken($token)->postJson($show, $body)->assertNotFound();
    GroupResourceLibrary::where('group_id', $this->group->id)->update(['visibility' => 'public']);
    $newShow = route('api.integrations.resource-commands.show', ['commandName' => 'renamed']);
    $this->withToken($token)->postJson($list, $body)->assertOk()->assertJsonPath('data.0.command_name', 'renamed')->assertJsonPath('data.0.title', 'Pending message');
    $this->withToken($token)->postJson($newShow, $body)->assertOk()->assertJsonCount(1, 'data.components');
    GroupResourceLibrary::where('group_id', $this->group->id)->update(['visibility' => 'private']);
    $this->withToken($token)->postJson($newShow, $body)->assertOk()->assertJsonCount(0, 'data.components');
    resource_action($this, $resource, 'archive')->assertOk();
    $this->withToken($token)->postJson($newShow, $body)->assertNotFound();
    $this->withToken($token)->postJson($list, $body)->assertJsonCount(0, 'data');
});

it('uploads protected images, enforces quota, and retains images in old revisions', function () {
    $resource = resource_create($this);
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    $upload = route('groups.dashboard.resources.images.store', $this->group);
    $image = $this->postJson($upload, ['image' => UploadedFile::fake()->image('diagram.png', 400, 400), 'resource_id' => $resource->id, 'version' => $resource->fresh()->version, 'editing_token' => $lease, 'alt_text' => 'Positions'])->assertCreated()->json('data');
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content(['body' => '![Positions]('.$image['url'].')'])])->assertOk();
    resource_action($this, $resource, 'submit', ['editing_token' => $lease, 'summary' => 'Add a diagram.'])->assertOk();
    resource_action($this, $resource, 'publish')->assertOk();
    $this->get($image['url'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->getJson(route('public-resources.images.show', ['image' => $image['uuid']]))->assertNotFound();
    GroupResourceLibrary::where('group_id', $this->group->id)->update(['visibility' => 'public']);
    $this->get(route('public-resources.images.show', ['image' => $image['uuid']]))->assertOk();
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    config(['group_resources.quota_bytes' => 1]);
    $this->postJson($upload, ['image' => UploadedFile::fake()->image('extra.png'), 'resource_id' => $resource->id, 'version' => $resource->fresh()->version, 'editing_token' => $lease, 'alt_text' => ''])->assertUnprocessable();
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content()])->assertOk();
    resource_action($this, $resource, 'submit', ['editing_token' => $lease, 'summary' => 'Remove live diagram.'])->assertOk();
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
    resource_action($this, $resource, 'submit', ['editing_token' => $lease, 'summary' => 'Rename guide.'])->assertOk();
    resource_action($this, $resource, 'publish')->assertOk();
    GroupResourceLibrary::where('group_id', $this->group->id)->update(['visibility' => 'public']);
    $oldUrl = route('public-resources.show', ['group' => $this->group, 'slug' => 'drs-bridges']);
    $this->getJson($oldUrl)->assertRedirect(route('public-resources.show', ['group' => $this->group, 'slug' => 'new-slug']));
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    resource_action($this, $resource, 'restore', ['editing_token' => $lease, 'revision_id' => $originalRevision])->assertOk();
    expect($resource->fresh()->working_copy['title'])->toBe('DRS Bridges')->and($resource->fresh()->publishedRevision->snapshot['title'])->toBe('Revised guide');
    resource_action($this, $resource, 'submit', ['editing_token' => $lease, 'summary' => 'Restore the original guide.'])->assertOk();
    resource_action($this, $resource, 'publish')->assertOk();
    expect($resource->revisions()->count())->toBe(3);
});

it('does not expose historical admin revisions or allow moderators to change admin access', function () {
    $resource = resource_create($this, ['access_level' => 'admin', 'body' => 'Admin secret']);
    resource_publish($this, $resource);
    $adminRevision = $resource->published_revision_id;
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content()])->assertOk();
    resource_action($this, $resource, 'submit', ['editing_token' => $lease, 'summary' => 'Publish safe copy.'])->assertOk();
    resource_action($this, $resource, 'publish')->assertOk();
    $moderator = User::factory()->create();
    $this->group->memberships()->create(['user_id' => $moderator->id, 'role' => 'moderator']);
    $this->actingAs($moderator);
    $this->getJson(route('groups.dashboard.resources.revisions.show', ['group' => $this->group, 'resource' => $resource, 'revisionId' => $adminRevision]))->assertNotFound();
    $this->get(route('groups.dashboard.resources.edit', ['group' => $this->group, 'resource' => $resource]))->assertInertia(fn (Assert $page) => $page->has('resource.history', 1));
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content(['access_level' => 'admin'])])->assertUnprocessable();
});

it('blocks moderator access when an admin-only working or pending copy exists', function () {
    $resource = resource_create($this);
    resource_publish($this, $resource);
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content(['access_level' => 'admin', 'body' => 'Pending admin secret'])])->assertOk();
    $moderator = User::factory()->create();
    $this->group->memberships()->create(['user_id' => $moderator->id, 'role' => 'moderator']);
    $this->actingAs($moderator);
    $this->get(route('groups.dashboard.resources.manage', $this->group))->assertInertia(fn (Assert $page) => $page->where('resources.total', 0));
    $this->get(route('groups.dashboard.resources.edit', ['group' => $this->group, 'resource' => $resource]))->assertForbidden();
    resource_action($this, $resource, 'discard')->assertForbidden();
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
    $content['command']['enabled'] = false;
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => $content])->assertOk();
    resource_action($this, $resource, 'submit', ['editing_token' => $lease, 'summary' => 'Disable command.'])->assertOk();
    resource_action($this, $resource, 'publish')->assertOk();
    $this->withToken($token)->postJson($url, $body)->assertNotFound();
    expect($resource->fresh()->command->embed['title'])->toBe('Bridge positions')->and($resource->fresh()->command->enabled)->toBeFalse();
});

it('delivers private embed assets only through the authorized current command', function () {
    $resource = resource_create($this, ['access_level' => 'admin']);
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    $image = $this->postJson(route('groups.dashboard.resources.images.store', $this->group), ['image' => UploadedFile::fake()->image('embed.png'), 'resource_id' => $resource->id, 'version' => $resource->fresh()->version, 'editing_token' => $lease, 'alt_text' => ''])->assertCreated()->json('data');
    $content = resource_content(['access_level' => 'admin']);
    $content['command']['embed']['image'] = ['asset_id' => $image['uuid']];
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => $content])->assertOk();
    resource_action($this, $resource, 'submit', ['editing_token' => $lease, 'summary' => 'Share embed image.'])->assertOk();
    resource_action($this, $resource, 'publish')->assertOk();
    $token = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()->create(['api_token_hash' => IntegrationClient::hashApiToken($token), 'scopes' => [IntegrationClient::SCOPE_RESOURCES_READ]]);
    DiscordGuildIntegration::create(['group_id' => $this->group->id, 'discord_guild_id' => '123456', 'guild_installed_at' => now()]);
    $data = $this->withToken($token)->postJson(route('api.integrations.resource-commands.show', ['commandName' => 'bridges']), ['discord_guild_id' => '123456'])->assertOk()->json('data');
    expect($data['embed']['image']['url'])->toBe('attachment://'.$image['uuid'].'.png');
    $this->withToken($token)->get($data['assets'][0]['url'])->assertOk();
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
    resource_action($this, $resource, 'submit', ['editing_token' => $lease, 'summary' => 'Save admin diagram.'])->assertOk();
    resource_action($this, $resource, 'publish')->assertOk();
    $lease = resource_action($this, $resource, 'acquire')->json('data.editing_token');
    resource_action($this, $resource, 'save', ['editing_token' => $lease, 'content' => resource_content()])->assertOk();
    resource_action($this, $resource, 'submit', ['editing_token' => $lease, 'summary' => 'Publish a safe guide.'])->assertOk();
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
    $this->withToken($token)->postJson(route('api.integrations.resource-commands.show', ['commandName' => 'bridges']), $body)->assertNotFound();
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
        ->assertUnprocessable()->assertJsonValidationErrors('command.name');
});
