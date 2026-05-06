<?php

use App\Services\Lodestone\LodestoneCharacterSearchService;
use App\Services\Lodestone\LodestoneHttpClient;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Cache::flush();
});

it('parses lodestone character search results into reusable dto objects', function () {
    $html = <<<'HTML'
<!DOCTYPE html>
<html>
    <body>
        <div class="entry">
            <a href="/lodestone/character/41337960/" class="entry__link">
                <div class="entry__chara__face">
                    <img src="https://example.com/sara.png" alt="Sara Kiki">
                </div>
                <div class="entry__box entry__box--world">
                    <p class="entry__name">Sara Kiki</p>
                    <p class="entry__world">Twintania [Light]</p>
                </div>
            </a>
        </div>
        <div class="entry">
            <a href="/lodestone/character/10959109/" class="entry__link">
                <div class="entry__chara__face">
                    <img src="https://example.com/poppy.png" alt="Poppy Petalina">
                </div>
                <div class="entry__box entry__box--world">
                    <p class="entry__name">Poppy Petalina</p>
                    <p class="entry__world">Raiden [Light]</p>
                </div>
            </a>
        </div>
    </body>
</html>
HTML;

    $httpClient = Mockery::mock(LodestoneHttpClient::class);
    $httpClient
        ->shouldReceive('fetch')
        ->once()
        ->withArgs(fn (string $url) => str_contains($url, '/character/?q=Sara&worldname=Twintania'))
        ->andReturn($html);

    $service = new LodestoneCharacterSearchService($httpClient);
    $results = $service->search('Sara', 'Twintania');

    expect($results)->toHaveCount(2);
    expect($results[0]->lodestoneId)->toBe('41337960')
        ->and($results[0]->name)->toBe('Sara Kiki')
        ->and($results[0]->world)->toBe('Twintania')
        ->and($results[0]->dataCenter)->toBe('Light')
        ->and($results[0]->avatarUrl)->toBe('https://example.com/sara.png');
    expect($results[1]->lodestoneId)->toBe('10959109')
        ->and($results[1]->name)->toBe('Poppy Petalina');
});

it('caches lodestone character search results for a normalized name and world pair', function () {
    $html = <<<'HTML'
<!DOCTYPE html>
<html>
    <body>
        <div class="entry">
            <a href="/lodestone/character/41337960/" class="entry__link">
                <div class="entry__box entry__box--world">
                    <p class="entry__name">Sara Kiki</p>
                    <p class="entry__world">Twintania [Light]</p>
                </div>
            </a>
        </div>
        <div class="entry">
            <a href="/lodestone/character/10959109/" class="entry__link">
                <div class="entry__box entry__box--world">
                    <p class="entry__name">Poppy Petalina</p>
                    <p class="entry__world">Raiden [Light]</p>
                </div>
            </a>
        </div>
    </body>
</html>
HTML;

    $httpClient = Mockery::mock(LodestoneHttpClient::class);
    $httpClient
        ->shouldReceive('fetch')
        ->once()
        ->withArgs(fn (string $url) => str_contains($url, '/character/?q=Sara&worldname=Twintania'))
        ->andReturn($html);

    $service = new LodestoneCharacterSearchService($httpClient);

    $firstResults = $service->search('Sara', 'Twintania', 1);
    $secondResults = $service->search('  sara  ', 'twintania', 20);

    expect($firstResults)->toHaveCount(1)
        ->and($secondResults)->toHaveCount(2)
        ->and($secondResults[0]->lodestoneId)->toBe('41337960')
        ->and($secondResults[1]->lodestoneId)->toBe('10959109');
});
