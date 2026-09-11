<?php

use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceImage;
use App\Models\GroupResourceLibrary;
use App\Models\User;
use App\Services\Groups\Resources\ResourceAudit;
use App\Services\Groups\Resources\ResourceLibraryDeletionService;
use App\Services\Groups\Resources\ResourceLibraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function library_management_resource(Group $group): GroupResource
{
    $resource = GroupResource::factory()->create(['group_id' => $group->id, 'access_level' => 'admin', 'management_access_level' => 'admin']);
    $revision = $resource->revisions()->create(['editor' => ['name' => 'Editor'], 'snapshot' => ['title' => 'Internal resource', 'access_level' => 'admin'], 'summary' => 'Initial version.', 'state' => 'published']);
    $pending = $resource->revisions()->create(['editor' => ['name' => 'Editor'], 'snapshot' => ['title' => 'Pending version'], 'summary' => 'Pending changes.', 'state' => 'pending']);
    $resource->update(['status' => 'published', 'published_revision_id' => $revision->id, 'pending_revision_id' => $pending->id]);
    $resource->commands()->create(['group_id' => $group->id, 'name' => 'command-'.$resource->id, 'enabled' => true, 'embed' => ['title' => 'Bot command']]);
    DB::table('group_resource_slugs')->insert(['group_id' => $group->id, 'resource_id' => $resource->id, 'slug' => $resource->slug]);

    return $resource;
}

function library_management_image(Group $group, ?GroupResource $resource): GroupResourceImage
{
    $uuid = (string) Str::uuid();
    $path = "group-resources/{$group->id}/{$uuid}.png";
    Storage::disk('local')->put($path, 'image-data');

    return GroupResourceImage::create([
        'uuid' => $uuid, 'group_id' => $group->id, 'resource_id' => $resource?->id,
        'access_level' => 'admin', 'path' => $path, 'mime_type' => 'image/png',
        'width' => 1, 'height' => 1, 'size_bytes' => 10, 'alt_text' => '',
    ]);
}

beforeEach(function () {
    $this->group = Group::factory()->create();
    $this->group->features()->update(['resource_hub_enabled' => true]);
    $this->actingAs($this->group->owner);
    Storage::fake('local');
    $this->settingsUrl = route('groups.dashboard.resources.library.update', $this->group);
    $this->deleteUrl = route('groups.dashboard.resources.library.resources.destroy', $this->group);
});

it('defaults new libraries to public in page data models and the database', function () {
    $this->get(route('groups.dashboard.resources.manage', $this->group))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('library.visibility', 'public')->where('group.permissions.can_update_group_settings', true)
            ->where('library.customization.title', $this->group->name.' Resource Library')
            ->where('library.customization.introduction', 'Browse '.$this->group->name.' resources to find the info you need.'));
    expect(app(ResourceLibraryService::class)->isPublic($this->group))->toBeTrue();
    $library = DB::transaction(fn () => app(ResourceLibraryService::class)->lock($this->group));
    expect($library->fresh()->visibility)->toBe('public');
    expect($library->fresh()->customization['title'])->toBe($this->group->name.' Resource Library')
        ->and($library->fresh()->customization['introduction'])->toBe('Browse '.$this->group->name.' resources to find the info you need.');
    $otherGroup = Group::factory()->create();
    DB::table('group_resource_libraries')->insert(['group_id' => $otherGroup->id]);
    expect(GroupResourceLibrary::where('group_id', $otherGroup->id)->value('visibility'))->toBe('public');
});

it('preserves configured group-only visibility and existing customization during visibility changes', function () {
    $library = GroupResourceLibrary::create(['group_id' => $this->group->id, 'visibility' => 'private', 'customization' => ['title' => 'Our library', 'accent_color' => '#16803d']]);
    $this->get(route('groups.dashboard.resources.manage', $this->group))
        ->assertInertia(fn (Assert $page) => $page->where('library.visibility', 'private'));
    foreach (['public', 'private'] as $visibility) {
        $this->putJson($this->settingsUrl, ['visibility' => $visibility])->assertOk()->assertJsonPath('data.visibility', $visibility)
            ->assertJsonPath('data.customization.title', 'Our library')->assertJsonPath('data.customization.accent_color', '#16803d');
        expect($library->fresh()->visibility)->toBe($visibility);
    }
    $this->assertDatabaseHas('audit_logs', ['action' => 'group.resources.settings_updated']);
});

it('does not change existing visibility when applying the new database default', function () {
    $library = GroupResourceLibrary::create(['group_id' => $this->group->id, 'visibility' => 'private']);
    $migration = require database_path('migrations/2026_09_10_001500_default_new_resource_libraries_to_public.php');
    $migration->up();
    expect($library->fresh()->visibility)->toBe('private');
});

it('saves every public library customization and returns it when reopening management', function () {
    $banner = library_management_image($this->group, null);
    $logo = library_management_image($this->group, null);
    $sharing = library_management_image($this->group, null);
    $customization = [
        'title' => 'Our strategy library', 'introduction' => 'Guides for our group.',
        'banner_image_id' => $banner->uuid, 'banner_focal_x' => 25, 'banner_focal_y' => 75,
        'logo_image_id' => $logo->uuid, 'sharing_image_id' => $sharing->uuid,
        'accent_color' => '#126a4c', 'appearance' => 'dark',
        'links' => [['label' => 'Discord', 'url' => 'https://discord.com/invite/example']],
    ];
    $response = $this->putJson($this->settingsUrl, ['visibility' => 'public', 'customization' => $customization])->assertOk();
    foreach ($customization as $key => $value) {
        $response->assertJsonPath('data.customization.'.$key, $value);
    }
    $this->get(route('groups.dashboard.resources.manage', $this->group))->assertInertia(fn (Assert $page) => $page
        ->where('library.customization.title', $customization['title'])
        ->where('library.customization.banner_image_id', $banner->uuid)
        ->where('library.customization.links', $customization['links']));
    $this->assertDatabaseHas('audit_logs', ['action' => 'group.resources.settings_updated']);

    $this->putJson($this->settingsUrl, ['visibility' => 'public', 'customization' => array_replace($customization, [
        'banner_image_id' => null, 'logo_image_id' => null, 'sharing_image_id' => null, 'links' => [], 'appearance' => 'system',
    ])])->assertOk()->assertJsonPath('data.customization.banner_image_id', null)
        ->assertJsonPath('data.customization.logo_image_id', null)->assertJsonPath('data.customization.sharing_image_id', null)
        ->assertJsonPath('data.customization.links', [])->assertJsonPath('data.customization.appearance', 'system');
});

it('ignores customization submitted with group-only visibility without losing saved branding', function () {
    $saved = ['title' => 'Keep this title', 'introduction' => 'Keep this introduction.', 'accent_color' => '#123456', 'links' => [['label' => 'Website', 'url' => 'https://example.com']]];
    $library = GroupResourceLibrary::create(['group_id' => $this->group->id, 'customization' => $saved]);
    $this->putJson($this->settingsUrl, ['visibility' => 'private', 'customization' => ['title' => str_repeat('x', 500), 'accent_color' => 'invalid']])
        ->assertOk()->assertJsonPath('data.visibility', 'private')->assertJsonPath('data.customization.title', $saved['title']);
    expect($library->fresh()->customization)->toBe($saved);
    $this->putJson($this->settingsUrl, ['visibility' => 'public'])->assertOk()->assertJsonPath('data.customization.links', $saved['links']);
});

it('fills legacy missing null and blank library text without replacing custom branding', function (array $customization) {
    $library = GroupResourceLibrary::create(['group_id' => $this->group->id, 'visibility' => 'private', 'customization' => $customization + ['accent_color' => '#123456']]);
    $payload = app(ResourceLibraryService::class)->payload($this->group);
    expect($payload['customization']['title'])->toBe($this->group->name.' Resource Library')
        ->and($payload['customization']['introduction'])->toBe('Browse '.$this->group->name.' resources to find the info you need.')
        ->and($payload['customization']['accent_color'])->toBe('#123456');
    $this->putJson($this->settingsUrl, ['visibility' => 'private'])->assertOk();
    expect($library->fresh()->customization['title'])->toBe($payload['customization']['title'])
        ->and($library->fresh()->customization['introduction'])->toBe($payload['customization']['introduction']);
})->with([
    [[]], [['title' => null, 'introduction' => null]], [['title' => '', 'introduction' => " \t\n"]],
]);

it('rejects empty library text while preserving the last saved settings', function (string $field, mixed $value) {
    $saved = ['title' => 'Custom title', 'introduction' => 'Custom introduction.'];
    $library = GroupResourceLibrary::create(['group_id' => $this->group->id, 'customization' => $saved]);
    $this->putJson($this->settingsUrl, ['visibility' => 'public', 'customization' => array_replace($saved, [$field => $value])])
        ->assertUnprocessable()->assertJsonValidationErrors('customization.'.$field);
    expect($library->fresh()->customization)->toBe($saved);
})->with(['title', 'introduction'])->with([null, '', '   ']);

it('preserves existing text on partial settings saves and uses defaults for missing text', function () {
    $library = GroupResourceLibrary::create(['group_id' => $this->group->id, 'customization' => ['title' => 'Existing library']]);
    $this->putJson($this->settingsUrl, ['visibility' => 'public', 'customization' => ['accent_color' => '#123456']])->assertOk()
        ->assertJsonPath('data.customization.title', 'Existing library')
        ->assertJsonPath('data.customization.introduction', 'Browse '.$this->group->name.' resources to find the info you need.');
    expect($library->fresh()->customization['title'])->toBe('Existing library');
});

it('keeps generated defaults within field limits for long group names', function () {
    $this->group->update(['name' => str_repeat('界', 255)]);
    foreach (['en', 'de', 'fr', 'ja'] as $locale) {
        app()->setLocale($locale);
        $customization = app(ResourceLibraryService::class)->payload($this->group)['customization'];
        expect(mb_strlen($customization['title']))->toBeLessThanOrEqual(160)
            ->and(mb_strlen($customization['introduction']))->toBeLessThanOrEqual(2000)
            ->and($customization['title'])->not->toBe('resource_library.default_title');
    }
});

it('returns precise customization errors and leaves settings unchanged on failure', function () {
    $library = GroupResourceLibrary::create(['group_id' => $this->group->id, 'customization' => ['title' => 'Keep me']]);
    $this->putJson($this->settingsUrl, ['visibility' => 'public', 'customization' => [
        'title' => str_repeat('x', 161), 'introduction' => str_repeat('x', 2001),
        'banner_focal_x' => -1, 'banner_focal_y' => 101, 'accent_color' => '#oops', 'appearance' => 'rainbow',
        'links' => array_fill(0, 9, ['label' => '', 'url' => 'javascript:alert(1)']),
    ]])->assertUnprocessable()->assertJsonValidationErrors([
        'customization.title', 'customization.introduction', 'customization.banner_focal_x', 'customization.banner_focal_y',
        'customization.accent_color', 'customization.appearance', 'customization.links', 'customization.links.0.label', 'customization.links.0.url',
    ]);
    expect($library->fresh()->customization)->toBe(['title' => 'Keep me']);
});

it('refuses branding images belonging to another group or a specific resource', function () {
    $otherImage = library_management_image(Group::factory()->create(), null);
    $restricted = library_management_image($this->group, library_management_resource($this->group));
    $this->putJson($this->settingsUrl, ['visibility' => 'public', 'customization' => [
        'banner_image_id' => $otherImage->uuid, 'logo_image_id' => $restricted->uuid, 'sharing_image_id' => (string) Str::uuid(),
    ]])->assertUnprocessable()->assertJsonValidationErrors([
        'customization.banner_image_id', 'customization.logo_image_id', 'customization.sharing_image_id',
    ]);
});

it('deletes every resource state and related data but preserves collections branding and other groups', function () {
    $resource = library_management_resource($this->group);
    GroupResource::factory()->create(['group_id' => $this->group->id, 'status' => 'draft', 'editing_expires_at' => now()->addMinutes(10)]);
    GroupResource::factory()->create(['group_id' => $this->group->id, 'status' => 'archived']);
    $image = library_management_image($this->group, $resource);
    $branding = library_management_image($this->group, null);
    $library = GroupResourceLibrary::create(['group_id' => $this->group->id, 'visibility' => 'private', 'storage_used_bytes' => 20, 'customization' => ['title' => 'Keep this', 'logo_image_id' => $branding->uuid, 'start_resource_id' => $resource->id]]);
    $otherResource = library_management_resource(Group::factory()->create());
    $otherImage = library_management_image($otherResource->group, $otherResource);
    $collectionIds = $this->group->resourceCollections()->pluck('id')->all();

    $this->deleteJson($this->deleteUrl, ['confirmation' => 'i am sure'])->assertOk()->assertJsonPath('deleted_count', 3)
        ->assertJsonPath('data.visibility', 'private')->assertJsonPath('data.storage.used_bytes', 10)
        ->assertJsonPath('data.customization.title', 'Keep this')->assertJsonPath('data.customization.logo_image_id', $branding->uuid)
        ->assertJsonPath('data.customization.start_resource_id', GroupResource::where('group_id', $this->group->id)->where('is_home', true)->value('id'));

    expect(GroupResource::where('group_id', $this->group->id)->where('is_home', false)->count())->toBe(0)
        ->and($this->group->resourceCollections()->pluck('id')->all())->toBe($collectionIds)
        ->and($library->fresh()->storage_used_bytes)->toBe(10);
    foreach (['group_resource_revisions', 'group_resource_commands', 'group_resource_slugs'] as $table) {
        $this->assertDatabaseMissing($table, ['resource_id' => $resource->id]);
        $this->assertDatabaseHas($table, ['resource_id' => $otherResource->id]);
    }
    $this->assertDatabaseMissing('group_resource_images', ['id' => $image->id]);
    $this->assertDatabaseHas('group_resource_images', ['id' => $branding->id]);
    $this->assertDatabaseHas('group_resources', ['id' => $otherResource->id]);
    Storage::disk('local')->assertMissing($image->path);
    Storage::disk('local')->assertExists([$branding->path, $otherImage->path]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'group.resources.all_deleted', 'scope_id' => $this->group->id]);
});

it('retains images referenced by Home including old and pending revisions when clearing the library', function () {
    $home = GroupResource::where('group_id', $this->group->id)->where('is_home', true)->sole();
    $other = library_management_resource($this->group);
    $image = library_management_image($this->group, $other);
    $home->revisions()->create(['editor' => ['name' => 'Editor'], 'snapshot' => ['title' => 'Home', 'access_level' => 'everyone', 'image_ids' => [$image->uuid]], 'summary' => 'Added image', 'state' => 'draft']);
    GroupResourceLibrary::create(['group_id' => $this->group->id, 'storage_used_bytes' => 10]);
    $this->deleteJson($this->deleteUrl, ['confirmation' => 'i am sure'])->assertOk()->assertJsonPath('data.storage.used_bytes', 10);
    expect($image->fresh()->resource_id)->toBeNull();
    $this->assertDatabaseHas('group_resources', ['id' => $home->id]);
    Storage::disk('local')->assertExists($image->path);
});

it('requires the confirmation phrase on the server', function (array $body) {
    $resource = library_management_resource($this->group);
    $this->deleteJson($this->deleteUrl, $body)->assertUnprocessable()->assertJsonValidationErrors('confirmation');
    $this->assertDatabaseHas('group_resources', ['id' => $resource->id]);
})->with([
    [[]], [['confirmation' => 'yes']], [['confirmation' => 'I am sure']], [['confirmation' => null]], [['confirmation' => ['i am sure']]],
]);

it('restricts library-wide settings and deletion to admins and owners', function (string $role, bool $allowed) {
    $user = User::factory()->create();
    if ($role !== 'outsider') {
        $this->group->memberships()->create(['user_id' => $user->id, 'role' => $role]);
    }
    $this->actingAs($user);
    $status = $allowed ? 200 : ($role === 'outsider' ? 404 : 403);
    $this->putJson($this->settingsUrl, ['visibility' => 'private'])->assertStatus($status);
    $this->deleteJson($this->deleteUrl, ['confirmation' => 'i am sure'])->assertStatus($status);
    if ($role === 'moderator') {
        $this->get(route('groups.dashboard.resources.manage', $this->group))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('group.permissions.can_update_group_settings', false));
    }
})->with([['admin', true], ['moderator', false], ['member', false], ['outsider', false]]);

it('refuses destructive operations when the feature is disabled', function () {
    $resource = library_management_resource($this->group);
    $this->group->features()->update(['resource_hub_enabled' => false]);
    $this->deleteJson($this->deleteUrl, ['confirmation' => 'i am sure'])->assertForbidden();
    $this->assertDatabaseHas('group_resources', ['id' => $resource->id]);
});

it('rolls back deletion without removing files if the transaction fails', function () {
    $resource = library_management_resource($this->group);
    $image = library_management_image($this->group, $resource);
    GroupResourceLibrary::create(['group_id' => $this->group->id, 'storage_used_bytes' => 10]);
    $this->mock(ResourceAudit::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit failed'));

    expect(fn () => app(ResourceLibraryDeletionService::class)->deleteResources($this->group, $this->group->owner))->toThrow(RuntimeException::class, 'Audit failed');
    $this->assertDatabaseHas('group_resources', ['id' => $resource->id]);
    $this->assertDatabaseHas('group_resource_images', ['id' => $image->id]);
    expect(GroupResourceLibrary::where('group_id', $this->group->id)->value('storage_used_bytes'))->toBe(10);
    Storage::disk('local')->assertExists($image->path);
});
