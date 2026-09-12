<?php

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupResource;
use App\Models\GroupResourceCollection;
use App\Models\GroupResourceCommand;
use App\Models\GroupResourceImage;
use App\Models\GroupResourceLibrary;
use App\Models\User;
use App\Services\Groups\Resources\ResourceLibraryDeletionService;
use App\Services\Groups\Resources\ResourceReaderService;
use App\Services\Groups\Resources\ResourceStarterContent;
use App\Services\Groups\Resources\ResourceWorkflowService;
use App\Services\RichText\RichTextDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function starter_settings(Group $group, bool $enabled = true): array
{
    return $group->only(['name', 'description', 'discord_invite_url', 'datacenter', 'join_mode', 'is_visible'])
        + ['features' => ['resource_hub_enabled' => $enabled]];
}

beforeEach(function () {
    Storage::fake('local');
    $this->group = Group::factory()->create();
    $this->actingAs($this->group->owner);
    $this->settingsUrl = route('groups.dashboard.settings.update', $this->group);
});

it('creates a usable localized starter library on the first activation', function (string $locale) {
    $activities = ActivityType::factory()->count(2)->withPublishedVersion()->create(['is_active' => true]);
    ActivityType::factory()->withPublishedVersion()->create(['is_active' => false]);
    ActivityType::factory()->create(['is_active' => true]);
    $originalHome = GroupResource::where('group_id', $this->group->id)->sole();

    $this->withSession(['locale' => $locale])->put($this->settingsUrl, starter_settings($this->group))
        ->assertRedirect()->assertSessionHasNoErrors();

    $group = $this->group->fresh();
    expect($group->features->resource_hub_initialized_at)->not->toBeNull()
        ->and($group->features->resource_hub_enabled)->toBeTrue()
        ->and(Activity::count())->toBe(0)
        ->and(ActivityType::count())->toBe(4);
    $resources = GroupResource::where('group_id', $group->id)->with('publishedRevision')->get()->keyBy('slug');
    expect($resources)->toHaveCount(8)
        ->and($resources->where('status', 'published'))->toHaveCount(6)
        ->and($resources->where('status', 'draft'))->toHaveCount(1)
        ->and($resources->where('status', 'archived'))->toHaveCount(1)
        ->and($resources->whereNotNull('editing_user_id'))->toHaveCount(0);
    $home = $resources->firstWhere('is_home', true);
    expect($home->id)->toBe($originalHome->id)
        ->and($home->publishedRevision->snapshot['description'])->toContain($group->name)
        ->and($home->publishedRevision->snapshot['body'])->not->toBe(RichTextDocument::empty())
        ->and($home->revisions()->count())->toBe(2);
    $folders = GroupResourceCollection::where('group_id', $group->id)->get()->keyBy('slug');
    expect($folders)->toHaveCount(4)
        ->and($folders['starter-raid-preparation']->parent_id)->toBe($folders['starter-examples']->id)
        ->and($folders['starter-start']->name)->toBe(__('resource_starter.collections.start', [], $locale));

    $image = GroupResourceImage::where('group_id', $group->id)->sole();
    Storage::disk('local')->assertExists($image->path);
    expect($image->library_upload)->toBeTrue()
        ->and($image->resource_id)->toBeNull()
        ->and($image->mime_type)->toBe('image/jpeg')
        ->and($image->width)->toBe(800)
        ->and($image->height)->toBe(572)
        ->and(hash('sha256', Storage::disk('local')->get($image->path)))->toBe(hash_file('sha256', public_path('resource-sample-planning-map.jpg')))
        ->and(GroupResourceLibrary::where('group_id', $group->id)->sole()->storage_used_bytes)->toBe($image->size_bytes);
    $prep = $resources['starter-raid-preparation'];
    expect($prep->publishedRevision->snapshot['title'])->toBe(__('resource_starter.raid-preparation.title', [], $locale))
        ->and($prep->publishedRevision->snapshot['metadata_image_id'])->toBe($image->uuid)
        ->and($prep->activityTypes()->pluck('activity_types.id')->all())->toEqualCanonicalizing($activities->modelKeys())
        ->and($prep->publishedRevision->snapshot['image_ids'])->toContain($image->uuid)
        ->and($prep->tags()->count())->toBe(2);
    $commands = GroupResourceCommand::where('group_id', $group->id)->get()->keyBy('name');
    expect($commands)->toHaveCount(2)
        ->and($commands['demo-prep']->enabled)->toBeTrue()
        ->and($commands['demo-prep']->embed['thumbnail']['asset_id'])->toBe($image->uuid)
        ->and($commands['demo-map']->embed['image']['asset_id'])->toBe($image->uuid);
    foreach ($resources as $resource) {
        $snapshot = $resource->working_copy ?? $resource->publishedRevision?->snapshot;
        expect(app(RichTextDocument::class)->html($snapshot['body']))->not->toBeEmpty()
            ->and(json_encode($snapshot))->not->toContain('resource_starter.');
    }

    $member = User::factory()->create();
    $group->memberships()->create(['user_id' => $member->id, 'role' => GroupMembership::ROLE_MEMBER, 'joined_at' => now()]);
    $reader = app(ResourceReaderService::class);
    expect($reader->query($group, null, public: true)->pluck('slug')->all())->toEqualCanonicalizing([
        $home->slug, 'starter-getting-started', 'starter-raid-preparation', 'starter-discord-embeds',
    ])->and($reader->query($group, $member)->count())->toBe(4)
        ->and($reader->query($group, $group->owner)->count())->toBe(6);
    $this->assertDatabaseHas('audit_logs', ['action' => 'group.resources.publish', 'scope_id' => $group->id]);
})->with(['en', 'de', 'fr', 'ja']);

it('keeps edited resources and their locale when settings are saved or the feature is enabled again', function () {
    $this->put($this->settingsUrl, starter_settings($this->group))->assertSessionHasNoErrors();
    $resource = GroupResource::where('group_id', $this->group->id)->where('slug', 'starter-practice-draft')->sole();
    $workflow = app(ResourceWorkflowService::class);
    $group = $this->group->fresh();
    $lease = $workflow->mutate($group, $resource, $group->owner, 'acquire', ['version' => $resource->version]);
    $content = array_replace($resource->fresh()->working_copy, ['title' => 'Our own guide']);
    $saved = $workflow->mutate($group, $resource, $group->owner, 'save', [
        'version' => $lease['version'], 'editing_token' => $lease['editing_token'], 'content' => $content, 'summary' => 'Made this guide our own.',
    ]);
    $workflow->mutate($group, $resource, $group->owner, 'release', ['version' => $saved['version'], 'editing_token' => $lease['editing_token']]);
    $ids = GroupResource::where('group_id', $group->id)->pluck('id')->all();
    $marker = $group->features->resource_hub_initialized_at;
    $revisionCount = DB::table('group_resource_revisions')->count();
    $this->put($this->settingsUrl, starter_settings($group))->assertSessionHasNoErrors();
    $this->put($this->settingsUrl, starter_settings($group, false))->assertSessionHasNoErrors();
    $this->withSession(['locale' => 'ja'])->put($this->settingsUrl, starter_settings($group))->assertSessionHasNoErrors();

    expect(GroupResource::where('group_id', $group->id)->pluck('id')->all())->toBe($ids)
        ->and($resource->fresh()->working_copy['title'])->toBe('Our own guide')
        ->and(DB::table('group_resource_revisions')->count())->toBe($revisionCount)
        ->and($group->fresh()->features->resource_hub_initialized_at->equalTo($marker))->toBeTrue()
        ->and(GroupResourceImage::where('group_id', $group->id)->count())->toBe(1);
});

it('does not recreate the demo after all resources are cleared', function () {
    $this->put($this->settingsUrl, starter_settings($this->group))->assertSessionHasNoErrors();
    $group = $this->group->fresh();
    $home = GroupResource::where('group_id', $group->id)->where('is_home', true)->sole();
    $body = $home->publishedRevision->snapshot['body'];
    $image = GroupResourceImage::where('group_id', $group->id)->sole();
    expect(app(ResourceLibraryDeletionService::class)->deleteResources($group, $group->owner))->toBe(7);
    $this->put($this->settingsUrl, starter_settings($group, false))->assertSessionHasNoErrors();
    $this->put($this->settingsUrl, starter_settings($group))->assertSessionHasNoErrors();
    expect(GroupResource::where('group_id', $group->id)->sole()->id)->toBe($home->id)
        ->and($home->fresh()->publishedRevision->snapshot['body'])->toBe($body)
        ->and(GroupResourceCollection::where('group_id', $group->id)->count())->toBe(0)
        ->and(GroupResourceImage::where('group_id', $group->id)->sole()->uuid)->toBe($image->uuid);
    Storage::disk('local')->assertExists($image->path);
});

it('preserves pre-existing content when a library has no initialization marker', function (string $existing) {
    $home = GroupResource::where('group_id', $this->group->id)->sole();
    if ($existing === 'resource') {
        GroupResource::factory()->create(['group_id' => $this->group->id]);
    } elseif ($existing === 'collection') {
        GroupResourceCollection::create(['group_id' => $this->group->id, 'name' => 'Our folder', 'slug' => 'our-folder']);
    } else {
        $home->publishedRevision->update(['snapshot' => array_replace($home->publishedRevision->snapshot, ['description' => 'Our custom Home'])]);
    }
    $resources = GroupResource::where('group_id', $this->group->id)->get()->toArray();
    $homeSnapshot = $home->publishedRevision->fresh()->snapshot;
    $this->put($this->settingsUrl, starter_settings($this->group))->assertSessionHasNoErrors();
    expect(GroupResource::where('group_id', $this->group->id)->get()->toArray())->toBe($resources)
        ->and($home->publishedRevision->fresh()->snapshot)->toBe($homeSnapshot)
        ->and(GroupResourceImage::count())->toBe(0)
        ->and($this->group->fresh()->features->resource_hub_initialized_at)->not->toBeNull();
})->with(['resource', 'collection', 'home']);

it('rolls back activation and removes the uploaded sample when initialization fails', function () {
    $this->partialMock(ResourceStarterContent::class, function ($mock) {
        $mock->shouldReceive('resources')->once()->andThrow(new RuntimeException('Starter content failed.'));
    });
    $this->withoutExceptionHandling();
    expect(fn () => $this->put($this->settingsUrl, starter_settings($this->group)))
        ->toThrow(RuntimeException::class, 'Starter content failed.');
    expect($this->group->fresh()->features->resource_hub_enabled)->toBeFalse()
        ->and($this->group->fresh()->features->resource_hub_initialized_at)->toBeNull()
        ->and(GroupResource::where('group_id', $this->group->id)->count())->toBe(1)
        ->and(GroupResourceCollection::count())->toBe(0)
        ->and(GroupResourceImage::count())->toBe(0)
        ->and(GroupResourceLibrary::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('marks already used libraries during migration without adding content', function () {
    $enabled = Group::factory()->create();
    $enabled->features()->update(['resource_hub_enabled' => true]);
    $used = Group::factory()->create();
    GroupResourceLibrary::create(['group_id' => $used->id]);
    $migration = require database_path('migrations/2026_09_11_000006_track_resource_hub_initialization.php');
    $migration->down();
    $migration->up();
    expect($enabled->fresh()->features->resource_hub_initialized_at)->not->toBeNull()
        ->and($used->fresh()->features->resource_hub_initialized_at)->not->toBeNull()
        ->and($this->group->fresh()->features->resource_hub_initialized_at)->toBeNull();
    $this->actingAs($used->owner)->put(route('groups.dashboard.settings.update', $used), starter_settings($used))
        ->assertSessionHasNoErrors();
    expect(GroupResource::where('group_id', $used->id)->count())->toBe(1)
        ->and(GroupResourceCollection::count())->toBe(0);
});

it('prevents non-admin members from enabling the starter library', function (string $role) {
    $member = User::factory()->create();
    $this->group->memberships()->create(['user_id' => $member->id, 'role' => $role, 'joined_at' => now()]);
    $this->actingAs($member)->put($this->settingsUrl, starter_settings($this->group))->assertForbidden();
    expect($this->group->fresh()->features->resource_hub_enabled)->toBeFalse()
        ->and($this->group->fresh()->features->resource_hub_initialized_at)->toBeNull()
        ->and(GroupResourceCollection::count())->toBe(0);
})->with([GroupMembership::ROLE_MEMBER, GroupMembership::ROLE_MODERATOR]);
