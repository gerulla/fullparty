<?php

namespace App\Services\FFLogs;

use App\Services\Notifications\AdminReportService;
use App\Support\Notifications\AdminReportDiagnostics;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class FFLogsClient
{
    private const TOKEN_CACHE_KEY = 'fflogs:client_credentials_token';

    private const TOKEN_CACHE_TTL_BUFFER = 60;

    private const AUTH_FAILURE_TTL_SECONDS = 120;

    public function __construct(
        private readonly AdminReportService $adminReports,
        private readonly FFLogsConnectionMonitor $connectionMonitor,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function query(array $payload): Response
    {
        $token = $this->accessToken();
        $response = $this->send($payload, $token);

        if ($response->status() !== 401) {
            $this->reportUnsuccessfulResponse($response);

            return $response;
        }

        $token = $this->accessToken(rejectedToken: $token);
        $response = $this->send($payload, $token);

        if ($response->status() === 401) {
            $this->backOffRejectedToken($token);
            $this->reportAuthenticationFailure([
                'stage' => AdminReportDiagnostics::reason('api_request'),
                'reason' => AdminReportDiagnostics::reason('token_rejected_after_refresh'),
                ...AdminReportDiagnostics::response($response),
            ]);
        } else {
            $this->reportUnsuccessfulResponse($response);
        }

        return $response;
    }

    private function send(array $payload, string $token): Response
    {
        try {
            $response = Http::withToken($token)
                ->connectTimeout(5)->timeout(15)
                ->acceptJson()
                ->post((string) config('services.ff_logs.graphql_url'), $payload);
            $this->connectionMonitor->receivedResponse('api');

            return $response;
        } catch (ConnectionException $exception) {
            $this->connectionMonitor->connectionFailed('api', $exception);
            throw $exception;
        }
    }

    private function accessToken(?string $rejectedToken = null): string
    {
        $cachedToken = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_string($cachedToken) && $cachedToken !== '' && $cachedToken !== $rejectedToken) {
            return $cachedToken;
        }

        return Cache::lock(self::TOKEN_CACHE_KEY.':fetching', 20)->block(5, function () use ($rejectedToken): string {
            $cachedToken = Cache::get(self::TOKEN_CACHE_KEY);

            // Another request may already have replaced the rejected token.
            if (is_string($cachedToken) && $cachedToken !== '' && $cachedToken !== $rejectedToken) {
                return $cachedToken;
            }

            Cache::forget(self::TOKEN_CACHE_KEY);

            if (Cache::has(self::TOKEN_CACHE_KEY.':failed')) {
                throw new RuntimeException(__('errors.ff_logs_authentication_is_temporarily_unavailable'));
            }

            try {
                return $this->requestAccessToken();
            } catch (Throwable $exception) {
                Cache::put(self::TOKEN_CACHE_KEY.':failed', true, self::AUTH_FAILURE_TTL_SECONDS);
                if ($exception instanceof ConnectionException) {
                    $this->connectionMonitor->connectionFailed('oauth', $exception);
                    throw $exception;
                }
                $details = AdminReportDiagnostics::exception($exception);
                $reason = match ($exception->getMessage()) {
                    'FF Logs credentials are not configured.' => 'credentials_missing',
                    'FF Logs access token was not returned.' => 'token_missing',
                    default => null,
                };
                if ($reason !== null) {
                    $details['reason'] = AdminReportDiagnostics::reason($reason);
                }
                $this->reportAuthenticationFailure(['stage' => AdminReportDiagnostics::reason('oauth_request'), ...$details]);

                throw $exception;
            }
        });
    }

    private function requestAccessToken(): string
    {
        $clientId = config('services.ff_logs.client_id');
        $clientSecret = config('services.ff_logs.client_secret');

        if (! $clientId || ! $clientSecret) {
            throw new RuntimeException('FF Logs credentials are not configured.');
        }

        $response = Http::asForm()
            ->connectTimeout(5)->timeout(10)
            ->withBasicAuth($clientId, $clientSecret)
            ->post(config('services.ff_logs.token_url'), ['grant_type' => 'client_credentials']);
        $this->connectionMonitor->receivedResponse('oauth');
        $response = $response->throw()->json();

        $token = $response['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('FF Logs access token was not returned.');
        }

        $expiresIn = max(0, ((int) ($response['expires_in'] ?? 3600)) - self::TOKEN_CACHE_TTL_BUFFER);
        Cache::put(self::TOKEN_CACHE_KEY, $token, now()->addSeconds($expiresIn));

        return $token;
    }

    private function backOffRejectedToken(string $token): void
    {
        Cache::lock(self::TOKEN_CACHE_KEY.':fetching', 20)->block(5, function () use ($token): void {
            $cachedToken = Cache::get(self::TOKEN_CACHE_KEY);

            // A late 401 must not remove a newer token obtained by another request.
            if ($cachedToken !== null && $cachedToken !== $token) {
                return;
            }

            Cache::forget(self::TOKEN_CACHE_KEY);
            Cache::put(self::TOKEN_CACHE_KEY.':failed', true, self::AUTH_FAILURE_TTL_SECONDS);
        });
    }

    private function reportAuthenticationFailure(array $details): void
    {
        $this->adminReports->report(
            'fflogs.authentication', 'admin_reports.fflogs_auth_title', 'admin_reports.fflogs_auth_message',
            details: [...$details, 'admin_url' => AdminReportDiagnostics::adminUrl('admin.fflogs-playground.index')],
        );
    }

    private function reportUnsuccessfulResponse(Response $response): void
    {
        if ($response->status() === 429 || $response->serverError()) {
            $this->adminReports->report(
                key: 'fflogs.http.'.($response->status() === 429 ? 'rate-limit' : 'server-error'),
                titleKey: 'admin_reports.fflogs_unavailable_title',
                messageKey: 'admin_reports.fflogs_unavailable_message',
                params: ['status' => $response->status()],
                details: [
                    'stage' => AdminReportDiagnostics::reason('api_request'),
                    ...AdminReportDiagnostics::response($response),
                    'admin_url' => AdminReportDiagnostics::adminUrl('admin.fflogs-playground.index'),
                ],
            );
        }
    }
}
