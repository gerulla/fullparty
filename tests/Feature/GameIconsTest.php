<?php

use App\Models\Group;
use App\Models\User;
use App\Services\Groups\Resources\ResourceWorkflowService;
use App\Services\RichText\GameIconCatalog;
use App\Services\RichText\RichTextDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('builds a localized local-only catalog covering every requested category without importing database records', function () {
    Http::preventStrayRequests();
    $catalog = app(GameIconCatalog::class);
    $icons = $catalog->all();
    expect(array_values(array_unique(array_column($icons, 'category'))))->toEqualCanonicalizing(['class', 'phantom_job', 'role', 'class_action', 'role_action', 'phantom_action', 'bozja_action', 'bozja_item']);
    expect($catalog->find('class_pld')['shortcode'])->toBe('pld')
        ->and($catalog->find('phantom_knight')['shortcode'])->toBe('phantom_knight')
        ->and($catalog->find('role_physrange')['shortcode'])->toBe('physrange')
        ->and($catalog->find('action_30')['names']['de'])->toBe('Heiliger Boden')
        ->and($catalog->find('action_7531')['shortcode'])->toBe('rampart')
        ->and($catalog->find('bozja_26')['shortcode'])->toBe('lost_cure');
    expect(array_unique(array_column($icons, 'key')))->toHaveCount(count($icons));
    expect(array_unique(array_column($icons, 'shortcode')))->toHaveCount(count($icons));
    foreach ($icons as $icon) {
        expect($icon['shortcode'])->toMatch('/^[a-z0-9_]{1,100}$/D');
        expect($icon['names'])->toHaveKeys(['en', 'de', 'fr', 'ja']);
        expect(is_file(public_path(rawurldecode(ltrim($icon['src'], '/')))))->toBeTrue();
    }
    expect(collect($icons)->where('key', 'action_7531'))->toHaveCount(1);
    $this->assertDatabaseCount('calculator_actions', 0);
    Http::assertNothingSent();
});

it('provides the cached catalog only on the authenticated editor surface', function () {
    $url = route('editor.game-icons');
    $this->getJson($url)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->getJson($url)->assertOk()
        ->assertJsonPath('icons.0.category', 'class')->assertHeader('Cache-Control', 'max-age=3600, private');
});

it('preserves inline game icons through HTML and JSON while canonicalizing client supplied URLs', function () {
    $service = app(RichTextDocument::class);
    $document = $service->validate(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'Use '],
        ['type' => 'gameIcon', 'attrs' => ['key' => 'class_pld', 'src' => 'javascript:alert(1)', 'shortcode' => '<script>']],
        ['type' => 'text', 'text' => ' here.'],
    ]]]]);
    $html = $service->html($document);
    expect($html)->toContain('data-game-icon-key="class_pld"', 'alt=":pld:"', 'src="/reference-icons/character-classes/icons/pld.webp"')->not->toContain('javascript:', '<script>');
    expect($service->text($document))->toBe('Use :pld: here.')
        ->and($service->imageUrls($document))->toBe([]);
    $roundTrip = $service->validate($service->editor()->setContent($html)->getDocument());
    expect($roundTrip)->toBe($document);
});

it('rejects unknown, malformed or misplaced game icons', function (array $node) {
    expect(fn () => app(RichTextDocument::class)->validate(['type' => 'doc', 'content' => [$node]]))->toThrow(ValidationException::class);
})->with([
    'block position' => [['type' => 'gameIcon', 'attrs' => ['key' => 'class_pld']]],
    'unknown key' => [['type' => 'paragraph', 'content' => [['type' => 'gameIcon', 'attrs' => ['key' => '../../secret']]]]],
    'missing key' => [['type' => 'paragraph', 'content' => [['type' => 'gameIcon']]]],
    'extra attribute' => [['type' => 'paragraph', 'content' => [['type' => 'gameIcon', 'attrs' => ['key' => 'class_pld', 'onerror' => 'alert(1)']]]]],
    'code block' => [['type' => 'codeBlock', 'content' => [['type' => 'gameIcon', 'attrs' => ['key' => 'class_pld']]]]],
]);

it('saves and publishes game icons without treating bundled assets as resource uploads', function () {
    $group = Group::factory()->create();
    $group->features()->update(['resource_hub_enabled' => true]);
    $body = app(RichTextDocument::class)->validate(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
        ['type' => 'gameIcon', 'attrs' => ['key' => 'bozja_26']],
        ['type' => 'text', 'text' => ' Lost Cure'],
    ]]]]);
    $content = ['title' => 'Icon guide', 'slug' => 'icon-guide', 'description' => '', 'body' => $body, 'tags' => [], 'activity_type_ids' => [], 'access_level' => 'everyone', 'author' => ['name' => 'Author']];
    $workflow = app(ResourceWorkflowService::class);
    $resource = $workflow->create($group, $group->owner, ['content' => $content]);
    $lease = $workflow->mutate($group, $resource, $group->owner, 'acquire', ['version' => $resource->fresh()->version]);
    $saved = $workflow->mutate($group, $resource, $group->owner, 'save', ['version' => $lease['version'], 'editing_token' => $lease['editing_token'], 'content' => $content, 'summary' => 'Added icons.']);
    $workflow->mutate($group, $resource, $group->owner, 'publish', ['version' => $saved['version'], 'editing_token' => $lease['editing_token']]);
    $this->getJson(route('public-resources.show', ['group' => $group, 'slug' => $resource->uuid]))->assertOk()->assertJsonPath('data.body', $body);
});
