<?php

use App\Jobs\SendDiscordAdminReportJob;
use App\Models\IntegrationClient;
use App\Models\IntegrationClientHealthCheck;
use App\Services\FFLogs\FFLogsClient;
use App\Services\Integrations\IntegrationHealthcheckService;
use App\Services\Integrations\IntegrationWebhookDispatcher;
use App\Services\Notifications\AdminReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.admin_reports.enabled', true);
    config()->set('app.locale', 'en');
    config()->set('app.url', 'https://fullparty.test');
    config()->set('services.ff_logs.client_id', 'client-id');
    config()->set('services.ff_logs.client_secret', 'client-secret');
    config()->set('services.ff_logs.token_url', 'https://fflogs.test/oauth/token');
    config()->set('services.ff_logs.graphql_url', 'https://fflogs.test/graphql');
    Cache::flush();
    Queue::fake();
    Http::preventStrayRequests();
});

it('includes component metrics and diagnostic metadata in localized bot health alerts', function (string $locale) {
    config()->set('app.locale', $locale);
    $client = IntegrationClient::factory()->create([
        'name' => 'Community bot',
        'last_healthcheck_ok_at' => '2026-10-08 17:00:00',
    ]);
    Http::fake([$client->healthcheck_url => Http::response([
        'status' => 'degraded',
        'secret' => 'PRIVATE',
        'checks' => [
            'discord' => ['status' => 'healthy', 'ready' => true, 'ping_ms' => 42],
            'recent_failures' => ['status' => 'degraded', 'warnCount' => 4, 'errorCount' => 2, 'ignoredCount' => 3, 'lastFailureAt' => '2026-10-08T18:00:00Z'],
            'queue' => ['status' => 'unhealthy', 'queued' => 12, 'processing' => 3, 'failedLastWindow' => 6, 'stuckProcessing' => 2, 'message' => 'PRIVATE'],
        ],
    ], 200)]);

    app(IntegrationHealthcheckService::class)->check($client);
    app(IntegrationHealthcheckService::class)->check($client);

    Queue::assertPushed(SendDiscordAdminReportJob::class, 1);
    Queue::assertPushed(SendDiscordAdminReportJob::class, function ($job) use ($client, $locale) {
        expect($job->message)->toContain('Community bot', (string) $client->id, 'Discord', 'Recent Failures', 'Queue', '42', '12', '2026-10-08T18:00:00Z', '2026-10-08T17:00:00', '200', 'https://fullparty.test/')
            ->toContain(__('admin_reports.health_metrics.stuckProcessing', [], $locale).': 2')
            ->toContain(__('admin_reports.details.duration_ms', [], $locale))
            ->not->toContain('PRIVATE', 'admin_reports.', ':client', ':status', ':id');
        expect(strpos($job->message, 'Queue'))->toBeLessThan(strpos($job->message, 'Discord'));

        return $job->severity === 'warning';
    });
})->with(['en', 'de', 'fr', 'ja']);

it('retains non-successful HTTP health response details instead of discarding their body', function () {
    $client = IntegrationClient::factory()->create();
    Http::fake([$client->healthcheck_url => Http::response([
        'status' => 'unhealthy',
        'checks' => ['discord' => ['ready' => false, 'status' => 'unhealthy'], 'queue' => ['status' => 'degraded', 'queued' => 18]],
    ], 503, ['Retry-After' => '60', 'CF-Ray' => 'abc123-LHR'])]);

    app(IntegrationHealthcheckService::class)->check($client);

    expect(IntegrationClientHealthCheck::query()->sole()->response_status)->toBe(503);
    Queue::assertPushed(SendDiscordAdminReportJob::class, function ($job) {
        expect($job->message)->toContain('HTTP status: 503', 'not ready', 'queued: 18', 'Retry after (seconds): 60', 'abc123-LHR', 'Delivery ID:');

        return $job->severity === 'error';
    });
});

it('includes transport failure codes without leaking exception URLs or request credentials', function () {
    $client = IntegrationClient::factory()->create();
    Http::fake([$client->healthcheck_url => Http::failedConnection('cURL error 28: timeout for https://user:PRIVATE@bot.test/?token=PRIVATE')]);
    app(IntegrationHealthcheckService::class)->check($client);

    Queue::assertPushed(SendDiscordAdminReportJob::class, function ($job) {
        expect($job->message)->toContain('cURL code: 28', 'timed out', 'ConnectionException')->not->toContain('PRIVATE', '?token=', 'user:');

        return true;
    });
    expect($client->fresh()->last_healthcheck_error)->not->toContain('PRIVATE');
});

it('ignores malformed or sensitive bot health fields', function () {
    $client = IntegrationClient::factory()->create();
    Http::fake([$client->healthcheck_url => Http::response([
        'status' => 'degraded',
        'checks' => [
            'queue' => ['status' => ['PRIVATE'], 'queued' => ['PRIVATE'], 'processing' => 'PRIVATE', 'errorCount' => 7, 'lastFailureAt' => 'PRIVATE', 'token' => 'PRIVATE'],
            'not a component PRIVATE' => ['status' => 'degraded'],
        ],
    ], 200)]);
    app(IntegrationHealthcheckService::class)->check($client);

    Queue::assertPushed(SendDiscordAdminReportJob::class, function ($job) {
        expect($job->message)->toContain('error: 7')->not->toContain('PRIVATE', 'Array');

        return true;
    });
});

it('keeps healthy bot checks quiet', function () {
    $client = IntegrationClient::factory()->create();
    Http::fake([$client->healthcheck_url => Http::response(['status' => 'healthy', 'checks' => ['discord' => ['ready' => true]]])]);
    app(IntegrationHealthcheckService::class)->check($client);
    Queue::assertNothingPushed();
});

it('includes failed webhook status and delivery reference without temporary participant data', function () {
    $client = IntegrationClient::factory()->create();
    Http::fake([$client->outbound_events_url => Http::response(['error' => 'PRIVATE discord-user-123'], 503)]);
    app(IntegrationWebhookDispatcher::class)->dispatchDiscordBotEvent(IntegrationClient::EVENT_DISCORD_GUILD_RUN_PARTICIPANT_SYNC, ['discord_user_id' => 'discord-user-123']);

    Queue::assertPushed(SendDiscordAdminReportJob::class, function ($job) {
        expect($job->message)->toContain('HTTP status: 503', 'Delivery ID:', IntegrationClient::EVENT_DISCORD_GUILD_RUN_PARTICIPANT_SYNC)
            ->not->toContain('PRIVATE', 'discord-user-123');

        return true;
    });
});

it('identifies token endpoint failures and unsuccessful token recovery separately', function () {
    Http::fake(['https://fflogs.test/oauth/token' => Http::response(['error' => 'PRIVATE'], 401)]);
    $this->artisan('fflogs:check-health')->assertFailed();
    Queue::assertPushed(SendDiscordAdminReportJob::class, function ($job) {
        expect($job->message)->toContain('OAuth token request', 'HTTP status: 401')->not->toContain('PRIVATE');

        return true;
    });
});

it('explains a missing token in an otherwise successful OAuth response', function () {
    Http::fake(['https://fflogs.test/oauth/token' => Http::response(['unexpected' => 'PRIVATE'])]);
    $this->artisan('fflogs:check-health')->assertFailed();
    Queue::assertPushed(SendDiscordAdminReportJob::class, fn ($job) => str_contains($job->message, 'did not contain an access token'));
});

it('includes upstream rate-limit retry timing and request references', function () {
    Cache::put('fflogs:client_credentials_token', 'PRIVATE');
    Http::fake(['https://fflogs.test/graphql' => Http::response(['error' => 'PRIVATE'], 429, ['Retry-After' => '120', 'X-Request-ID' => 'request-42'])]);
    app(FFLogsClient::class)->query(['query' => '{ rateLimitData { limitPerHour } }']);

    Queue::assertPushed(SendDiscordAdminReportJob::class, function ($job) {
        expect($job->message)->toContain('HTTP status: 429', 'Retry after (seconds): 120', 'request-42', 'GraphQL API request')->not->toContain('PRIVATE');

        return true;
    });
});

it('explains invalid health data and preserves available budget fields', function () {
    Cache::put('fflogs:client_credentials_token', 'token');
    Http::fake(['https://fflogs.test/graphql' => Http::response(['data' => ['rateLimitData' => ['limitPerHour' => 9000, 'pointsSpentThisHour' => 75]]])]);
    $this->artisan('fflogs:check-health')->expectsOutput('FF Logs health check: '.__('admin_reports.reasons.invalid_budget'))->assertFailed();
    Queue::assertPushed(SendDiscordAdminReportJob::class, function ($job) {
        expect($job->message)->toContain('missing or invalid', 'Hourly point limit: 9000', 'Points used: 75', 'HTTP status: 200');

        return true;
    });
});

it('distinguishes GraphQL errors from an HTTP transport failure', function () {
    Cache::put('fflogs:client_credentials_token', 'token');
    Http::fake(['https://fflogs.test/graphql' => Http::response(['errors' => [['message' => 'PRIVATE'], ['message' => 'PRIVATE']]])]);
    $this->artisan('fflogs:check-health')->assertFailed();
    Queue::assertPushed(SendDiscordAdminReportJob::class, function ($job) {
        expect($job->message)->toContain('GraphQL returned errors', 'GraphQL error count: 2', 'HTTP status: 200')->not->toContain('PRIVATE');

        return true;
    });
});

it('bounds diagnostic alerts to the existing bot message budget', function () {
    app(AdminReportService::class)->report('long-check', 'admin_reports.test_title', 'admin_reports.test_message', details: [
        'checks' => str_repeat('queue: unhealthy; ', 500),
        'reason' => str_repeat('failure ', 500),
    ]);
    Queue::assertPushed(SendDiscordAdminReportJob::class, fn ($job) => mb_strlen($job->message) <= 1800);
});
