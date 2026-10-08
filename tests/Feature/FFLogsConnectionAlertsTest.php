<?php

use App\Jobs\SendDiscordAdminReportJob;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Services\FFLogs\CharacterZoneProgressFetcher;
use App\Services\FFLogs\FFLogsClient;
use App\Services\Groups\ApplicantQueue\ApplicantMilestoneResolver;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
    Cache::put('fflogs:client_credentials_token', 'token');
    Queue::fake();
    Http::preventStrayRequests();
});

function failedFflogsConnection(): void
{
    expect(fn () => app(FFLogsClient::class)->query(['query' => '{ rateLimitData { limitPerHour } }']))
        ->toThrow(ConnectionException::class);
}

it('does not turn a burst of failed user requests into an outage alert', function () {
    Http::fake(['https://fflogs.test/graphql' => Http::failedConnection('cURL error 28: Resolving timed out after 5001 milliseconds')]);
    for ($i = 0; $i < 12; $i++) {
        failedFflogsConnection();
    }
    Http::assertSentCount(12);
    Queue::assertNothingPushed();
});

it('alerts only after three spaced failures and distinguishes DNS timeouts in all locales', function (string $locale) {
    config()->set('app.locale', $locale);
    Http::fake(['https://fflogs.test/graphql' => Http::failedConnection('cURL error 28: Resolving timed out after 5001 milliseconds for https://PRIVATE/?token=PRIVATE')]);
    failedFflogsConnection();
    $this->travel(60)->seconds();
    failedFflogsConnection();
    Queue::assertNothingPushed();
    $this->travel(60)->seconds();
    failedFflogsConnection();

    Queue::assertPushed(SendDiscordAdminReportJob::class, 1);
    Queue::assertPushed(SendDiscordAdminReportJob::class, function ($job) use ($locale) {
        expect($job->message)->toContain('3', '120', __('admin_reports.reasons.dns_timeout', [], $locale), __('admin_reports.details.first_failed_at', [], $locale))
            ->not->toContain('PRIVATE', 'admin_reports.', ':count', ':seconds');

        return $job->severity === 'warning';
    });
    $this->travel(60)->seconds();
    failedFflogsConnection();
    Queue::assertPushed(SendDiscordAdminReportJob::class, 1);
})->with(['en', 'de', 'fr', 'ja']);

it('resets the connection failure streak when any HTTP response arrives', function (int $status) {
    Http::fake(['https://fflogs.test/graphql' => Http::sequence()
        ->pushFailedConnection()->pushFailedConnection()->push([], $status)
        ->pushFailedConnection()->pushFailedConnection()]);
    failedFflogsConnection();
    $this->travel(60)->seconds();
    failedFflogsConnection();
    expect(app(FFLogsClient::class)->query(['query' => '{ rateLimitData { limitPerHour } }'])->status())->toBe($status);
    $this->travel(60)->seconds();
    failedFflogsConnection();
    $this->travel(60)->seconds();
    failedFflogsConnection();
    Queue::assertNothingPushed();
})->with([200, 403]);

it('expires old failures rather than adding unrelated incidents together', function () {
    Http::fake(['https://fflogs.test/graphql' => Http::failedConnection()]);
    failedFflogsConnection();
    $this->travel(60)->seconds();
    failedFflogsConnection();
    $this->travel(16)->minutes();
    failedFflogsConnection();
    Queue::assertNothingPushed();
});

it('resets the streak during a successful request even when the monitoring lock is busy', function () {
    Http::fake(['https://fflogs.test/graphql' => Http::sequence()->pushFailedConnection()->pushFailedConnection()->push([])->pushFailedConnection()]);
    failedFflogsConnection();
    $this->travel(60)->seconds();
    failedFflogsConnection();
    $lock = Cache::lock('fflogs:connection-observations:api:lock', 5);
    $lock->get();
    try {
        expect(app(FFLogsClient::class)->query(['query' => '{ rateLimitData { limitPerHour } }'])->successful())->toBeTrue();
    } finally {
        $lock->release();
    }
    $this->travel(60)->seconds();
    failedFflogsConnection();
    Queue::assertNothingPushed();
});

it('confirms persistent failures through scheduled checks without making extra diagnostic requests', function () {
    Http::fake(['https://fflogs.test/graphql' => Http::failedConnection()]);
    for ($i = 0; $i < 3; $i++) {
        $this->artisan('fflogs:check-health')->assertFailed();
        $this->travel(5)->minutes();
    }
    Http::assertSentCount(3);
    Queue::assertPushed(SendDiscordAdminReportJob::class, 1);
});

it('does not count cooldown responses as new remote failures', function () {
    Http::fake(['https://fflogs.test/graphql' => Http::failedConnection()]);
    $character = new Character(['name' => 'Example Player', 'world' => 'Lich', 'datacenter' => 'Light']);
    $fetcher = app(CharacterZoneProgressFetcher::class);
    expect(fn () => $fetcher->fetchEncounterProgressForCharacter($character, 48))->toThrow(ConnectionException::class);
    for ($i = 0; $i < 5; $i++) {
        expect(fn () => $fetcher->fetchEncounterProgressForCharacter($character, 48))->toThrow(RuntimeException::class);
    }
    Http::assertSentCount(1);
    Queue::assertNothingPushed();
});

it('classifies token endpoint timeouts as connection failures rather than rejected authentication', function () {
    Cache::forget('fflogs:client_credentials_token');
    Http::fake(['https://fflogs.test/oauth/token' => Http::failedConnection('cURL error 28: Resolving timed out after 5001 milliseconds')]);
    for ($i = 0; $i < 3; $i++) {
        $this->artisan('fflogs:check-health')->assertFailed();
        $this->travel(121)->seconds();
    }
    Http::assertSentCount(3);
    Queue::assertPushed(SendDiscordAdminReportJob::class, 1);
    Queue::assertPushed(SendDiscordAdminReportJob::class, fn ($job) => $job->title === __('admin_reports.fflogs_connection_title')
        && str_contains($job->message, 'OAuth token request'));
});

it('does not let a busy monitoring lock mask the original API exception', function () {
    Http::fake(['https://fflogs.test/graphql' => Http::failedConnection()]);
    $lock = Cache::lock('fflogs:connection-observations:api:lock', 5);
    $lock->get();
    try {
        failedFflogsConnection();
    } finally {
        $lock->release();
    }
    Queue::assertNothingPushed();
});

it('identifies otherwise empty lock timeout messages in applicant logs', function () {
    $this->mock(CharacterZoneProgressFetcher::class, fn ($mock) => $mock->shouldReceive('fetchRawZoneRankingsForCharacter')->once()->andThrow(new LockTimeoutException));
    Log::spy();
    $version = new ActivityTypeVersion([
        'fflogs_zone_id' => 48,
        'progress_schema' => ['milestones' => [['key' => 'boss', 'fflogs_matcher' => ['encounter_id' => 101]]]],
    ]);
    app(ApplicantMilestoneResolver::class)->serialize(new Character(['name' => 'Example Player']), $version);

    Log::shouldHaveReceived('warning')->once()->with('Unable to load applicant FF Logs milestone data.', Mockery::on(fn ($context) => $context['exception_type'] === LockTimeoutException::class
        && $context['exception'] === __('admin_reports.reasons.lock_timeout')));
    Queue::assertNothingPushed();
});
