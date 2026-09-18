<?php

use App\Models\DiscordGuildIntegration;
use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceImage;
use App\Models\GroupResourceLibrary;
use App\Models\IntegrationClient;
use App\Models\User;
use App\Services\Groups\Resources\ResourceAudit;
use App\Services\Groups\Resources\ResourceImageService;
use App\Services\Groups\Resources\ResourceLibraryDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->group = Group::factory()->create();
    $this->group->features()->update(['resource_hub_enabled' => true]);
    $this->library = GroupResourceLibrary::create(['group_id' => $this->group->id, 'visibility' => 'public']);
    $this->actingAs($this->group->owner);
    $this->indexUrl = route('groups.dashboard.resources.images.index', $this->group);
    $this->storeUrl = route('groups.dashboard.resources.images.store', $this->group);
    Storage::fake('local');
});

function library_upload($test, array $data = []): GroupResourceImage
{
    $response = $test->postJson($test->storeUrl, $data + [
        'image' => UploadedFile::fake()->image('positions.png', 40, 30), 'alt_text' => 'Positions', 'library_upload' => true,
    ])->assertCreated();

    return GroupResourceImage::where('uuid', $response->json('data.uuid'))->firstOrFail();
}

function library_image_url($test, GroupResourceImage $image, string $action = 'destroy'): string
{
    return rtrim(config('app.url'), '/').route('groups.dashboard.resources.images.'.$action, ['group' => $test->group, 'image' => $image->uuid], false);
}

it('lists searchable paginated uploads without exposing storage paths', function () {
    $first = library_upload($this);
    $second = library_upload($this, ['image' => UploadedFile::fake()->image('healers.png'), 'alt_text' => 'Healing']);
    $this->getJson($this->indexUrl.'?per_page=1')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('last_page', 2)
        ->assertJsonPath('data.0.uuid', $second->uuid)->assertJsonPath('data.0.name', 'healers.png')
        ->assertJsonPath('data.0.uploader', $this->group->owner->name)->assertJsonMissingPath('data.0.path');
    $this->getJson($this->indexUrl.'?per_page=1&page=2')->assertJsonPath('data.0.uuid', $first->uuid);
    $this->getJson($this->indexUrl.'?q=POSITIONS')->assertJsonPath('total', 1)->assertJsonPath('data.0.uuid', $first->uuid);
    $this->getJson($this->indexUrl.'?per_page=100')->assertUnprocessable();
    $this->getJson($this->indexUrl.'?type=svg')->assertUnprocessable();
});

it('keeps explicit library uploads through cleanup and preserves animated GIF bytes', function () {
    $binary = hex2bin('47494638396101000100800000000000ffffff21f904000a0000002c000000000100010000020244010021f904000a0000002c00000000010001000002024c01003b');
    $image = library_upload($this, ['image' => UploadedFile::fake()->createWithContent('animated.gif', $binary)]);
    library_upload($this);
    $this->getJson($this->indexUrl.'?type=gif')->assertJsonPath('total', 1)->assertJsonPath('data.0.uuid', $image->uuid);
    $this->getJson($this->indexUrl.'?type=image')->assertJsonPath('total', 1);
    $this->travel(2)->days();
    expect(app(ResourceImageService::class)->cleanup())->toBe(0);
    expect(Storage::disk('local')->get($image->path))->toBe($binary);
});

it('supports explicitly filtering to unscoped group images before pagination', function () {
    $first = library_upload($this);
    $second = library_upload($this);
    $restricted = library_upload($this);
    $resource = GroupResource::factory()->create(['group_id' => $this->group->id]);
    $restricted->update(['resource_id' => $resource->id, 'library_upload' => false]);
    $this->getJson($this->indexUrl.'?library_only=1&per_page=1')->assertOk()
        ->assertJsonPath('total', 2)->assertJsonPath('data.0.uuid', $second->uuid);
    $this->getJson($this->indexUrl.'?library_only=1&per_page=1&page=2')->assertOk()
        ->assertJsonPath('total', 2)->assertJsonPath('data.0.uuid', $first->uuid);
    $this->getJson($this->indexUrl.'?library_only=0')->assertJsonPath('total', 3);
    $this->getJson($this->indexUrl.'?library_only=invalid')->assertUnprocessable();
});

it('reuses existing resource images for branding and preserves them when the source resource is deleted', function (string $field) {
    $resource = GroupResource::factory()->create(['group_id' => $this->group->id]);
    $image = library_upload($this);
    $image->update(['resource_id' => $resource->id, 'library_upload' => false]);
    $publicUrl = route('public-resources.images.show', ['image' => $image->uuid]);
    $settingsUrl = route('groups.dashboard.resources.library.update', $this->group);

    $this->getJson($this->indexUrl)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.uuid', $image->uuid);
    $this->getJson($publicUrl)->assertNotFound();
    $this->putJson($settingsUrl, [
        'visibility' => 'public', 'customization' => [$field => $image->uuid],
    ])->assertOk()->assertJsonPath('data.customization.'.$field, $image->uuid);
    $this->get($publicUrl)->assertOk();
    $this->deleteJson(library_image_url($this, $image))->assertUnprocessable()->assertJsonValidationErrors('image');

    app(ResourceLibraryDeletionService::class)->deleteResource($this->group, $resource, $this->group->owner, ['version' => $resource->fresh()->version]);
    expect($image->fresh()->resource_id)->toBeNull()->and($image->fresh()->library_upload)->toBeTrue();
    expect($this->library->fresh()->storage_used_bytes)->toBe($image->size_bytes);
    Storage::disk('local')->assertExists($image->path);
    $this->get($publicUrl)->assertOk();
    $this->travel(2)->days();
    expect(app(ResourceImageService::class)->cleanup())->toBe(0);

    $this->library->update(['visibility' => 'private']);
    $this->getJson($publicUrl)->assertNotFound();
})->with(['banner_image_id', 'logo_image_id', 'sharing_image_id']);

it('lets moderators manage group uploads but not admin images or historical admin images', function () {
    $secret = library_upload($this, ['library_upload' => false]);
    $oldSecret = library_upload($this);
    $resource = GroupResource::factory()->create(['group_id' => $this->group->id, 'working_copy' => ['image_ids' => []]]);
    $oldSecret->update(['resource_id' => $resource->id, 'access_level' => 'admin', 'library_upload' => false]);
    $resource->revisions()->create(['editor' => [], 'summary' => 'Secret', 'state' => 'published', 'snapshot' => ['access_level' => 'admin', 'image_ids' => [$oldSecret->uuid]]]);
    $moderator = User::factory()->create();
    $this->group->memberships()->create(['user_id' => $moderator->id, 'role' => 'moderator']);
    $this->actingAs($moderator);
    $public = library_upload($this);
    $this->getJson($this->indexUrl)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.uuid', $public->uuid);
    foreach ([$secret, $oldSecret] as $image) {
        $this->getJson('/resource-assets/'.$image->uuid)->assertNotFound();
        $this->deleteJson(library_image_url($this, $image))->assertForbidden();
        $this->putJson(library_image_url($this, $image, 'update'), ['name' => 'Secret', 'alt_text' => ''])->assertForbidden();
    }
    $this->putJson(library_image_url($this, $public, 'update'), ['name' => 'Bridge diagram', 'alt_text' => 'West and east', 'caption' => 'Party positions'])
        ->assertOk()->assertJsonPath('data.name', 'Bridge diagram')->assertJsonPath('data.alt_text', 'West and east');
    $this->assertDatabaseHas('audit_logs', ['action' => 'group.resources.image_updated']);
});

it('denies members and banned moderators access to upload management', function (string $role, bool $banned) {
    $image = library_upload($this);
    $user = User::factory()->create();
    $this->group->memberships()->create(['user_id' => $user->id, 'role' => $role]);
    if ($banned) {
        $this->group->bans()->create(['user_id' => $user->id, 'banned_by_user_id' => $this->group->owner_id, 'reason' => 'Test']);
    }
    $this->actingAs($user)->getJson($this->indexUrl)->assertForbidden();
    $this->postJson($this->storeUrl, ['image' => UploadedFile::fake()->image('test.png'), 'alt_text' => '', 'library_upload' => true])->assertForbidden();
    $this->deleteJson(library_image_url($this, $image))->assertForbidden();
})->with([['member', false], ['moderator', true]]);

it('isolates uploads and target resources between groups', function () {
    $image = library_upload($this);
    $other = Group::factory()->create();
    $other->features()->update(['resource_hub_enabled' => true]);
    GroupResourceLibrary::create(['group_id' => $other->id]);
    $this->actingAs($other->owner)->getJson(route('groups.dashboard.resources.images.index', $other))->assertJsonPath('total', 0);
    $this->deleteJson(route('groups.dashboard.resources.images.destroy', ['group' => $other, 'image' => $image->uuid]))->assertNotFound();
    $resource = GroupResource::factory()->create(['group_id' => $other->id]);
    $this->actingAs($this->group->owner)->getJson($this->indexUrl.'?resource_id='.$resource->id)->assertNotFound();
});

it('reuses group images in a saved resource and serves them publicly only after publication', function () {
    $image = library_upload($this);
    $resource = GroupResource::factory()->create(['group_id' => $this->group->id]);
    $action = fn ($operation, $data = []) => $this->postJson(rtrim(config('app.url'), '/').route('groups.dashboard.resources.update', ['group' => $this->group, 'resource' => $resource, 'operation' => $operation], false), ['version' => $resource->fresh()->version] + $data);
    $token = $action('acquire')->assertOk()->json('data.editing_token');
    $action('save', ['editing_token' => $token, 'summary' => 'Added bridge image.', 'content' => [
        'title' => 'Bridges', 'slug' => $resource->slug, 'description' => '', 'body' => ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => '/resource-assets/'.$image->uuid, 'alt' => 'Positions']]]],
        'access_level' => 'everyone', 'tags' => [], 'activity_type_ids' => [], 'metadata_image_id' => $image->uuid,
        'commands' => [['name' => 'bridges', 'enabled' => true, 'embed' => ['title' => 'Bridges', 'image' => ['asset_id' => $image->uuid]]]],
    ]])->assertOk();
    $publicUrl = route('public-resources.images.show', ['image' => $image->uuid]);
    $this->getJson($publicUrl)->assertNotFound();
    $action('release', ['editing_token' => $token])->assertOk();
    $action('publish')->assertOk();
    $this->get($publicUrl)->assertOk();
    $this->deleteJson(library_image_url($this, $image))->assertUnprocessable()->assertJsonValidationErrors('image');
    DiscordGuildIntegration::create(['group_id' => $this->group->id, 'discord_guild_id' => '123456', 'guild_installed_at' => now()]);
    $botToken = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()->create(['api_token_hash' => IntegrationClient::hashApiToken($botToken), 'scopes' => [IntegrationClient::SCOPE_RESOURCES_READ]]);
    $payload = $this->withToken($botToken)->postJson(route('api.integrations.resource-commands.show', ['commandName' => 'bridges']), ['discord_guild_id' => '123456'])->assertOk()->json('data');
    $this->withToken($botToken)->get($payload['assets'][0]['url'])->assertOk();
    $this->library->update(['visibility' => 'private']);
    $this->getJson($publicUrl)->assertNotFound();
});

it('protects images referenced by old revisions, branding, or active editors', function (string $reference) {
    $image = library_upload($this);
    $resource = GroupResource::factory()->create(['group_id' => $this->group->id]);
    if ($reference === 'history') {
        $resource->revisions()->create(['editor' => [], 'summary' => 'Old image', 'state' => 'published', 'snapshot' => ['access_level' => 'everyone', 'image_ids' => [$image->uuid]]]);
    }
    if ($reference === 'branding') {
        $this->library->update(['customization' => ['banner_image_id' => $image->uuid]]);
    }
    if ($reference === 'editor') {
        $resource->update(['editing_expires_at' => now()->addMinutes(10)]);
    }
    $this->deleteJson(library_image_url($this, $image))->assertUnprocessable()->assertJsonValidationErrors('image');
    $this->assertDatabaseHas('group_resource_images', ['uuid' => $image->uuid]);
    Storage::disk('local')->assertExists($image->path);
})->with(['history', 'branding', 'editor']);

it('authorizes nested image sources and tracks them through publication and deletion protection', function (string $layout) {
    $image = library_upload($this);
    $foreign = library_upload($this);
    $foreign->update(['group_id' => Group::factory()->create()->id]);
    $resource = GroupResource::factory()->create(['group_id' => $this->group->id]);
    $action = fn ($operation, $data = []) => $this->postJson(rtrim(config('app.url'), '/').route('groups.dashboard.resources.update', ['group' => $this->group, 'resource' => $resource, 'operation' => $operation], false), ['version' => $resource->fresh()->version] + $data);
    $token = $action('acquire')->assertOk()->json('data.editing_token');
    $content = fn ($src) => [
        'title' => 'Inline markers', 'slug' => $resource->slug, 'description' => '',
        'body' => ['type' => 'doc', 'content' => [['type' => $layout === 'group' ? 'imageGroup' : 'paragraph', 'content' => [['type' => $layout === 'group' ? 'image' : 'inlineImage', 'attrs' => ['src' => $src, 'width' => 24]]]]]],
        'access_level' => 'everyone', 'tags' => [], 'activity_type_ids' => [], 'commands' => [],
    ];
    foreach (['/resource-assets/'.$foreign->uuid, 'https://example.com/map.png'] as $src) {
        $action('save', ['editing_token' => $token, 'summary' => 'Add image', 'content' => $content($src)])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
    }
    $action('save', ['editing_token' => $token, 'summary' => 'Add image', 'content' => $content('/resource-assets/'.$image->uuid)])->assertOk();
    expect($resource->fresh()->working_copy['image_ids'])->toBe([$image->uuid]);
    $publicUrl = route('public-resources.images.show', ['image' => $image->uuid]);
    $this->getJson($publicUrl)->assertNotFound();
    $action('release', ['editing_token' => $token])->assertOk();
    $action('publish')->assertOk();
    $this->get($publicUrl)->assertOk();
    $this->deleteJson(library_image_url($this, $image))->assertUnprocessable()->assertJsonValidationErrors('image');
})->with(['inline', 'group']);

it('deletes unused uploads with quota and audit updates', function () {
    $image = library_upload($this);
    expect($this->library->fresh()->storage_used_bytes)->toBeGreaterThan(0);
    $this->deleteJson(library_image_url($this, $image))->assertNoContent();
    $this->assertDatabaseMissing('group_resource_images', ['uuid' => $image->uuid]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'group.resources.image_deleted']);
    expect($this->library->fresh()->storage_used_bytes)->toBe(0);
    Storage::disk('local')->assertMissing($image->path);
});

it('does not remove files or charge quota when the deletion transaction fails', function () {
    $image = library_upload($this);
    $this->mock(ResourceAudit::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit failed'));
    $this->deleteJson(library_image_url($this, $image))->assertServerError();
    $this->assertDatabaseHas('group_resource_images', ['uuid' => $image->uuid]);
    expect($this->library->fresh()->storage_used_bytes)->toBe($image->size_bytes);
    Storage::disk('local')->assertExists($image->path);
});

it('preserves shared images when their original resource is deleted', function () {
    $image = library_upload($this);
    $origin = GroupResource::factory()->create(['group_id' => $this->group->id]);
    $image->update(['resource_id' => $origin->id, 'library_upload' => false]);
    GroupResource::factory()->create(['group_id' => $this->group->id, 'working_copy' => ['image_ids' => [$image->uuid]]]);
    app(ResourceLibraryDeletionService::class)->deleteResource($this->group, $origin, $this->group->owner, ['version' => $origin->fresh()->version]);
    expect($image->fresh()->resource_id)->toBeNull()->and($image->fresh()->library_upload)->toBeTrue();
    expect($this->library->fresh()->storage_used_bytes)->toBe($image->size_bytes);
    Storage::disk('local')->assertExists($image->path);
    $this->travel(2)->days();
    expect(app(ResourceImageService::class)->cleanup())->toBe(0);
});
