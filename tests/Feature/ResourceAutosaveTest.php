<?php

use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceLibrary;
use App\Models\User;
use App\Services\Groups\Resources\ResourceCommandService;
use App\Services\Groups\Resources\ResourceReaderService;
use App\Services\RichText\RichTextDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->group = Group::factory()->create(['owner_id' => $this->owner->id]);
    $this->group->features()->update(['resource_hub_enabled' => true]);
    $this->actingAs($this->owner);
    $this->content = ['title' => 'Guide', 'slug' => 'old-guide', 'description' => '', 'body' => RichTextDocument::empty(), 'access_level' => 'everyone', 'tags' => [], 'activity_type_ids' => [], 'commands' => []];
    $id = $this->postJson(route('groups.dashboard.resources.store', $this->group), ['content' => $this->content])->assertCreated()->json('data.id');
    $this->resource = GroupResource::findOrFail($id);
    $this->action = function (string $action, array $data = []) {
        return $this->postJson(rtrim(config('app.url'), '/').route('groups.dashboard.resources.update', ['group' => $this->group, 'resource' => $this->resource, 'operation' => $action], false), ['version' => $this->resource->refresh()->version] + $data);
    };
    $token = ($this->action)('acquire')->assertOk()->json('data.editing_token');
    ($this->action)('save', ['editing_token' => $token, 'content' => $this->content, 'summary' => 'Initial guide.'])->assertOk();
    ($this->action)('release', ['editing_token' => $token])->assertOk();
});

it('autosaves the working copy without creating checkpoints or publishing bot commands', function () {
    ($this->action)('publish')->assertOk();
    $published = $this->resource->refresh()->published_revision_id;
    $token = ($this->action)('acquire')->json('data.editing_token');
    $content = array_replace($this->content, ['title' => 'Changed draft', 'commands' => [['name' => 'new-command', 'enabled' => true, 'embed' => ['title' => 'Draft embed']]]]);
    ($this->action)('autosave', ['content' => $content, 'editing_token' => $token])->assertOk()->assertJsonPath('data.resource.working_copy.title', 'Changed draft');
    expect($this->resource->refresh()->published_revision_id)->toBe($published)
        ->and($this->resource->publishedRevision->snapshot['title'])->toBe('Guide')
        ->and($this->resource->revisions()->count())->toBe(1)
        ->and(app(ResourceCommandService::class)->available($this->group)->count())->toBe(0);
    ($this->action)('publish', ['editing_token' => $token])->assertUnprocessable();
    ($this->action)('save', ['editing_token' => $token, 'content' => $content, 'summary' => 'Updated the guide.'])->assertOk();
    ($this->action)('publish', ['editing_token' => $token])->assertOk();
    expect($this->resource->refresh()->revisions()->count())->toBe(2)
        ->and($this->resource->publishedRevision->summary)->toBe('Updated the guide.')
        ->and(app(ResourceCommandService::class)->available($this->group)->count())->toBe(1);
});

it('rejects invalid autosaves with exact field errors and preserves the last saved draft', function () {
    $token = ($this->action)('acquire')->json('data.editing_token');
    ($this->action)('autosave', ['editing_token' => $token, 'content' => array_replace($this->content, [
        'title' => '', 'description' => str_repeat('x', 1001),
        'commands' => [['name' => 'guide', 'enabled' => true, 'embed' => ['title' => 'Guide', 'url' => 'not-a-url', 'fields' => [['name' => '', 'value' => '']]]]],
    ])])->assertUnprocessable()->assertJsonValidationErrors(['title', 'description', 'commands.0.embed.url', 'commands.0.embed.fields.0.name', 'commands.0.embed.fields.0.value']);
    expect($this->resource->refresh()->working_copy['title'])->toBe('Guide');
});

it('reacquires an expired session without permitting stale versions to overwrite another edit', function () {
    $token = ($this->action)('acquire')->json('data.editing_token');
    $version = $this->resource->refresh()->version;
    $this->travel(20)->minutes();
    ($this->action)('autosave', ['editing_token' => $token, 'content' => $this->content])->assertConflict();
    $newToken = ($this->action)('acquire', ['editing_token' => $token])->assertOk()->json('data.editing_token');
    expect($newToken)->not->toBe($token);
    $this->postJson(route('groups.dashboard.resources.update', ['group' => $this->group, 'resource' => $this->resource, 'operation' => 'autosave']), [
        'version' => $version, 'editing_token' => $newToken, 'content' => array_replace($this->content, ['title' => 'Stale text']),
    ])->assertConflict();
    expect($this->resource->refresh()->working_copy['title'])->toBe('Guide');
});

it('reports inaccessible image errors on the cover and the specific embed image fields', function () {
    $token = ($this->action)('acquire')->json('data.editing_token');
    ($this->action)('autosave', ['editing_token' => $token, 'content' => array_replace($this->content, [
        'metadata_image_id' => (string) Str::uuid(),
        'commands' => [['name' => 'guide', 'enabled' => true, 'embed' => ['title' => 'Guide', 'image' => ['asset_id' => (string) Str::uuid()]]]],
    ])])->assertUnprocessable()->assertJsonValidationErrors(['metadata_image_id', 'commands.0.embed.image']);
});

it('archives without losing history and restores as an unpublished draft', function () {
    ($this->action)('publish')->assertOk();
    $uuid = $this->resource->uuid;
    ($this->action)('archive')->assertOk()->assertJsonPath('data.resource.status', 'archived')->assertJsonPath('data.resource.reader_urls', null);
    expect(collect(app(ResourceReaderService::class)->workspace($this->group, $this->owner)['resources'])->firstWhere('id', $this->resource->id)['status'])->toBe('archived');
    ($this->action)('acquire')->assertConflict();
    ($this->action)('publish')->assertConflict();
    $this->get(route('groups.dashboard.resources.show', ['group' => $this->group, 'slug' => $uuid]))->assertNotFound();
    ($this->action)('unarchive')->assertOk()->assertJsonPath('data.resource.status', 'draft');
    expect($this->resource->refresh()->uuid)->toBe($uuid)->and($this->resource->revisions()->count())->toBe(1);
    $this->get(route('groups.dashboard.resources.show', ['group' => $this->group, 'slug' => $uuid]))->assertNotFound();
    ($this->action)('publish')->assertOk();
    $this->get(route('groups.dashboard.resources.show', ['group' => $this->group, 'slug' => $uuid]))->assertOk();
});

it('uses immutable UUID reader links and redirects old aliases without exposing restricted resources', function () {
    expect(Str::isUuid($this->resource->uuid))->toBeTrue();
    ($this->action)('publish')->assertOk();
    GroupResourceLibrary::where('group_id', $this->group->id)->update(['visibility' => 'public']);
    $canonical = route('public-resources.show', ['group' => $this->group, 'slug' => $this->resource->uuid]);
    $old = route('public-resources.show', ['group' => $this->group, 'slug' => 'old-guide']);
    $this->getJson($old)->assertRedirect($canonical);
    $this->getJson($canonical)->assertOk()->assertJsonPath('data.title', 'Guide');
    ($this->action)('archive')->assertOk();
    $this->getJson($old)->assertNotFound();
    $this->getJson($canonical)->assertNotFound();
});
