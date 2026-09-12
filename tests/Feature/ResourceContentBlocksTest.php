<?php

use App\Models\Group;
use App\Models\GroupResource;
use App\Models\User;
use App\Services\Groups\Resources\ResourceSnapshotValidator;
use App\Services\Groups\Resources\ResourceWorkflowService;
use App\Services\RichText\RichTextDocument;
use App\Support\VideoEmbedUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function content_block_document(array $nodes): array
{
    return ['type' => 'doc', 'content' => $nodes];
}

function content_block_resource(Group $group, string $title, string $access = 'everyone', array $nodes = [['type' => 'paragraph']]): GroupResource
{
    $resource = GroupResource::factory()->create(['group_id' => $group->id, 'access_level' => $access, 'management_access_level' => $access, 'status' => 'published']);
    $snapshot = ['title' => $title, 'slug' => $resource->slug, 'description' => 'Published description', 'body' => content_block_document($nodes), 'tags' => ['guide'], 'activity_type_ids' => [], 'access_level' => $access, 'author' => ['name' => 'Author']];
    $revision = $resource->revisions()->create(['editor' => ['name' => 'Author'], 'summary' => 'Published', 'state' => 'published', 'snapshot' => $snapshot]);
    $resource->update(['published_revision_id' => $revision->id, 'working_copy' => $snapshot]);

    return $resource;
}

it('normalizes supported video URLs and rejects untrusted embed destinations', function () {
    foreach (json_decode(file_get_contents(base_path('tests/Fixtures/resource-video-urls.json')), true) as [$input, $expected]) {
        expect(VideoEmbedUrl::normalize($input))->toBe($expected, $input);
    }
});

it('validates resource-only blocks and renders safe HTML fallbacks', function () {
    $document = content_block_document([
        ['type' => 'resourceLink', 'attrs' => ['resourceId' => (string) str()->uuid()]],
        ['type' => 'videoEmbed', 'attrs' => ['url' => 'https://youtu.be/dQw4w9WgXcQ?t=90', 'title' => '<script>alert(1)</script>']],
    ]);
    $service = app(RichTextDocument::class);
    expect(fn () => $service->validate($document))->toThrow(ValidationException::class);
    $saved = $service->validate($document, resourceBlocks: true);
    expect($saved['content'][1]['attrs']['url'])->toBe('https://www.youtube.com/watch?v=dQw4w9WgXcQ&start=90');
    expect($service->html($saved, resourceBlocks: true))->toContain('data-resource-link', '&lt;script&gt;')->not->toContain('<script>', '<iframe');
});

it('rejects malformed card and video blocks', function (array $node) {
    expect(fn () => app(RichTextDocument::class)->validate(content_block_document([$node]), resourceBlocks: true))->toThrow(ValidationException::class);
})->with([
    [['type' => 'resourceLink']],
    [['type' => 'resourceLink', 'attrs' => ['resourceId' => 'not-a-uuid']]],
    [['type' => 'resourceLink', 'attrs' => ['resourceId' => 'ae5a9e25-1dab-4d41-a31f-2e1ed842442b', 'title' => 'Forged title']]],
    [['type' => 'videoEmbed']],
    [['type' => 'videoEmbed', 'attrs' => ['url' => 'https://evil.test/player']]],
    [['type' => 'videoEmbed', 'attrs' => ['url' => 'https://youtu.be/dQw4w9WgXcQ', 'src' => 'https://evil.test']]],
    [['type' => 'videoEmbed', 'attrs' => ['url' => 'https://youtu.be/dQw4w9WgXcQ'], 'content' => [['type' => 'paragraph']]]],
]);

it('saves and publishes both blocks through the resource workflow', function () {
    $group = Group::factory()->create();
    $group->features()->update(['resource_hub_enabled' => true]);
    $target = content_block_resource($group, 'Linked guide');
    $content = $target->working_copy;
    $content['slug'] = 'new-guide';
    $content['body'] = content_block_document([
        ['type' => 'resourceLink', 'attrs' => ['resourceId' => $target->uuid]],
        ['type' => 'videoEmbed', 'attrs' => ['url' => 'https://www.twitch.tv/fullparty', 'title' => 'Live strategy']],
    ]);
    $workflow = app(ResourceWorkflowService::class);
    $source = $workflow->create($group, $group->owner, ['content' => $content]);
    $lease = $workflow->mutate($group, $source, $group->owner, 'acquire', ['version' => $source->fresh()->version]);
    $saved = $workflow->mutate($group, $source, $group->owner, 'save', ['version' => $lease['version'], 'editing_token' => $lease['editing_token'], 'content' => $content, 'summary' => 'Added resource and video blocks.']);
    $workflow->mutate($group, $source, $group->owner, 'publish', ['version' => $saved['version'], 'editing_token' => $lease['editing_token']]);
    $this->getJson(route('public-resources.show', ['group' => $group, 'slug' => $source->uuid]))->assertOk()
        ->assertJsonPath('data.body', $content['body'])->assertJsonPath('data.linked_resources.0.title', 'Linked guide');
});

it('resolves only current published cards visible to each reader without leaking drafts', function (string $audience, array $expected) {
    $group = Group::factory()->create();
    $group->features()->update(['resource_hub_enabled' => true]);
    $targets = collect(['everyone', 'moderator', 'admin'])->map(fn ($level) => content_block_resource($group, $level, $level));
    $targets->push(content_block_resource(Group::factory()->create(), 'foreign'));
    $targets->push(content_block_resource($group, 'archived'));
    $targets->last()->update(['status' => 'archived']);
    $targets->push(content_block_resource($group, 'draft'));
    $targets->last()->update(['status' => 'draft']);
    $targets->first()->update(['working_copy' => array_replace($targets->first()->working_copy, ['title' => 'Secret draft'])]);
    $source = content_block_resource($group, 'Source', nodes: $targets->map(fn ($target) => ['type' => 'resourceLink', 'attrs' => ['resourceId' => $target->uuid]])->all());
    if ($audience === 'public') {
        $cards = $this->getJson(route('public-resources.show', ['group' => $group, 'slug' => $source->uuid]))->assertOk()->json('data.linked_resources');
    } else {
        $user = User::factory()->create();
        $group->memberships()->create(['user_id' => $user->id, 'role' => $audience, 'joined_at' => now()]);
        $cards = $this->actingAs($user)->get(route('groups.dashboard.resources.show', ['group' => $group, 'slug' => $source->uuid]))->assertOk()->inertiaProps('resource.linked_resources');
    }
    expect(collect($cards)->pluck('title')->all())->toEqualCanonicalizing($expected);
    foreach ($cards as $card) {
        expect($card)->not->toHaveKeys(['body', 'working_copy', 'commands']);
    }
})->with([['public', ['everyone']], ['member', ['everyone']], ['moderator', ['everyone', 'moderator']], ['admin', ['everyone', 'moderator', 'admin']]]);

it('rejects new foreign or inaccessible references but preserves existing unavailable links', function () {
    $group = Group::factory()->create();
    $group->features()->update(['resource_hub_enabled' => true]);
    $moderator = User::factory()->create();
    $group->memberships()->create(['user_id' => $moderator->id, 'role' => 'moderator', 'joined_at' => now()]);
    $source = content_block_resource($group, 'Source');
    $validator = app(ResourceSnapshotValidator::class);
    foreach ([content_block_resource($group, 'Private', 'admin'), content_block_resource(Group::factory()->create(), 'Foreign')] as $target) {
        $content = $source->working_copy;
        $content['body'] = content_block_document([['type' => 'resourceLink', 'attrs' => ['resourceId' => $target->uuid]]]);
        expect(fn () => $validator->validate($group, $moderator, $content, $source))->toThrow(ValidationException::class);
    }
    $target = content_block_resource($group, 'Removed');
    $content = $source->working_copy;
    $content['body'] = content_block_document([['type' => 'resourceLink', 'attrs' => ['resourceId' => $target->uuid]]]);
    $source->update(['working_copy' => $content]);
    $target->delete();
    expect($validator->validate($group, $moderator, $content, $source)['body'])->toBe($content['body']);
    $source->revisions()->create(['editor' => ['name' => 'Author'], 'summary' => 'Older linked version', 'state' => 'published', 'snapshot' => $content]);
    $source->update(['working_copy' => array_replace($content, ['body' => RichTextDocument::empty()])]);
    expect($validator->validate($group, $moderator, $content, $source)['body'])->toBe($content['body']);
});
