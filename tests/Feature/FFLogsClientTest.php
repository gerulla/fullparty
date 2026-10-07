<?php

use App\Models\Activity;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Services\FFLogs\ActivityReportProgressFetcher;
use App\Services\FFLogs\CharacterZoneProgressFetcher;
use App\Services\FFLogs\FFLogsClient;
use App\Services\FFLogs\FFLogsPlaygroundClient;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.ff_logs.client_id', 'client-id');
    config()->set('services.ff_logs.client_secret', 'client-secret');
    config()->set('services.ff_logs.token_url', 'https://fflogs.test/oauth/token');
    config()->set('services.ff_logs.graphql_url', 'https://fflogs.test/graphql');
    Cache::flush();
    Http::preventStrayRequests();
});

it('recovers from a rejected cached token in every ff logs consumer', function (string $consumer) {
    Cache::put('fflogs:client_credentials_token', 'rejected-token');
    $requests = [];
    Http::fake([
        'https://fflogs.test/oauth/token' => Http::response(['access_token' => 'fresh-token', 'expires_in' => 3600]),
        'https://fflogs.test/graphql' => function (Request $request) use (&$requests) {
            $requests[] = $request->data();
            if ($request->hasHeader('Authorization', 'Bearer rejected-token')) {
                return Http::response(['error' => 'Unauthenticated.'], 401);
            }

            expect($request->hasHeader('Authorization', 'Bearer fresh-token'))->toBeTrue();

            return Http::response(['data' => [
                'rateLimitData' => ['limitPerHour' => 9000],
                'characterData' => ['character' => ['zoneRankings' => ['rankings' => []]]],
                'reportData' => ['report' => ['title' => 'Example run', 'fights' => []]],
            ]]);
        },
    ]);

    if ($consumer === 'character') {
        $character = new Character(['name' => 'Example Player', 'world' => 'Lich', 'datacenter' => 'Light']);
        $result = app(CharacterZoneProgressFetcher::class)->fetchRawZoneRankingsForCharacter($character, 48, difficulty: 101);
        expect($result)->toBe(['rankings' => []]);
        // Successful recovery must populate the usual progress cache.
        app(CharacterZoneProgressFetcher::class)->fetchRawZoneRankingsForCharacter($character, 48, difficulty: 101);
    } elseif ($consumer === 'report') {
        $activity = (new Activity)->setRelation('activityTypeVersion', new ActivityTypeVersion);
        $result = app(ActivityReportProgressFetcher::class)->preview($activity, 'abc123');
        expect($result['report_title'])->toBe('Example run');
    } else {
        $result = app(FFLogsPlaygroundClient::class)->execute(['query' => '{ rateLimitData { limitPerHour } }']);
        expect($result->json('data.rateLimitData.limitPerHour'))->toBe(9000);
    }

    expect($requests)->toHaveCount(2)
        ->and($requests[0])->toBe($requests[1])
        ->and(Cache::get('fflogs:client_credentials_token'))->toBe('fresh-token')
        ->and(Cache::has('fflogs:client_credentials_token:failed'))->toBeFalse();
    Http::assertSentCount(3);
})->with(['character', 'report', 'playground']);

it('reuses a token refreshed by another request while the old request was in flight', function () {
    Cache::put('fflogs:client_credentials_token', 'old-token');
    Http::fake(['https://fflogs.test/graphql' => function (Request $request) {
        if ($request->hasHeader('Authorization', 'Bearer old-token')) {
            Cache::put('fflogs:client_credentials_token', 'newer-token');

            return Http::response(['error' => 'Unauthenticated.'], 401);
        }

        expect($request->hasHeader('Authorization', 'Bearer newer-token'))->toBeTrue();

        return Http::response(['data' => []]);
    }]);

    expect(app(FFLogsClient::class)->query(['query' => '{ rateLimitData { limitPerHour } }'])->successful())->toBeTrue();
    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request) => $request->url() === 'https://fflogs.test/oauth/token');
});

it('stops after one retry and backs off when a fresh token is also rejected', function () {
    Cache::put('fflogs:client_credentials_token', 'old-token');
    Http::fake([
        'https://fflogs.test/oauth/token' => Http::sequence()
            ->push(['access_token' => 'still-rejected', 'expires_in' => 3600])
            ->push(['access_token' => 'working-token', 'expires_in' => 3600]),
        'https://fflogs.test/graphql' => Http::sequence()
            ->push(['error' => 'Unauthenticated.'], 401)
            ->push(['error' => 'Unauthenticated.'], 401)
            ->push(['data' => []]),
    ]);
    $payload = ['query' => '{ rateLimitData { limitPerHour } }'];

    expect(app(FFLogsClient::class)->query($payload)->status())->toBe(401)
        ->and(Cache::get('fflogs:client_credentials_token'))->toBeNull();
    expect(fn () => app(FFLogsPlaygroundClient::class)->execute($payload))
        ->toThrow(RuntimeException::class, __('errors.ff_logs_authentication_is_temporarily_unavailable'));
    Http::assertSentCount(3);

    $this->travel(121)->seconds();
    expect(app(FFLogsClient::class)->query($payload)->successful())->toBeTrue();
    Http::assertSentCount(5);
});

it('preserves a newer cached token when a late retry is rejected', function () {
    Cache::put('fflogs:client_credentials_token', 'old-token');
    Http::fake([
        'https://fflogs.test/oauth/token' => Http::response(['access_token' => 'refresh-token', 'expires_in' => 3600]),
        'https://fflogs.test/graphql' => function (Request $request) {
            if ($request->hasHeader('Authorization', 'Bearer refresh-token')) {
                Cache::put('fflogs:client_credentials_token', 'newest-token');
            }

            return Http::response(['error' => 'Unauthenticated.'], 401);
        },
    ]);

    expect(app(FFLogsClient::class)->query(['query' => '{ rateLimitData { limitPerHour } }'])->status())->toBe(401)
        ->and(Cache::get('fflogs:client_credentials_token'))->toBe('newest-token')
        ->and(Cache::has('fflogs:client_credentials_token:failed'))->toBeFalse();
    Http::assertSentCount(3);
});

it('backs off failed token refreshes without retrying invalid credentials', function () {
    Cache::put('fflogs:client_credentials_token', 'old-token');
    Http::fake([
        'https://fflogs.test/oauth/token' => Http::response(['error' => 'invalid_client'], 401),
        'https://fflogs.test/graphql' => Http::response(['error' => 'Unauthenticated.'], 401),
    ]);
    $payload = ['query' => '{ rateLimitData { limitPerHour } }'];

    expect(fn () => app(FFLogsClient::class)->query($payload))->toThrow(RequestException::class);
    expect(Cache::get('fflogs:client_credentials_token'))->toBeNull();
    expect(fn () => app(FFLogsClient::class)->query($payload))
        ->toThrow(RuntimeException::class, __('errors.ff_logs_authentication_is_temporarily_unavailable'));
    Http::assertSentCount(2);
});

it('does not refresh tokens or retry other upstream errors', function (int $status) {
    Cache::put('fflogs:client_credentials_token', 'valid-token');
    Http::fake(['https://fflogs.test/graphql' => Http::response(['error' => 'Request failed.'], $status)]);

    expect(app(FFLogsClient::class)->query(['query' => '{ rateLimitData { limitPerHour } }'])->status())->toBe($status)
        ->and(Cache::get('fflogs:client_credentials_token'))->toBe('valid-token')
        ->and(Cache::has('fflogs:client_credentials_token:failed'))->toBeFalse();
    Http::assertSentCount(1);
})->with([403, 429, 500]);

it('expires a cached token before its advertised expiry', function () {
    Http::fake([
        'https://fflogs.test/oauth/token' => Http::sequence()
            ->push(['access_token' => 'first-token', 'expires_in' => 3600])
            ->push(['access_token' => 'next-token', 'expires_in' => 3600]),
        'https://fflogs.test/graphql' => Http::response(['data' => []]),
    ]);
    $client = app(FFLogsClient::class);
    $payload = ['query' => '{ rateLimitData { limitPerHour } }'];
    $client->query($payload);
    $this->travel(3539)->seconds();
    $client->query($payload);
    Http::assertSentCount(3);
    $this->travel(2)->seconds();
    $client->query($payload);
    Http::assertSentCount(5);
    expect(Cache::get('fflogs:client_credentials_token'))->toBe('next-token');
});

it('serializes token requests across concurrent callers', function () {
    $duplicateBlocked = false;
    $payload = ['query' => '{ rateLimitData { limitPerHour } }'];
    Http::fake([
        'https://fflogs.test/oauth/token' => function () use ($payload, &$duplicateBlocked) {
            try {
                app(FFLogsClient::class)->query($payload);
            } catch (LockTimeoutException) {
                $duplicateBlocked = true;
            }

            return Http::response(['access_token' => 'shared-token', 'expires_in' => 3600]);
        },
        'https://fflogs.test/graphql' => Http::response(['data' => []]),
    ]);

    expect(app(FFLogsClient::class)->query($payload)->successful())->toBeTrue()
        ->and($duplicateBlocked)->toBeTrue();
    Http::assertSentCount(2);
});
