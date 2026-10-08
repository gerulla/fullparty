<?php

use App\Jobs\SendDiscordAdminReportJob;
use App\Models\IntegrationClient;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Services\FFLogs\FFLogsClient;
use App\Services\Integrations\IntegrationAdminNotificationService;
use App\Services\Integrations\IntegrationHealthcheckService;
use App\Services\Integrations\IntegrationWebhookDispatcher;
use App\Services\Notifications\AdminReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.admin_reports.enabled', true);
    config()->set('app.locale', 'en');
    config()->set('services.ff_logs.client_id', 'client-id');
    config()->set('services.ff_logs.client_secret', 'client-secret');
    config()->set('services.ff_logs.token_url', 'https://fflogs.test/oauth/token');
    config()->set('services.ff_logs.graphql_url', 'https://fflogs.test/graphql');
    Cache::flush();
    Queue::fake();
    Http::preventStrayRequests();
});

it('queues one report for repeated incidents and permits a reminder after fifteen minutes', function () {
    $reports = app(AdminReportService::class);
    $send = fn () => $reports->report('fflogs.auth', 'admin_reports.fflogs_auth_title', 'admin_reports.fflogs_auth_message');
    expect($send())->toBeTrue()->and($send())->toBeFalse();
    Queue::assertPushed(SendDiscordAdminReportJob::class, 1);
    Queue::assertPushed(SendDiscordAdminReportJob::class, fn ($job) => $job->title === 'FF Logs authentication failed' && $job->afterCommit);

    $this->travel(16)->minutes();
    expect($send())->toBeTrue();
    Queue::assertPushed(SendDiscordAdminReportJob::class, 2);
});

it('sends the exact admin report data using the signed integration transport', function () {
    $client = IntegrationClient::factory()->create(['webhook_signing_secret' => 'test-secret']);
    Http::fake([$client->outbound_events_url => Http::response(['ok' => true])]);

    $result = (new SendDiscordAdminReportJob('Run cleanup failed', 'Run 123 could not be cleaned up.', 'error'))
        ->handle(app(IntegrationWebhookDispatcher::class));

    expect($result['sent'])->toBe(1);
    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $timestamp = $request->header('X-FullParty-Timestamp')[0];

        return $request['event'] === 'discord.admin.report'
            && $request['data'] === ['title' => 'Run cleanup failed', 'message' => 'Run 123 could not be cleaned up.', 'severity' => 'error']
            && $request->header('X-FullParty-Signature')[0] === 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), 'test-secret');
    });
});

it('only sends admin reports to active bots with the capability enabled', function () {
    IntegrationClient::factory()->create(['status' => IntegrationClient::STATUS_PAUSED]);
    IntegrationClient::factory()->create(['status' => IntegrationClient::STATUS_REVOKED]);
    IntegrationClient::factory()->create(['allowed_events' => [IntegrationClient::EVENT_DISCORD_NOTIFICATION_DELIVERY]]);
    IntegrationClient::factory()->create(['outbound_events_url' => null]);

    $result = (new SendDiscordAdminReportJob('Test', 'Test'))->handle(app(IntegrationWebhookDispatcher::class));
    expect($result['attempted'])->toBe(0);
    Http::assertNothingSent();
});

it('retains an in-app alert without recursively reporting a failed admin report', function () {
    User::factory()->create(['is_admin' => true]);
    $client = IntegrationClient::factory()->create();
    Http::fake([$client->outbound_events_url => Http::response(['error' => 'Unavailable'], 503)]);

    $result = (new SendDiscordAdminReportJob('Test', 'Test'))->handle(app(IntegrationWebhookDispatcher::class));
    expect($result['failed'])->toBe(1)
        ->and(NotificationEvent::query()->where('type', 'integration.event_delivery_failed')->count())->toBe(1);
    Queue::assertNotPushed(SendDiscordAdminReportJob::class);
    Http::assertSentCount(2);
});

it('forwards integration failures with safe summaries and no raw error bodies', function () {
    $client = IntegrationClient::factory()->create(['name' => 'Test bot']);
    app(IntegrationAdminNotificationService::class)->notifyEventDeliveryFailed($client, 'discord.guild.run_completed', 'SECRET-TOKEN in a raw response');
    Queue::assertPushed(SendDiscordAdminReportJob::class, fn ($job) => str_contains($job->message, 'Test bot')
        && str_contains($job->message, 'discord.guild.run_completed')
        && ! str_contains($job->message, 'SECRET-TOKEN'));
});

it('reports unhealthy integrations through the shared service', function () {
    $client = IntegrationClient::factory()->create();
    Http::fake([$client->healthcheck_url => Http::response(['status' => 'unhealthy', 'secret' => 'PRIVATE'], 200)]);
    app(IntegrationHealthcheckService::class)->check($client);
    app(IntegrationHealthcheckService::class)->check($client);
    Queue::assertPushed(SendDiscordAdminReportJob::class, 1);
    Queue::assertPushed(SendDiscordAdminReportJob::class, fn ($job) => $job->severity === 'error' && ! str_contains($job->message, 'PRIVATE'));
});

it('does not report recovered authentication failures', function () {
    Cache::put('fflogs:client_credentials_token', 'old-token');
    Http::fake([
        'https://fflogs.test/oauth/token' => Http::response(['access_token' => 'new-token', 'expires_in' => 3600]),
        'https://fflogs.test/graphql' => Http::sequence()->pushStatus(401)->push(['data' => []]),
    ]);
    app(FFLogsClient::class)->query(['query' => '{ rateLimitData { limitPerHour } }']);
    Queue::assertNotPushed(SendDiscordAdminReportJob::class);
});

it('reports failed authentication recovery once without leaking tokens', function () {
    Cache::put('fflogs:client_credentials_token', 'old-secret-token');
    Http::fake([
        'https://fflogs.test/oauth/token' => Http::response(['access_token' => 'new-secret-token', 'expires_in' => 3600]),
        'https://fflogs.test/graphql' => Http::response(['error' => 'new-secret-token client-secret'], 401),
    ]);
    app(FFLogsClient::class)->query(['query' => '{ rateLimitData { limitPerHour } }']);
    Queue::assertPushed(SendDiscordAdminReportJob::class, 1);
    Queue::assertPushed(SendDiscordAdminReportJob::class, fn ($job) => $job->title === 'FF Logs authentication failed'
        && ! str_contains(serialize($job), 'secret'));
});

it('reports rate limits and upstream outages with safe status summaries', function (int $status) {
    Cache::put('fflogs:client_credentials_token', 'token');
    Http::fake(['https://fflogs.test/graphql' => Http::response(['error' => 'PRIVATE'], $status)]);
    $client = app(FFLogsClient::class);
    $client->query(['query' => '{ rateLimitData { limitPerHour } }']);
    $client->query(['query' => '{ rateLimitData { limitPerHour } }']);
    Queue::assertPushed(SendDiscordAdminReportJob::class, 1);
    Queue::assertPushed(SendDiscordAdminReportJob::class, fn ($job) => str_contains($job->message, (string) $status) && ! str_contains($job->message, 'PRIVATE'));
})->with([429, 503]);

it('tests immediate delivery with the artisan command without requiring a worker', function () {
    $client = IntegrationClient::factory()->create();
    Http::fake([$client->outbound_events_url => Http::response(['ok' => true])]);
    $this->artisan('admin:report-test')->expectsOutput(__('admin_reports.test_sent'))->assertSuccessful();
    Http::assertSent(fn (Request $request) => $request['event'] === 'discord.admin.report'
        && $request['data']['severity'] === 'info'
        && $request['data']['title'] === 'FullParty admin report test');
    Queue::assertNotPushed(SendDiscordAdminReportJob::class);
});

it('can test queued delivery separately', function () {
    $this->artisan('admin:report-test --queued')->expectsOutput(__('admin_reports.test_queued'))->assertSuccessful();
    Queue::assertPushed(SendDiscordAdminReportJob::class, 1);
    Http::assertNothingSent();
});

it('does not claim test delivery succeeded without a configured capable bot', function () {
    $this->artisan('admin:report-test')->expectsOutput(__('admin_reports.test_failed'))->assertFailed();
    Http::assertNothingSent();
});

it('returns a failing test command status when the bot rejects delivery', function () {
    $client = IntegrationClient::factory()->create();
    Http::fake([$client->outbound_events_url => Http::response(['error' => 'Unavailable'], 503)]);
    $this->artisan('admin:report-test')->expectsOutput(__('admin_reports.test_failed'))->assertFailed();
    Http::assertSentCount(2);
    Queue::assertNotPushed(SendDiscordAdminReportJob::class);
});

it('does not interrupt callers when queueing fails and releases the incident for retry', function () {
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable'));
    Bus::shouldReceive('dispatch')->once()->andReturn(null);
    $reports = app(AdminReportService::class);
    expect($reports->report('queue-test', 'admin_reports.test_title', 'admin_reports.test_message'))->toBeFalse()
        ->and($reports->report('queue-test', 'admin_reports.test_title', 'admin_reports.test_message'))->toBeTrue();
    Http::assertNothingSent();
});

it('reports token endpoint failures through the health check without exposing credentials', function () {
    Http::fake(['https://fflogs.test/oauth/token' => Http::response(['error' => 'PRIVATE'], 401)]);
    $this->artisan('fflogs:check-health')->assertFailed();
    $this->artisan('fflogs:check-health')->assertFailed();
    Http::assertSentCount(1);
    Queue::assertPushed(SendDiscordAdminReportJob::class, 1);
    Queue::assertPushed(SendDiscordAdminReportJob::class, fn ($job) => $job->title === 'FF Logs authentication failed'
        && ! str_contains($job->message, 'PRIVATE'));
});

it('keeps an isolated connection failure in the health check quiet', function () {
    Cache::put('fflogs:client_credentials_token', 'token');
    Http::fake(['https://fflogs.test/graphql' => Http::failedConnection()]);
    $this->artisan('fflogs:check-health')->assertFailed();
    Queue::assertNotPushed(SendDiscordAdminReportJob::class);
});

it('keeps admin reports disabled when configured off', function () {
    config()->set('services.admin_reports.enabled', false);
    $this->artisan('admin:report-test')->expectsOutput(__('admin_reports.test_disabled'))->assertFailed();
    expect(app(AdminReportService::class)->report('test', 'admin_reports.test_title', 'admin_reports.test_message'))->toBeFalse();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('renders report messages on the server for every supported locale', function (string $locale) {
    config()->set('app.locale', $locale);
    app(AdminReportService::class)->report('quota', 'admin_reports.fflogs_quota_title', 'admin_reports.fflogs_quota_message', ['spent' => 8500, 'limit' => 9000, 'seconds' => 600], 'warning');
    Queue::assertPushed(SendDiscordAdminReportJob::class, fn ($job) => ! str_contains($job->title, 'admin_reports.')
        && str_contains($job->message, '8500') && str_contains($job->message, '9000')
        && str_contains($job->message, '600') && ! str_contains($job->message, ':spent') && $job->severity === 'warning');
})->with(['en', 'de', 'fr', 'ja']);

it('detects a nearly exhausted api budget during the scheduled health check', function () {
    Cache::put('fflogs:client_credentials_token', 'token');
    Http::fake(['https://fflogs.test/graphql' => Http::response(['data' => ['rateLimitData' => [
        'limitPerHour' => 9000, 'pointsSpentThisHour' => 8100, 'pointsResetIn' => 600,
    ]]])]);
    $this->artisan('fflogs:check-health')->assertSuccessful();
    Queue::assertPushed(SendDiscordAdminReportJob::class, fn ($job) => $job->severity === 'warning' && str_contains($job->message, '8100'));
});

it('keeps a healthy low-usage check quiet', function () {
    Cache::put('fflogs:client_credentials_token', 'token');
    Http::fake(['https://fflogs.test/graphql' => Http::response(['data' => ['rateLimitData' => [
        'limitPerHour' => 9000, 'pointsSpentThisHour' => 1, 'pointsResetIn' => 3600,
    ]]])]);
    $this->artisan('fflogs:check-health')->assertSuccessful();
    Queue::assertNothingPushed();
});

it('reports unexpected health check responses', function () {
    Cache::put('fflogs:client_credentials_token', 'token');
    Http::fake(['https://fflogs.test/graphql' => Http::response(['errors' => [['message' => 'PRIVATE']]])]);
    $this->artisan('fflogs:check-health')->assertFailed();
    Queue::assertPushed(SendDiscordAdminReportJob::class, fn ($job) => $job->title === 'FF Logs health check failed' && ! str_contains($job->message, 'PRIVATE'));
});
