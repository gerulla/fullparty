<?php

use App\Models\Group;
use App\Models\User;
use App\Services\Groups\Resources\ResourceReaderService;
use App\Services\Groups\Resources\ResourceWorkflowService;
use App\Services\RichText\RichTextDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->group = Group::factory()->create();
    $this->group->features()->update(['resource_hub_enabled' => true]);
    $this->user = $this->group->owner;
    $this->workflow = app(ResourceWorkflowService::class);
    $this->content = ['title' => 'Guide', 'slug' => 'guide', 'description' => '', 'body' => RichTextDocument::empty(), 'access_level' => 'everyone', 'tags' => [], 'activity_type_ids' => [], 'commands' => []];
    $this->resource = $this->workflow->create($this->group, $this->user, ['content' => $this->content]);
});

function publication_action($test, string $action, array $data = [], ?User $user = null): array
{
    return $test->workflow->mutate($test->group, $test->resource, $user ?? $test->user, $action, ['version' => $test->resource->fresh()->version] + $data);
}

it('requires a changelog on the first save and never turns publishing into a save', function () {
    $lease = publication_action($this, 'acquire')['editing_token'];
    expect(fn () => publication_action($this, 'save', ['editing_token' => $lease, 'content' => $this->content]))->toThrow(ValidationException::class);
    expect(fn () => publication_action($this, 'publish', ['editing_token' => $lease]))->toThrow(ValidationException::class);
    expect($this->resource->revisions()->count())->toBe(0)->and(DB::table('group_resource_publications')->count())->toBe(0);
    publication_action($this, 'save', ['editing_token' => $lease, 'content' => $this->content, 'summary' => 'Prepared the first guide.']);
    $saved = $this->resource->revisions()->sole();
    expect($saved->state)->toBe('draft')->and($this->resource->fresh()->published_revision_id)->toBeNull();
    publication_action($this, 'publish', ['editing_token' => $lease]);
    expect($this->resource->revisions()->count())->toBe(1)->and($saved->fresh()->summary)->toBe('Prepared the first guide.')
        ->and($this->resource->fresh()->published_revision_id)->toBe($saved->id);
    publication_action($this, 'publish', ['editing_token' => $lease]);
    expect(DB::table('group_resource_publications')->count())->toBe(1);
});

it('allows authorized corrections to hidden resources without lifting the moderation restriction', function () {
    $lease = publication_action($this, 'acquire')['editing_token'];
    publication_action($this, 'save', ['editing_token' => $lease, 'content' => $this->content, 'summary' => 'Initial guide.']);
    publication_action($this, 'publish', ['editing_token' => $lease]);
    $this->resource->refresh()->forceFill(['moderation_hidden_at' => now()])->save();
    $reader = app(ResourceReaderService::class);
    expect($reader->query($this->group, $this->user, manage: true)->whereKey($this->resource->id)->exists())->toBeTrue()
        ->and($reader->managementDetail($this->group, $this->resource->fresh(), $this->user)['moderation_hidden'])->toBeTrue();
    publication_action($this, 'save', ['editing_token' => $lease, 'content' => array_replace($this->content, ['title' => 'Corrected guide']), 'summary' => 'Fixed the reported issue.']);
    publication_action($this, 'publish', ['editing_token' => $lease]);
    expect($this->resource->fresh()->publishedRevision->snapshot['title'])->toBe('Corrected guide')
        ->and($this->resource->fresh()->moderation_hidden_at)->not->toBeNull()
        ->and($reader->query($this->group, null, true)->whereKey($this->resource->id)->exists())->toBeFalse()
        ->and($reader->query($this->group, $this->user)->whereKey($this->resource->id)->exists())->toBeFalse()
        ->and($reader->managementDetail($this->group, $this->resource->fresh(), $this->user)['reader_urls'])->toBeNull();
    $this->getJson(route('public-resources.show', ['group' => $this->group, 'slug' => $this->resource->uuid]))->assertNotFound();
});

it('blocks publication of autosaved changes until they have an explicit saved revision', function () {
    $lease = publication_action($this, 'acquire')['editing_token'];
    publication_action($this, 'save', ['editing_token' => $lease, 'content' => $this->content, 'summary' => 'Initial guide.']);
    publication_action($this, 'autosave', ['editing_token' => $lease, 'content' => array_replace($this->content, ['title' => 'Autosaved changes'])]);
    expect(fn () => publication_action($this, 'publish', ['editing_token' => $lease]))->toThrow(ValidationException::class);
    expect($this->resource->revisions()->count())->toBe(1)->and($this->resource->fresh()->status)->toBe('draft');
    expect(app(ResourceReaderService::class)->managementDetail($this->group, $this->resource->fresh(), $this->user)['can_publish'])->toBeFalse();
    publication_action($this, 'save', ['editing_token' => $lease, 'content' => array_replace($this->content, ['title' => 'Autosaved changes']), 'summary' => 'Updated the title.']);
    expect(app(ResourceReaderService::class)->managementDetail($this->group, $this->resource->fresh(), $this->user)['can_publish'])->toBeTrue();
    publication_action($this, 'publish', ['editing_token' => $lease]);
    expect($this->resource->fresh()->publishedRevision->snapshot['title'])->toBe('Autosaved changes')->and($this->resource->revisions()->count())->toBe(2);
});

it('records the actual publisher separately and paginates edit and publication history together', function () {
    $publisher = User::factory()->create(['name' => 'Publisher']);
    $this->group->memberships()->create(['user_id' => $publisher->id, 'role' => 'admin', 'joined_at' => now()]);
    for ($index = 1; $index <= 3; $index++) {
        $lease = publication_action($this, 'acquire')['editing_token'];
        publication_action($this, 'save', ['editing_token' => $lease, 'content' => array_replace($this->content, ['title' => 'Guide '.$index]), 'summary' => 'Saved edit '.$index]);
        publication_action($this, 'release', ['editing_token' => $lease]);
        publication_action($this, 'publish', user: $publisher);
        $this->travel(1)->seconds();
    }
    expect($this->resource->revisions()->count())->toBe(3)->and(DB::table('group_resource_publications')->count())->toBe(3);
    $management = app(ResourceReaderService::class)->managementDetail($this->group, $this->resource->fresh(), $this->user)['history'];
    expect($management)->toHaveCount(6)->and($management[0]['summary'])->toBe('Publisher published the resource.')
        ->and($management[0]['editor']['name'])->toBe('Publisher')->and($management[1]['summary'])->toBe('Saved edit 3')
        ->and($management[1]['editor']['name'])->toBe($this->user->name);
    $params = ['group' => $this->group, 'slug' => $this->resource->uuid];
    $preview = $this->getJson(route('public-resources.show', $params))->assertOk()->json('data.history');
    expect($preview['data'])->toHaveCount(3)->and($preview['has_more'])->toBeTrue();
    $older = $this->getJson(route('public-resources.history', $params).'?before='.$preview['data'][2]['id'])->assertOk()->json('data');
    expect(array_column(array_merge($preview['data'], $older), 'id'))->toBe(array_column($management, 'id'));
    $afterEdit = $this->getJson(route('public-resources.history', $params).'?before='.$management[1]['id'])->assertOk()->json('data');
    expect(array_column($afterEdit, 'id'))->toBe(array_column(array_slice($management, 2), 'id'));
});

it('keeps restricted publication events private and preserves repeated publication events', function () {
    $lease = publication_action($this, 'acquire')['editing_token'];
    publication_action($this, 'save', ['editing_token' => $lease, 'content' => array_replace($this->content, ['access_level' => 'admin']), 'summary' => 'Private edit.']);
    publication_action($this, 'publish', ['editing_token' => $lease]);
    publication_action($this, 'save', ['editing_token' => $lease, 'content' => $this->content, 'summary' => 'Public edit.']);
    publication_action($this, 'publish', ['editing_token' => $lease]);
    publication_action($this, 'release', ['editing_token' => $lease]);
    publication_action($this, 'unpublish');
    publication_action($this, 'publish');
    expect($this->resource->revisions()->count())->toBe(2)->and(DB::table('group_resource_publications')->count())->toBe(3);
    $data = $this->getJson(route('public-resources.history', ['group' => $this->group, 'slug' => $this->resource->uuid]))->assertOk()->json('data');
    expect($data)->toHaveCount(3)->and(array_column($data, 'summary'))->not->toContain('Private edit.');
});

it('rejects content changelogs and combined save-publish requests at the endpoint', function () {
    $this->actingAs($this->user);
    $route = fn ($operation) => route('groups.dashboard.resources.update', ['group' => $this->group, 'resource' => $this->resource, 'operation' => $operation]);
    $this->postJson($route('publish'), ['version' => 1, 'content' => $this->content, 'summary' => 'Duplicate changelog'])
        ->assertUnprocessable()->assertJsonValidationErrors(['content', 'summary']);
    $this->postJson($route('save'), ['version' => 1, 'content' => $this->content, 'publish' => true, 'summary' => 'Save and publish'])
        ->assertUnprocessable()->assertJsonValidationErrors('publish');
});
