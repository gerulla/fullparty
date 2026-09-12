<?php

use App\Models\ActivityType;
use App\Models\BozjaHolster;
use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceCollection;
use App\Models\GroupResourceLibrary;
use App\Models\User;
use App\Services\Groups\Resources\ResourceCommandService;
use App\Services\Groups\Resources\ResourceHolsterService;
use App\Services\Groups\Resources\ResourceReaderService;
use App\Services\Groups\Resources\ResourceWorkflowService;
use App\Services\RichText\RichTextDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->group = Group::factory()->create();
    $this->group->features()->update(['resource_hub_enabled' => true]);
    $this->owner = $this->group->owner;
    $this->drs = ActivityType::factory()->create(['slug' => 'delubrum-reginae-savage']);
    $this->folder = GroupResourceCollection::create(['group_id' => $this->group->id, 'name' => 'Holsters', 'slug' => 'holsters']);
    $this->library = GroupResourceLibrary::create(['group_id' => $this->group->id, 'holster_collection_id' => $this->folder->id]);
    $this->holster = BozjaHolster::create([
        'group_id' => $this->group->id, 'name' => ['en' => 'Original tank'], 'notes' => str_repeat('Notes ', 300),
        'guide' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Original guide']]]]],
    ]);
    app(ResourceHolsterService::class)->synchronize($this->group);
    $this->resource = GroupResource::where('holster_id', $this->holster->id)->firstOrFail();
    $this->url = route('public-resources.holsters.show', ['group' => $this->group, 'holster' => $this->holster]);
    $this->action = fn (string $action, array $data = []) => app(ResourceWorkflowService::class)->mutate(
        $this->group, $this->resource->fresh(), $this->owner, $action,
        $data + ['version' => $this->resource->fresh()->version],
    );
});

it('loads linked holsters through the normal manager and enforces inherited fields on save', function () {
    $workspace = app(ResourceReaderService::class)->workspace($this->group, $this->owner);
    $row = collect($workspace['resources'])->firstWhere('id', $this->resource->id);
    expect($row['holster_id'])->toBe($this->holster->id)->and($row['summary']['activity_type_ids'])->toBe([$this->drs->id]);
    $this->actingAs($this->owner)->getJson(rtrim(config('app.url'), '/').route('groups.dashboard.resources.edit', ['group' => $this->group, 'resource' => $this->resource], false))
        ->assertOk()->assertJsonPath('data.holster_id', $this->holster->id)->assertJsonPath('data.working_copy.title', 'Original tank');
    $lease = ($this->action)('acquire')['editing_token'];
    $snapshot = array_replace($this->resource->working_copy, [
        'title' => 'Forged title', 'description' => 'Forged description', 'body' => RichTextDocument::empty(),
        'activity_type_ids' => [ActivityType::factory()->create()->id], 'tags' => ['custom'],
        'commands' => [['name' => 'tank-guide', 'enabled' => true, 'embed' => ['title' => 'Tank setup']]],
    ]);
    unset($snapshot['author']);
    ($this->action)('save', ['editing_token' => $lease, 'content' => $snapshot, 'summary' => 'Add resource settings']);
    $saved = $this->resource->fresh()->working_copy;
    expect($saved['title'])->toBe('Original tank')->and($saved['description'])->toBe($this->holster->notes)
        ->and($saved['body'])->toBe($this->holster->guide)->and($saved['activity_type_ids'])->toBe([$this->drs->id])
        ->and($saved['tags'])->toBe(['custom']);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.commands', []);
    ($this->action)('publish', ['editing_token' => $lease]);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.tags', ['custom'])->assertJsonPath('data.commands.0.name', 'tank-guide');
    $command = app(ResourceCommandService::class)->find($this->group, 'tank-guide');
    expect(app(ResourceCommandService::class)->payload($command, 'guild')['embed']['author']['name'])->toBe('Original tank');
    expect($this->resource->fresh()->activityTypes()->pluck('activity_types.id')->all())->toBe([$this->drs->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'group.resources.save']);
});

it('inherits source changes without overwriting metadata or requiring another publication', function () {
    $lease = ($this->action)('acquire')['editing_token'];
    $snapshot = array_replace($this->resource->working_copy, ['tags' => ['retained']]);
    ($this->action)('save', ['editing_token' => $lease, 'content' => $snapshot, 'summary' => 'Tag holster']);
    ($this->action)('publish', ['editing_token' => $lease]);
    $revisionCount = $this->resource->revisions()->count();
    $this->holster->update(['name' => ['en' => 'Renamed loadout'], 'notes' => 'New description', 'guide' => '## Current guide']);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.title', 'Renamed loadout')->assertJsonPath('data.tags', ['retained'])
        ->assertJsonPath('data.description', 'New description')->assertSee('Current guide');
    foreach (['Renamed', 'New description', 'Current guide', 'retained'] as $search) {
        $this->getJson(route('public-resources.index', $this->group).'?q='.urlencode($search))->assertOk()->assertJsonPath('resources.total', 1);
    }
    $this->getJson(route('public-resources.index', $this->group).'?q=Original')->assertOk()->assertJsonPath('resources.total', 0);
    $this->getJson(route('public-resources.index', $this->group).'?activity_type_id='.$this->drs->id)->assertOk()->assertJsonPath('resources.total', 1);
    $detail = app(ResourceReaderService::class)->managementDetail($this->group, $this->resource->fresh(), $this->owner);
    expect($detail['working_copy']['title'])->toBe('Renamed loadout')->and($detail['has_unpublished_changes'])->toBeFalse()
        ->and($this->resource->revisions()->count())->toBe($revisionCount);
});

it('enforces resource access on legacy URLs UUID URLs listings and paired loadouts', function () {
    $refill = BozjaHolster::create(['group_id' => $this->group->id, 'parent_holster_id' => $this->holster->id, 'type' => 'refill', 'name' => ['en' => 'Public refill']]);
    app(ResourceHolsterService::class)->synchronize($this->group);
    $lease = ($this->action)('acquire')['editing_token'];
    ($this->action)('save', ['editing_token' => $lease, 'content' => array_replace($this->resource->working_copy, ['access_level' => 'admin']), 'summary' => 'Restrict access']);
    ($this->action)('publish', ['editing_token' => $lease]);
    $this->getJson($this->url)->assertNotFound();
    $this->getJson(route('public-resources.show', ['group' => $this->group, 'slug' => $this->resource->uuid]))->assertNotFound();
    $this->getJson(route('public-resources.index', $this->group).'?q=Original')->assertOk()->assertJsonPath('resources.total', 0);
    $this->getJson(route('public-resources.holsters.show', ['group' => $this->group, 'holster' => $refill]))->assertOk()->assertJsonPath('data.holster.prepop', null);
    $moderator = User::factory()->create();
    $this->group->memberships()->create(['user_id' => $moderator->id, 'role' => 'moderator', 'joined_at' => now()]);
    $workspace = app(ResourceReaderService::class)->workspace($this->group, $moderator);
    expect(collect($workspace['resources'])->pluck('id')->all())->not->toContain($this->resource->id);
    expect(collect($workspace['holsters']['resources'])->pluck('holster_id')->all())->not->toContain($this->holster->id);
    $this->actingAs($moderator)->getJson(rtrim(config('app.url'), '/').route('groups.dashboard.resources.edit', ['group' => $this->group, 'resource' => $this->resource], false))->assertForbidden();
    $this->actingAs($this->owner)->getJson(rtrim(config('app.url'), '/').route('groups.dashboard.resources.edit', ['group' => $this->group, 'resource' => $this->resource], false))->assertOk();
});

it('retains metadata through disabling reactivation and collection changes and supports pinning and archiving', function () {
    ($this->action)('pin', ['is_pinned' => true]);
    $this->getJson(route('public-resources.index', $this->group))->assertOk()->assertJsonPath('reader.pinned_resources.0.id', $this->resource->id);
    $id = $this->resource->id;
    $this->holster->update(['is_active' => false]);
    $this->getJson($this->url)->assertNotFound();
    $this->holster->update(['is_active' => true]);
    app(ResourceHolsterService::class)->configure($this->group, $this->owner, ['collection_id' => null]);
    $this->getJson($this->url)->assertNotFound();
    $folder = GroupResourceCollection::create(['group_id' => $this->group->id, 'name' => 'Moved', 'slug' => 'moved']);
    app(ResourceHolsterService::class)->configure($this->group, $this->owner, ['collection_id' => $folder->id]);
    expect($this->resource->fresh()->collection_id)->toBe($folder->id)->and($this->resource->fresh()->is_pinned)->toBeTrue();
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.id', $id);
    ($this->action)('archive');
    $this->getJson($this->url)->assertNotFound();
    app(ResourceHolsterService::class)->synchronize($this->group);
    expect(GroupResource::where('holster_id', $this->holster->id)->count())->toBe(1)->and($this->resource->fresh()->status)->toBe('archived');
});
