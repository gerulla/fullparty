<?php

use App\Models\ActivityType;
use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceCollection;
use App\Models\GroupResourceLibrary;
use App\Models\User;
use App\Services\RichText\RichTextDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function hub_resource(Group $group, string $title, string $access = 'everyone', ?int $collectionId = null, string $status = 'published', array $activities = []): GroupResource
{
    $resource = GroupResource::factory()->create([
        'group_id' => $group->id, 'collection_id' => $collectionId, 'access_level' => $access,
        'management_access_level' => $access, 'status' => $status, 'published_at' => now(),
    ]);
    $revision = $resource->revisions()->create([
        'editor_user_id' => $group->owner_id, 'editor' => ['name' => 'Editor'], 'state' => 'published',
        'summary' => 'Published guide', 'snapshot' => [
            'title' => $title, 'description' => 'Published introduction', 'body' => RichTextDocument::empty(), 'body_text' => $title,
            'access_level' => $access, 'tags' => ['guide'], 'activity_type_ids' => $activities, 'author' => ['name' => 'Author'],
        ],
    ]);
    $resource->update(['published_revision_id' => $revision->id]);
    $resource->activityTypes()->sync($activities);

    return $resource;
}

beforeEach(function () {
    $this->group = Group::factory()->create();
    $this->group->features()->update(['resource_hub_enabled' => true]);
});

it('scopes sidebar resources and activity filters to published resources the reader can access', function (string $audience, array $visible) {
    $types = ActivityType::factory()->count(4)->withPublishedVersion()->create();
    hub_resource($this->group, 'Everyone guide', activities: [$types[0]->id]);
    hub_resource($this->group, 'Moderator guide', 'moderator', activities: [$types[1]->id]);
    hub_resource($this->group, 'Admin guide', 'admin', activities: [$types[2]->id]);
    hub_resource($this->group, 'Draft guide', status: 'draft', activities: [$types[3]->id]);
    hub_resource($this->group, 'Archived guide', status: 'archived', activities: [$types[3]->id]);
    hub_resource(Group::factory()->create(), 'Foreign guide', activities: [$types[3]->id]);
    GroupResource::where('is_home', false)->update(['is_pinned' => true]);
    if ($audience === 'public') {
        $response = $this->getJson(route('public-resources.index', $this->group))->assertOk();
        $reader = $response->json('reader');
        $response->assertJsonMissingPath('auth')->assertJsonMissingPath('reader.recent_resources.0.body');
    } else {
        $user = User::factory()->create();
        $this->group->memberships()->create(['user_id' => $user->id, 'role' => $audience, 'joined_at' => now()]);
        $response = $this->actingAs($user)->get(route('groups.dashboard.resources.index', $this->group))->assertOk();
        $reader = $response->inertiaProps('reader');
    }
    expect(collect($reader['recent_resources'])->pluck('title')->all())->toEqualCanonicalizing($visible)
        ->and(collect($reader['pinned_resources'])->pluck('title')->all())->toEqualCanonicalizing($visible)
        ->and(collect($reader['activities'])->pluck('id')->all())->toEqualCanonicalizing($types->take(count($visible))->modelKeys());
})->with([
    ['public', ['Everyone guide']], ['member', ['Everyone guide']],
    ['moderator', ['Everyone guide', 'Moderator guide']], ['admin', ['Everyone guide', 'Moderator guide', 'Admin guide']],
]);

it('includes the complete public hub context when opening an article directly', function () {
    $folder = GroupResourceCollection::create(['group_id' => $this->group->id, 'name' => 'Guides', 'slug' => 'guides']);
    $resource = hub_resource($this->group, 'Published title', collectionId: $folder->id);
    $resource->update(['working_copy' => ['title' => 'Unpublished secret'], 'updated_at' => now()->addDay()]);
    $this->withSession(['locale' => 'fr'])->get(route('public-resources.show', ['group' => $this->group, 'slug' => $resource->uuid]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Resources/Show')
        ->where('locale.current', 'fr')->where('resource.title', 'Published title')
        ->where('library.visibility', 'public')->where('collections.0.id', $folder->id)
        ->where('reader.recent_resources.0.title', 'Published title')
        ->where('group.description', $this->group->description)->where('group.datacenter', $this->group->datacenter)
        ->missing('auth')->missing('resource.working_copy'));
});

it('renders public article sharing metadata from published content with the library image as fallback', function (bool $cover) {
    $sharingId = (string) Str::uuid();
    $coverId = (string) Str::uuid();
    GroupResourceLibrary::create(['group_id' => $this->group->id, 'customization' => [
        'title' => 'Our resource library', 'introduction' => 'Library introduction', 'sharing_image_id' => $sharingId,
    ]]);
    $resource = hub_resource($this->group, 'Published guide');
    $snapshot = $resource->publishedRevision->snapshot;
    $resource->publishedRevision->update(['snapshot' => $snapshot + ['metadata_image_id' => $cover ? $coverId : null]]);
    $resource->update(['working_copy' => array_replace($snapshot, [
        'title' => 'Unpublished title', 'description' => 'Unpublished description', 'metadata_image_id' => (string) Str::uuid(),
    ])]);
    $canonical = route('public-resources.show', ['group' => $this->group, 'slug' => $resource->uuid]);
    $imageUrl = route('public-resources.images.show', ['image' => $cover ? $coverId : $sharingId]);
    $this->get($canonical.'?utm_source=discord')->assertOk()
        ->assertSee('<meta property="og:image" content="'.$imageUrl.'"', false)
        ->assertSee('<meta property="og:description" content="Published introduction"', false)
        ->assertSee('<link rel="canonical" href="'.$canonical.'"', false)
        ->assertSee('inertia="og:image"', false)->assertSee('inertia="og:title"', false)
        ->assertDontSee('Unpublished title')->assertDontSee('Unpublished description')
        ->assertInertia(fn (Assert $page) => $page->where('seo.title', 'Published guide - Our resource library')
            ->where('seo.description', 'Published introduction')->where('seo.image', $imageUrl)
            ->where('seo.url', $canonical)->where('seo.type', 'article'));
})->with([true, false]);

it('uses library branding for homepage and collection previews', function (bool $collection) {
    $sharingId = (string) Str::uuid();
    GroupResourceLibrary::create(['group_id' => $this->group->id, 'customization' => [
        'title' => 'Our resource library', 'introduction' => 'Library introduction', 'sharing_image_id' => $sharingId,
    ]]);
    $folder = GroupResourceCollection::create(['group_id' => $this->group->id, 'name' => 'Guides', 'slug' => 'guides']);
    hub_resource($this->group, 'Guide', collectionId: $folder->id);
    $url = $collection
        ? route('public-resources.collections.show', ['group' => $this->group, 'collectionSlug' => $folder->slug])
        : route('public-resources.index', $this->group);
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('seo.title', $collection ? 'Guides - Our resource library' : 'Our resource library')
        ->where('seo.description', 'Library introduction')->where('seo.type', 'website')
        ->where('seo.url', $url)->where('seo.image', route('public-resources.images.show', ['image' => $sharingId])));
})->with([true, false]);

it('shows only published enabled Discord command names and titles on both reader surfaces', function (bool $public) {
    $resource = hub_resource($this->group, 'Command guide');
    $snapshot = $resource->publishedRevision->snapshot;
    $snapshot['commands'] = [
        ['name' => 'raid-plan', 'enabled' => true, 'embed' => ['title' => 'Raid plan', 'description' => 'Embed body', 'fields' => [['name' => 'Details', 'value' => 'Embed field']]]],
        ['name' => 'disabled-command', 'enabled' => false, 'embed' => ['title' => 'Disabled title']],
    ];
    $resource->publishedRevision->update(['snapshot' => $snapshot]);
    $resource->update(['working_copy' => array_replace($snapshot, ['commands' => [
        ['name' => 'unpublished-command', 'enabled' => true, 'embed' => ['title' => 'Draft title']],
    ]])]);
    $expected = [['name' => 'raid-plan', 'title' => 'Raid plan']];
    if ($public) {
        $this->getJson(route('public-resources.show', ['group' => $this->group, 'slug' => $resource->uuid]))
            ->assertOk()->assertJsonPath('data.commands', $expected)->assertJsonMissingPath('data.commands.0.embed');
    } else {
        $this->actingAs($this->group->owner)->get(route('groups.dashboard.resources.show', ['group' => $this->group, 'slug' => $resource->uuid]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('resource.commands', $expected)->missing('resource.commands.0.embed'));
    }
})->with([true, false]);

it('returns an empty reader command list when there are no enabled published commands', function () {
    $resource = hub_resource($this->group, 'No commands');
    $this->getJson(route('public-resources.show', ['group' => $this->group, 'slug' => $resource->uuid]))
        ->assertOk()->assertJsonPath('data.commands', []);
});

it('returns the selected parent collection and its visible descendants for browsing', function () {
    $parent = GroupResourceCollection::create(['group_id' => $this->group->id, 'name' => 'Parent', 'slug' => 'parent']);
    $child = GroupResourceCollection::create(['group_id' => $this->group->id, 'name' => 'Child', 'slug' => 'child', 'parent_id' => $parent->id]);
    hub_resource($this->group, 'Child guide', collectionId: $child->id);
    $this->getJson(route('public-resources.collections.show', ['group' => $this->group, 'collectionSlug' => 'parent']))
        ->assertOk()->assertJsonPath('reader.selected_collection_id', $parent->id)
        ->assertJsonCount(2, 'collections')->assertJsonPath('resources.total', 0)->assertJsonMissingPath('resource');
    $this->actingAs($this->group->owner)->get(rtrim(config('app.url'), '/').route('groups.dashboard.resources.collections.show', ['group' => $this->group, 'collectionSlug' => 'child'], false))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('reader.selected_collection_id', $child->id)->where('resources.data.0.title', 'Child guide'));
});

it('paginates resource sections and keeps a bounded recent list using publication dates', function () {
    for ($index = 1; $index <= 53; $index++) {
        hub_resource($this->group, 'Guide '.$index)->update(['published_at' => now()->subDays(54 - $index)]);
    }
    GroupResource::where('group_id', $this->group->id)->where('is_home', false)->oldest('id')->first()->update(['updated_at' => now()->addDay()]);
    $this->getJson(route('public-resources.index', $this->group).'?page=2')->assertOk()
        ->assertJsonPath('resources.per_page', 50)->assertJsonPath('resources.current_page', 2)
        ->assertJsonPath('resources.total', 54)->assertJsonCount(4, 'resources.data')
        ->assertJsonCount(5, 'reader.recent_resources')->assertJsonPath('reader.recent_resources.0.title', 'Guide 53');
});

it('keeps public browsing blocked for group-only libraries', function () {
    $resource = hub_resource($this->group, 'Private library guide');
    GroupResourceLibrary::create(['group_id' => $this->group->id, 'visibility' => 'private']);
    $this->get(route('public-resources.index', $this->group))->assertNotFound();
    $this->get(route('public-resources.show', ['group' => $this->group, 'slug' => $resource->uuid]))->assertNotFound();
    $this->getJson(route('public-resources.history', ['group' => $this->group, 'slug' => $resource->uuid]))->assertNotFound();
});

it('previews at most three published edits and appends all older accessible history', function (string $audience, array $levels) {
    $resource = hub_resource($this->group, 'Guide');
    foreach (['everyone', 'moderator', 'admin'] as $level) {
        for ($index = 1; $index <= 5; $index++) {
            $resource->revisions()->create([
                'editor' => ['name' => 'Contributor '.$index, 'avatar_url' => '/avatar.png', 'private_field' => 'Hidden'],
                'state' => 'published', 'summary' => $level.' edit '.$index,
                'snapshot' => ['access_level' => $level, 'body' => ['secret' => 'Never returned']],
            ]);
        }
    }
    $resource->revisions()->create(['editor' => ['name' => 'Draft editor'], 'state' => 'draft', 'summary' => 'Unpublished secret', 'snapshot' => ['access_level' => 'everyone']]);
    $expected = $resource->revisions()->where('state', 'published')->whereIn('snapshot->access_level', $levels)->latest('id')->pluck('id')->all();
    $params = ['group' => $this->group, 'slug' => $resource->uuid];
    if ($audience === 'public') {
        $preview = $this->getJson(route('public-resources.show', $params))->assertOk()->json('data.history');
        $historyUrl = route('public-resources.history', $params);
    } else {
        $user = User::factory()->create();
        $this->group->memberships()->create(['user_id' => $user->id, 'role' => $audience, 'joined_at' => now()]);
        $this->actingAs($user);
        $preview = $this->get(rtrim(config('app.url'), '/').route('groups.dashboard.resources.show', $params, false))->assertOk()->inertiaProps('resource.history');
        $historyUrl = rtrim(config('app.url'), '/').route('groups.dashboard.resources.history', $params, false);
    }
    expect($preview['has_more'])->toBeTrue()->and(array_column($preview['data'], 'id'))->toBe(array_slice($expected, 0, 3));
    $more = $this->getJson($historyUrl.'?before='.$preview['data'][2]['id'])->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->assertJsonMissingPath('data.0.snapshot')
        ->assertJsonMissingPath('data.0.editor.private_field')->assertJsonMissing(['summary' => 'Unpublished secret']);
    expect(array_column($more->json('data'), 'id'))->toBe(array_slice($expected, 3));
    $this->getJson($historyUrl)->assertOk()->assertJsonCount(count($expected), 'data');
    $this->getJson($historyUrl.'?before=0')->assertUnprocessable();
})->with([
    ['public', ['everyone']], ['member', ['everyone']],
    ['moderator', ['everyone', 'moderator']], ['admin', ['everyone', 'moderator', 'admin']],
]);

it('does not offer more history for three or fewer visible edits', function () {
    $resource = hub_resource($this->group, 'Short history');
    $url = route('public-resources.show', ['group' => $this->group, 'slug' => $resource->uuid]);
    $this->getJson($url)->assertOk()->assertJsonCount(1, 'data.history.data')->assertJsonPath('data.history.has_more', false);
});

it('protects reader history against inaccessible and mismatched resources', function () {
    foreach (['draft', 'archived'] as $status) {
        $resource = hub_resource($this->group, $status, status: $status);
        $this->getJson(route('public-resources.history', ['group' => $this->group, 'slug' => $resource->uuid]))->assertNotFound();
    }
    $restricted = hub_resource($this->group, 'Restricted', 'admin');
    $this->getJson(route('public-resources.history', ['group' => $this->group, 'slug' => $restricted->uuid]))->assertNotFound();
    $resource = hub_resource($this->group, 'Visible');
    $foreign = Group::factory()->create();
    $foreign->features()->update(['resource_hub_enabled' => true]);
    $this->getJson(route('public-resources.history', ['group' => $foreign, 'slug' => $resource->uuid]))->assertNotFound();
    $internal = rtrim(config('app.url'), '/').route('groups.dashboard.resources.history', ['group' => $this->group, 'slug' => $resource->uuid], false);
    $this->getJson($internal)->assertUnauthorized();
    $this->group->update(['is_visible' => true]);
    $this->actingAs($foreign->owner)->getJson($internal)->assertRedirect(route('groups.index'));
    $this->group->features()->update(['resource_hub_enabled' => false]);
    $this->getJson(route('public-resources.history', ['group' => $this->group, 'slug' => $resource->uuid]))->assertNotFound();
});

it('finds published titles descriptions bodies and partial tags while keeping draft tags private', function (string $search) {
    $resource = hub_resource($this->group, 'Moonlit preparation');
    $snapshot = array_replace($resource->publishedRevision->snapshot, ['description' => 'Bring consumables', 'body_text' => 'Meet at the north gate', 'tags' => ['progression', '攻略']]);
    $resource->publishedRevision->update(['snapshot' => $snapshot]);
    $resource->update(['working_copy' => array_replace($snapshot, ['tags' => ['secret-draft-tag']])]);
    hub_resource($this->group, 'Hidden', 'admin')->publishedRevision->update(['snapshot' => $snapshot]);
    hub_resource($this->group, 'Unpublished', status: 'draft')->publishedRevision->update(['snapshot' => $snapshot]);
    $base = route('public-resources.index', $this->group);
    $this->getJson($base.'?'.http_build_query(['q' => $search]))->assertOk()
        ->assertJsonPath('resources.total', 1)->assertJsonPath('resources.data.0.id', $resource->id);
    $this->getJson($base.'?q=secret-draft-tag')->assertOk()->assertJsonPath('resources.total', 0);
})->with(['moonlit', 'CONSUMABLES', 'north gate', 'progres', '#Progression', '攻略']);

it('keeps same-collection suggestions and pinned library resources available together', function () {
    $collection = GroupResourceCollection::create(['group_id' => $this->group->id, 'name' => 'Raid guides', 'slug' => 'raid-guides']);
    $article = hub_resource($this->group, 'Current guide', collectionId: $collection->id);
    $sibling = hub_resource($this->group, 'Another guide', collectionId: $collection->id);
    $pinned = hub_resource($this->group, 'Pinned outside collection');
    $pinned->update(['is_pinned' => true]);
    $this->get(route('public-resources.show', ['group' => $this->group, 'slug' => $article->uuid]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('resource.related_resources.0.id', $sibling->id)
            ->where('collections.0.name', 'Raid guides')->where('reader.pinned_resources.0.id', $pinned->id));
});
