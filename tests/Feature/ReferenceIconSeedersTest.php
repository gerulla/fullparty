<?php

use App\Models\CharacterClass;
use App\Models\PhantomJob;
use App\Models\RaidPosition;
use App\Support\SeedData\ReferenceIconCatalog;
use Database\Seeders\CharacterClassSeeder;
use Database\Seeders\PhantomJobSeeder;
use Database\Seeders\RaidPositionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

it('seeds character classes with committed local icon urls', function () {
    $this->seed(CharacterClassSeeder::class);

    $bard = CharacterClass::query()->where('shorthand', 'BRD')->sole();
    $bardSeedData = collect(ReferenceIconCatalog::characterClasses())
        ->firstWhere('shorthand', 'BRD');

    expect($bard->role)->toBe('physical ranged dps')
        ->and($bard->icon_url)->toBe($bardSeedData['icon_url'])
        ->and($bard->flaticon_url)->toBe($bardSeedData['flaticon_url']);
});

it('seeds phantom jobs with committed local icon urls', function () {
    $this->seed(PhantomJobSeeder::class);

    $phantomBard = PhantomJob::query()->where('name', 'Phantom Bard')->sole();
    $phantomBardSeedData = collect(ReferenceIconCatalog::phantomJobs())
        ->firstWhere('name', 'Phantom Bard');

    expect($phantomBard->icon_url)->toBe($phantomBardSeedData['icon_url'])
        ->and($phantomBard->black_icon_url)->toBe($phantomBardSeedData['black_icon_url'])
        ->and($phantomBard->transparent_icon_url)->toBe($phantomBardSeedData['transparent_icon_url'])
        ->and($phantomBard->sprite_url)->toBe($phantomBardSeedData['sprite_url']);
});

it('seeds reusable raid positions', function () {
    $this->seed(RaidPositionSeeder::class);

    expect(RaidPosition::query()->count())->toBe(8)
        ->and(RaidPosition::query()->where('key', 'mt')->value('name'))->toBe('Main Tank')
        ->and(RaidPosition::query()->where('key', 'ot')->value('name'))->toBe('Off Tank')
        ->and(RaidPosition::query()->where('key', 'r2')->value('sort_order'))->toBe(80);
});

it('converts remote reference icon urls to tracked local webp files', function () {
    $sourceRoot = storage_path('app/testing/reference-icon-source');
    $targetRoot = storage_path('app/testing/reference-icon-target');
    $sourcePath = $sourceRoot.DIRECTORY_SEPARATOR.'character-classes'.DIRECTORY_SEPARATOR.'icons'.DIRECTORY_SEPARATOR.'96px-Bard_Icon_3.png';
    $targetPath = $targetRoot.DIRECTORY_SEPARATOR.'character-classes'.DIRECTORY_SEPARATOR.'icons'.DIRECTORY_SEPARATOR.'brd.webp';

    File::deleteDirectory($sourceRoot);
    File::deleteDirectory($targetRoot);
    File::ensureDirectoryExists(dirname($sourcePath));

    CharacterClass::query()->create([
        'name' => 'Bard',
        'shorthand' => 'BRD',
        'role' => 'physical ranged dps',
        'icon_url' => 'https://ffxiv.gamerescape.com/w/images/thumb/b/b3/Bard_Icon_3.png/96px-Bard_Icon_3.png',
        'flaticon_url' => null,
    ]);

    $canvas = imagecreatetruecolor(1, 1);
    ob_start();
    imagepng($canvas);
    $png = ob_get_clean();
    imagedestroy($canvas);

    file_put_contents($sourcePath, $png);

    $this->artisan('reference-icons:convert-webp', [
        '--only' => 'character-classes',
        '--source-root' => $sourceRoot,
        '--target-root' => $targetRoot,
    ])
        ->assertSuccessful();

    $bard = CharacterClass::query()->where('shorthand', 'BRD')->sole();

    expect($bard->icon_url)->toBe('/reference-icons/character-classes/icons/brd.webp')
        ->and(is_file($targetPath))->toBeTrue();

    File::deleteDirectory($sourceRoot);
    File::deleteDirectory($targetRoot);
});
