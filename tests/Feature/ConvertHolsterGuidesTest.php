<?php

use App\Models\BozjaHolster;
use App\Models\Group;
use App\Services\RichText\MarkdownGuideConverter;
use App\Services\RichText\RichTextDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->group = Group::factory()->create();
    $this->holster = BozjaHolster::create(['group_id' => $this->group->id, 'name' => ['en' => 'Progression'], 'guide' => "# Ready\n\n- [x] Gear\n- [ ] Food\n\n![Map](https://example.com/map.png)\n\n| Party | Side |\n| --- | :---: |\n| A | West |"]);
});

it('defaults to a read-only conversion check', function () {
    $original = $this->holster->getRawOriginal('guide');
    $this->artisan('holsters:convert-guides')->assertSuccessful();
    expect($this->holster->fresh()->guide)->toBe($original)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('backs up original guides and converts once without changing timestamps', function () {
    $original = $this->holster->getRawOriginal('guide');
    $updated = $this->holster->updated_at;
    $this->artisan('holsters:convert-guides --apply')->assertSuccessful();
    $converted = $this->holster->fresh();
    expect($converted->guide_format)->toBe('tiptap')->and($converted->guide['type'])->toBe('doc')
        ->and($converted->updated_at->equalTo($updated))->toBeTrue();
    $files = Storage::disk('local')->allFiles('backups/holster-guides');
    expect($files)->toHaveCount(1);
    $backup = json_decode(Storage::disk('local')->get($files[0]), true);
    expect($backup['guides'][0])->toBe(['id' => $converted->id, 'guide' => $original]);
    $this->artisan('holsters:convert-guides --apply')->assertSuccessful();
    expect(Storage::disk('local')->allFiles('backups/holster-guides'))->toBe($files);
});

it('leaves every guide untouched if any conversion fails', function () {
    $original = $this->holster->getRawOriginal('guide');
    BozjaHolster::create(['group_id' => $this->group->id, 'name' => ['en' => 'Unsupported'], 'guide' => '[relative](guide.md)']);
    $this->artisan('holsters:convert-guides --apply')->assertFailed();
    expect($this->holster->fresh()->guide)->toBe($original)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('does not convert if the backup cannot be written', function () {
    Storage::shouldReceive('disk')->with('local')->once()->andReturn(new class
    {
        public function put(): bool
        {
            return false;
        }
    });
    $this->artisan('holsters:convert-guides --apply')->assertFailed();
    expect($this->holster->fresh()->guide_format)->toBe('markdown');
});

it('keeps legacy guides readable and protects them from accidental overwrite', function () {
    $url = route('groups.dashboard.content.delubrum-reginae-savage.holsters.update', ['group' => $this->group, 'bozjaHolster' => $this->holster]);
    $payload = ['name' => ['en' => 'Renamed'], 'role' => 'tank', 'type' => 'prepop', 'items' => []];
    $this->actingAs($this->group->owner)->putJson($url, $payload)->assertOk()
        ->assertJsonPath('data.guide_needs_conversion', true)->assertJsonPath('data.guide', null);
    $this->putJson($url, $payload + ['guide' => RichTextDocument::empty()])->assertUnprocessable()->assertJsonValidationErrors('guide');
    $this->artisan('holsters:convert-guides --apply')->assertSuccessful();
    $guide = app(MarkdownGuideConverter::class)->convert("# Edited\n\nKeep **these** spaces.\n\n```\n  space  \n```\n");
    $this->putJson($url, $payload + ['guide' => $guide])->assertOk()->assertJsonPath('data.guide', $guide)
        ->assertJsonPath('data.guide_needs_conversion', false);
});

it('rejects unsafe documents and resource-library images in holster guides', function () {
    $this->actingAs($this->group->owner);
    $url = route('groups.dashboard.content.delubrum-reginae-savage.holsters.store', $this->group);
    $payload = ['name' => ['en' => 'Unsafe'], 'role' => 'tank', 'type' => 'prepop', 'items' => []];
    foreach (['javascript:alert(1)', '/resource-assets/'.str()->uuid(), 'https://fullparty.gg/resource-assets/'.str()->uuid()] as $src) {
        $this->postJson($url, $payload + ['guide' => ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => $src]]]]])->assertUnprocessable()->assertJsonValidationErrors('guide');
    }
    $this->postJson($url, $payload + ['guide' => '# Old Markdown'])->assertUnprocessable()->assertJsonValidationErrors('guide');
});
