<?php

use App\Exceptions\LodestoneFetchException;
use App\Models\Character;
use App\Models\User;
use App\Services\Characters\CharacterProfileRefreshService;
use App\Services\FFLogs\ForkedTowerBloodProgressFetcher;
use App\Services\FFLogs\ForkedTowerMagicProgressFetcher;
use App\Services\Lodestone\ForkedTowerBloodAchievementProgressFetcher;
use App\Services\Lodestone\ForkedTowerMagicAchievementProgressFetcher;
use App\Services\Lodestone\LodestoneHttpClient;
use App\Services\Lodestone\Parsers\LodestoneProfileParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()->instance('env', 'local');
    Queue::fake();
    Http::preventStrayRequests();
    Cache::flush();
    config(['lodestone.parse_class_jobs' => true, 'lodestone.rate_limit_delay' => 0, 'lodestone.cache_ttl' => 0]);
    $this->user = User::factory()->create();
    $this->actingAs($this->user)
        ->withSession(['_token' => 'import-progress-test'])
        ->withHeaders(['X-CSRF-TOKEN' => 'import-progress-test', 'X-Character-Progress' => 'stream']);
});

function characterImportEvents($response): array
{
    return array_map(
        fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        array_filter(explode("\n", $response->streamedContent())),
    );
}

it('streams real refresh stages and preserves optional achievement fallback', function (string $method, string $blood, string $magic) {
    $character = Character::factory()->for($this->user)->create([
        'lodestone_id' => '12345678', 'add_method' => $method,
        'claimed_at' => $method === 'xivauth' ? now() : null,
        'verified_at' => $method === 'xivauth' ? now() : null,
    ]);
    $character->occultProgress()->create(['index_kills' => 3]);
    $this->mock(LodestoneHttpClient::class)->shouldReceive('fetch')->twice()->andReturn('<html><body>Fixture</body></html>');
    $this->mock(LodestoneProfileParser::class)->shouldReceive('parse')->once()->andReturn([
        'name' => 'Fixture Adventurer', 'world' => 'Lich', 'data_center' => 'Light',
        'avatar_url' => 'https://example.test/avatar.jpg',
    ]);
    foreach ([
        [ForkedTowerBloodProgressFetcher::class, $blood, 'demon_tablet'],
        [ForkedTowerMagicProgressFetcher::class, $magic, 'index'],
    ] as [$fetcher, $fflogs, $boss]) {
        $expectation = $this->mock($fetcher)->shouldReceive('fetchForCharacter')->once();
        if ($fflogs === 'failed') {
            $expectation->andThrow(new RuntimeException('Private upstream diagnostic'));
        } else {
            $expectation->andReturn([
                'clears' => 0,
                'bosses' => [['key' => $boss, 'kills' => $fflogs === 'found' ? 2 : 0, 'progress' => 0]],
            ]);
        }
    }
    foreach ([
        [ForkedTowerBloodAchievementProgressFetcher::class, $blood],
        [ForkedTowerMagicAchievementProgressFetcher::class, $magic],
    ] as [$fetcher, $fflogs]) {
        $mock = $this->mock($fetcher);
        if ($fflogs === 'found') {
            $mock->shouldNotReceive('fetchForCharacter');
        } else {
            $mock->shouldReceive('fetchForCharacter')->once()->andReturn(['clears' => 0, 'bosses' => []]);
        }
    }

    $response = $this->postJson(route('characters.onboarding.refresh', $character))
        ->assertOk()->assertHeader('Content-Type', 'application/x-ndjson')
        ->assertHeader('X-Accel-Buffering', 'no')->assertHeader('Cache-Control', 'no-store, private');
    $events = characterImportEvents($response);
    $progress = collect($events)->where('type', 'progress');
    $expected = [
        'profile', 'classes', 'phantom_jobs', 'fflogs', 'fflogs',
        ...array_fill(0, (int) ($blood !== 'found') + (int) ($magic !== 'found'), 'achievements'),
        'saving',
    ];
    expect($progress->pluck('stage')->all())->toBe($expected)
        ->and($progress->pluck('percent')->all())->toBe($progress->pluck('percent')->sort()->values()->all())
        ->and($progress->max('percent'))->toBeLessThan(100)
        ->and(end($events))->toMatchArray([
            'type' => 'complete', 'partial' => $blood === 'failed' || $magic === 'failed',
        ])
        ->and(end($events)['character'])->toMatchArray([
            'id' => $character->id, 'name' => 'Fixture Adventurer',
            'status' => $method === 'xivauth' ? 'claimed' : 'unverified',
        ])
        ->and($response->streamedContent())->not->toContain('Private upstream diagnostic')
        ->and($character->fresh()->lodestone_refreshed_at)->not->toBeNull()
        ->and($character->fresh()->occultProgress->demon_tablet_kills)->toBe($blood === 'found' ? 2 : 0)
        ->and($character->fresh()->occultProgress->index_kills)->toBe(match ($magic) {
            'found' => 2,
            'failed' => 3,
            default => 0,
        });
    Http::assertNothingSent();
})->with(['manual', 'xivauth'])->with([
    'both empty' => ['empty', 'empty'],
    'both found' => ['found', 'found'],
    'both failed' => ['failed', 'failed'],
    'blood only' => ['found', 'empty'],
    'magic only' => ['empty', 'found'],
    'magic unavailable' => ['found', 'failed'],
]);

it('sanitizes streamed failures and releases the refresh lock for retry', function (bool $unexpected) {
    $character = Character::factory()->for($this->user)->unverified()->create();
    $this->mock(CharacterProfileRefreshService::class)->shouldReceive('refresh')->once()->andThrow(
        $unexpected ? new RuntimeException('Private upstream diagnostic') : LodestoneFetchException::timeout('private-url'),
    );
    $events = characterImportEvents($this->postJson(route('characters.onboarding.refresh', $character))->assertOk());
    expect($events)->toBe([['type' => 'error', 'code' => $unexpected ? 'generic' : 'lodestone_unavailable']])
        ->and($character->fresh()->user_id)->toBe($this->user->id);

    $this->mock(CharacterProfileRefreshService::class)->shouldReceive('refresh')->once()->andReturn(['fflogs_error' => null]);
    $retry = characterImportEvents($this->postJson(route('characters.onboarding.refresh', $character))->assertOk());
    expect($retry[0]['type'])->toBe('complete')->and(Character::count())->toBe(1);
})->with([false, true]);

it('reports a busy refresh without running a second refresh', function () {
    $character = Character::factory()->for($this->user)->create();
    $lock = Cache::lock('character-onboarding-refresh:'.$character->id, 120);
    $lock->get();
    $this->mock(CharacterProfileRefreshService::class)->shouldNotReceive('refresh');
    try {
        $events = characterImportEvents($this->postJson(route('characters.onboarding.refresh', $character))->assertOk());
        expect($events)->toBe([['type' => 'error', 'code' => 'busy']]);
    } finally {
        $lock->release();
    }
});

it('authorizes streamed refresh before starting a response', function () {
    $character = Character::factory()->create();
    $this->mock(CharacterProfileRefreshService::class)->shouldNotReceive('refresh');
    $this->postJson(route('characters.onboarding.refresh', $character))->assertNotFound();
    app()->instance('env', 'production');
    $owned = Character::factory()->for($this->user)->create();
    $this->postJson(route('characters.onboarding.refresh', $owned))->assertNotFound();
    app()->instance('env', 'local');
    auth()->logout();
    $this->postJson(route('characters.onboarding.refresh', $owned))->assertUnauthorized();
});
