<?php

use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceLibrary;
use App\Models\User;
use App\Services\Groups\Resources\XivGearImportService;
use App\Services\Moderation\ReportContentPreview;
use App\Services\RichText\RichTextDocument;
use App\Support\XivGearSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

const XIVGEAR_PLD_URL = 'https://xivgear.app/sl/9e283c04-39af-4b86-b5b9-22f1f58a18f1';

beforeEach(function () {
    Cache::flush();
    Storage::fake('local');
    Http::preventStrayRequests();
    Http::fake([
        'api.xivgear.app/fulldata*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/xivgear-pld.json')), true)),
        'v2.xivapi.com/api/sheet/Item*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/xivgear-pld-items.json')), true)),
        'v2.xivapi.com/api/asset*' => Http::response("\x89PNG\r\n\x1a\nfixture"),
    ]);
    $this->group = Group::factory()->create();
    $this->group->features()->update(['resource_hub_enabled' => true]);
    GroupResourceLibrary::create(['group_id' => $this->group->id, 'visibility' => 'public']);
    $this->url = route('groups.dashboard.resources.gearsets.import', $this->group);
});

it('offers actual sets from the supplied PLD sheet and skips separator rows', function () {
    $this->actingAs($this->group->owner)->postJson($this->url, ['url' => XIVGEAR_PLD_URL])->assertOk()
        ->assertJsonCount(2, 'sets')->assertJsonPath('sets.0.index', 0)->assertJsonPath('sets.1.index', 2)
        ->assertJsonPath('sets.0.name', '2.50 ✪')->assertJsonPath('snapshots', []);
    Http::assertSentCount(1);
});

it('imports both real PLD sets including sword shield materia food and localized item names', function () {
    $result = $this->actingAs($this->group->owner)->postJson($this->url, ['url' => XIVGEAR_PLD_URL, 'set_indices' => [0, 2]])->assertOk()->json();
    expect($result['snapshots'])->toHaveCount(2);
    foreach ($result['snapshots'] as $snapshot) {
        expect(XivGearSnapshot::valid($snapshot))->toBeTrue();
        expect($snapshot['items'])->toHaveCount(12);
        expect($snapshot['job'])->toBe('PLD');
        expect($snapshot['gcd'])->toEqual(2.5);
        expect($snapshot['itemLevelSync'])->toBe(710);
        expect($snapshot['food']['id'])->toBe(49240);
        expect($snapshot['items'][0]['slot'])->toBe('Weapon');
        expect($snapshot['items'][1]['slot'])->toBe('OffHand');
        expect($snapshot['items'][1]['id'])->toBe(50053);
        expect($snapshot['items'][0]['materia'])->toHaveCount(5);
        expect($snapshot['items'][0]['names']['ja'])->not->toBe($snapshot['items'][0]['names']['en']);
        Storage::disk('local')->assertExists('xivgear-icons/'.$snapshot['items'][1]['icon'].'.png');
    }
    $count = count(Http::recorded());
    $again = app(XivGearImportService::class)->import(XIVGEAR_PLD_URL, [0, 2]);
    expect($again)->toBe($result);
    Http::assertSentCount($count);
});

it('requires resource management access before contacting external services', function () {
    $this->actingAs(User::factory()->create())->postJson($this->url, ['url' => XIVGEAR_PLD_URL])->assertNotFound();
    $member = User::factory()->create();
    $this->group->memberships()->create(['user_id' => $member->id, 'role' => 'member', 'joined_at' => now()]);
    $this->actingAs($member)->postJson($this->url, ['url' => XIVGEAR_PLD_URL])->assertForbidden();
    Http::assertNothingSent();
});

it('rejects untrusted destinations and malformed links without a request', function (string $url) {
    $this->actingAs($this->group->owner)->postJson($this->url, ['url' => $url])->assertUnprocessable()->assertJsonValidationErrors('url');
    Http::assertNothingSent();
})->with(['https://evil.test/sl/example', 'http://xivgear.app/sl/example', 'https://xivgear.app.evil.test', 'https://user@xivgear.app/sl/id', 'https://xivgear.app:443/sl/id', 'https://xivgear.app\\@127.0.0.1']);

it('rejects separator selections and excessive or repeated selected sets', function () {
    $this->actingAs($this->group->owner)->postJson($this->url, ['url' => XIVGEAR_PLD_URL, 'set_indices' => [1]])->assertUnprocessable();
    $this->postJson($this->url, ['url' => XIVGEAR_PLD_URL, 'set_indices' => [0, 0]])->assertUnprocessable();
    $this->postJson($this->url, ['url' => XIVGEAR_PLD_URL, 'set_indices' => range(0, 20)])->assertUnprocessable();
});

it('reports remote failures and does not follow redirects', function (int $status) {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['api.xivgear.app/*' => Http::response('', $status, ['Location' => 'https://evil.test'])]);
    $this->actingAs($this->group->owner)->postJson($this->url, ['url' => XIVGEAR_PLD_URL])->assertUnprocessable()->assertJsonValidationErrors('url');
    Http::assertSentCount(1);
})->with([302, 404, 429, 500]);

it('saves and publishes static tabbed snapshots with the editor chosen display', function (string $display) {
    $snapshots = app(XivGearImportService::class)->import(XIVGEAR_PLD_URL, [0, 2])['snapshots'];
    $body = ['type' => 'doc', 'content' => [['type' => 'xivGear', 'attrs' => ['display' => $display, 'snapshots' => $snapshots]]]];
    $content = ['title' => 'PLD equipment', 'slug' => 'pld-equipment', 'description' => 'Gear recommendations', 'body' => $body, 'tags' => [], 'activity_type_ids' => [], 'access_level' => 'everyone'];
    $id = $this->actingAs($this->group->owner)->postJson(route('groups.dashboard.resources.store', $this->group), ['content' => $content])->assertCreated()->json('data.id');
    $resource = GroupResource::findOrFail($id);
    $url = fn ($operation) => route('groups.dashboard.resources.update', ['group' => $this->group, 'resource' => $resource, 'operation' => $operation]);
    $lease = $this->postJson($url('acquire'), ['version' => $resource->version])->assertOk()->json('data');
    $saved = $this->postJson($url('save'), ['version' => $lease['version'], 'editing_token' => $lease['editing_token'], 'content' => $content, 'summary' => 'Added gearsets'])->assertOk()->json('data');
    $this->postJson($url('publish'), ['version' => $saved['version'], 'editing_token' => $lease['editing_token']])->assertOk();
    $count = count(Http::recorded());
    $this->getJson(route('public-resources.show', ['group' => $this->group, 'slug' => $resource->uuid]))->assertOk()->assertJsonPath('data.body', $body);
    $html = app(RichTextDocument::class)->html($body, resourceBlocks: true);
    expect($html)->toContain('2.50', 'XIVGear');
    $preview = app(ReportContentPreview::class)->build(['content' => ['body' => $body]]);
    expect($preview['html'])->toContain('2.50');
    Http::assertSentCount($count);
})->with(['expanded', 'compact']);

it('validates gear blocks and refuses unsafe snapshot URLs and invalid display modes', function () {
    $snapshot = app(XivGearImportService::class)->import(XIVGEAR_PLD_URL, [0])['snapshots'][0];
    $documents = app(RichTextDocument::class);
    $node = ['type' => 'xivGear', 'attrs' => ['display' => 'expanded', 'snapshots' => [$snapshot]]];
    $wrap = fn ($node) => ['type' => 'doc', 'content' => [$node]];
    expect(fn () => $documents->validate($wrap($node)))->toThrow(ValidationException::class);
    foreach ([['display', 'responsive'], ['snapshots', []], ['snapshots', [array_replace($snapshot, ['sourceUrl' => 'javascript:alert(1)'])]], ['snapshots', [array_replace_recursive($snapshot, ['items' => [0 => ['icon' => '../../secret']]])]]] as [$key, $value]) {
        $bad = $node;
        $bad['attrs'][$key] = $value;
        expect(fn () => $documents->validate($wrap($bad), resourceBlocks: true))->toThrow(ValidationException::class);
    }
});

it('serves only existing cached icons on both resource hosts without network calls', function () {
    Storage::disk('local')->put('xivgear-icons/123.png', "\x89PNG\r\n\x1a\nfixture");
    foreach ([parse_url(config('app.url'), PHP_URL_HOST), config('group_resources.public_host')] as $host) {
        $response = $this->get('http://'.$host.'/gearset-icons/123.png')->assertOk()->assertHeader('Content-Type', 'image/png');
        expect($response->headers->get('Cache-Control'))->toContain('immutable', 'max-age=31536000');
        $this->get('http://'.$host.'/gearset-icons/124.png')->assertNotFound();
    }
    Http::assertNothingSent();
});

it('automatically imports a single set and keeps food optional', function () {
    $sheet = json_decode(file_get_contents(base_path('tests/Fixtures/xivgear-pld.json')), true);
    $sheet['sets'] = [$sheet['sets'][0]];
    unset($sheet['sets'][0]['food']);
    Cache::put('xivgear:sheet:v1:'.hash('sha256', XIVGEAR_PLD_URL), $sheet + ['importedAt' => now()->toIso8601String()]);
    $result = app(XivGearImportService::class)->import(XIVGEAR_PLD_URL);
    expect($result['snapshots'])->toHaveCount(1);
    expect($result['snapshots'][0]['food'])->toBeNull();
    expect(XivGearSnapshot::valid($result['snapshots'][0]))->toBeTrue();
});

it('escapes embedded text in server-rendered fallbacks', function () {
    $snapshot = app(XivGearImportService::class)->import(XIVGEAR_PLD_URL, [0])['snapshots'][0];
    $snapshot['name'] = '<script>alert(1)</script>';
    $snapshot['items'][0]['names']['en'] = '<img src=x onerror=alert(1)>';
    $document = ['type' => 'doc', 'content' => [['type' => 'xivGear', 'attrs' => ['display' => 'expanded', 'snapshots' => [$snapshot]]]]];
    expect(app(RichTextDocument::class)->html($document, resourceBlocks: true))->toContain('&lt;script&gt;', '&lt;img')->not->toContain('<script>', '<img src=x');
});

it('resolves import messages in every supported locale', function (string $locale) {
    app()->setLocale($locale);
    expect(__('xivgear.import_failed'))->not->toBe('xivgear.import_failed');
    expect(__('xivgear.slots.OffHand'))->not->toBe('xivgear.slots.OffHand');
})->with(['en', 'de', 'fr', 'ja']);
