<?php

namespace App\Services\FFLogs;

use App\Services\Notifications\AdminReportService;
use App\Support\Notifications\AdminReportDiagnostics;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class FFLogsConnectionMonitor
{
    private const WINDOW_SECONDS = 900;

    private const SAMPLE_INTERVAL_SECONDS = 60;

    private const FAILURE_THRESHOLD = 3;

    public function __construct(private readonly AdminReportService $adminReports) {}

    public function receivedResponse(string $stage): void
    {
        $key = 'fflogs:connection-observations:'.$stage;
        try {
            // A response must reset the streak even while another request holds the sampling lock.
            Cache::put($key.':last-response', (string) Str::uuid(), self::WINDOW_SECONDS);
            Cache::forget($key);
        } catch (Throwable $exception) {
            Log::warning('Unable to reset FF Logs connection observations.', ['exception_type' => $exception::class]);
        }
    }

    public function connectionFailed(string $stage, ConnectionException $exception): void
    {
        $state = $this->update($stage, function (string $key): ?array {
            $timestamp = now()->timestamp;
            $state = Cache::get($key);
            $responseMarker = Cache::get($key.':last-response');
            if (! is_array($state) || ($state['response_marker'] ?? null) !== $responseMarker
                || $timestamp - $state['first_failed_at'] > self::WINDOW_SECONDS) {
                $state = ['count' => 0, 'first_failed_at' => $timestamp, 'last_sampled_at' => null, 'response_marker' => $responseMarker];
            }

            // Many viewers can fail together. Count a burst as one observation, not an outage.
            if ($state['last_sampled_at'] !== null && $timestamp - $state['last_sampled_at'] < self::SAMPLE_INTERVAL_SECONDS) {
                return null;
            }

            $state['count']++;
            $state['last_sampled_at'] = $timestamp;
            Cache::put($key, $state, self::WINDOW_SECONDS);

            return $state;
        });

        if (! is_array($state) || $state['count'] < self::FAILURE_THRESHOLD) {
            return;
        }

        // Do not alert on a stale failure observation if another request has since received a response.
        if (rescue(fn () => Cache::get('fflogs:connection-observations:'.$stage.':last-response'), 'unavailable', report: false) !== $state['response_marker']) {
            return;
        }

        $this->adminReports->report(
            key: 'fflogs.connection.'.$stage,
            titleKey: 'admin_reports.fflogs_connection_title',
            messageKey: 'admin_reports.fflogs_connection_message',
            params: ['count' => $state['count'], 'seconds' => $state['last_sampled_at'] - $state['first_failed_at']],
            severity: 'warning',
            details: [
                'stage' => AdminReportDiagnostics::reason($stage === 'oauth' ? 'oauth_request' : 'api_request'),
                ...AdminReportDiagnostics::exception($exception),
                'first_failed_at' => gmdate('Y-m-d\TH:i:s\Z', $state['first_failed_at']),
                'admin_url' => AdminReportDiagnostics::adminUrl('admin.fflogs-playground.index'),
            ],
        );
    }

    private function update(string $stage, callable $callback): mixed
    {
        $key = 'fflogs:connection-observations:'.$stage;
        try {
            // Monitoring must not add latency or replace the actual API error if its cache is busy.
            return Cache::lock($key.':lock', 5)->get(fn () => $callback($key));
        } catch (Throwable $exception) {
            Log::warning('Unable to update FF Logs connection observations.', ['exception_type' => $exception::class]);

            return null;
        }
    }
}
