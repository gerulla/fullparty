<?php

namespace App\Services\Integrations;

use App\Models\IntegrationClient;
use App\Models\IntegrationClientHealthCheck;
use App\Services\Notifications\AdminReportService;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class IntegrationHealthcheckService
{
    public function __construct(private readonly AdminReportService $adminReports) {}

    public function checkActiveClients(): void
    {
        IntegrationClient::query()
            ->where('status', IntegrationClient::STATUS_ACTIVE)
            ->whereNotNull('healthcheck_url')
            ->get()
            ->each(fn (IntegrationClient $client) => $this->check($client));
    }

    public function check(IntegrationClient $client): void
    {
        if (blank($client->healthcheck_url)) {
            return;
        }

        $deliveryId = (string) Str::uuid();
        $timestamp = (string) now()->unix();
        $signature = filled($client->webhook_signing_secret)
            ? 'sha256='.hash_hmac('sha256', $timestamp.'.', (string) $client->webhook_signing_secret)
            : null;
        $headers = [
            'User-Agent' => 'FullParty-Integrations/1.0',
            'X-FullParty-Event' => 'integration.healthcheck',
            'X-FullParty-Delivery' => $deliveryId,
            'X-FullParty-Timestamp' => $timestamp,
        ];

        if ($signature !== null) {
            $headers['X-FullParty-Signature'] = $signature;
        }

        $checkedAt = now();
        $startedAt = microtime(true);

        try {
            $response = Http::timeout(5)
                ->retry(1, 250)
                ->withHeaders($headers)
                ->get((string) $client->healthcheck_url);

            $payload = $this->healthPayload($response);
            $status = $this->resolveStatus($response, $payload);

            $this->recordResult(
                client: $client,
                checkedAt: $checkedAt,
                status: $status,
                responseStatus: $response->status(),
                durationMs: $this->durationMs($startedAt),
                error: $this->resultSummary($status, $response, $payload),
            );
        } catch (Throwable $exception) {
            $this->recordResult(
                client: $client,
                checkedAt: $checkedAt,
                status: IntegrationClientHealthCheck::STATUS_UNHEALTHY,
                responseStatus: null,
                durationMs: $this->durationMs($startedAt),
                error: $exception->getMessage(),
            );
        }
    }

    private function recordResult(
        IntegrationClient $client,
        CarbonInterface $checkedAt,
        string $status,
        ?int $responseStatus = null,
        ?int $durationMs = null,
        ?string $error = null,
    ): void {
        $trimmedError = $error === null ? null : Str::limit($error, 500, '...');

        $client->healthChecks()->create([
            'status' => $status,
            'checked_at' => $checkedAt,
            'response_status' => $responseStatus,
            'duration_ms' => $durationMs,
            'error' => $trimmedError,
        ]);

        $client->forceFill([
            'last_healthcheck_at' => $checkedAt,
            'last_healthcheck_ok_at' => $this->isHealthy($status) ? $checkedAt : $client->last_healthcheck_ok_at,
            'last_healthcheck_failed_at' => $this->isFailed($status) ? $checkedAt : null,
            'last_healthcheck_error' => $this->isHealthy($status) ? null : $trimmedError,
        ])->save();

        if (! $this->isHealthy($status)) {
            $this->adminReports->report(
                key: 'integration.health.'.$client->id.'.'.$status,
                titleKey: 'admin_reports.integration_health_title',
                messageKey: 'admin_reports.integration_health_message',
                params: ['client' => $client->name, 'status' => $status],
                severity: $this->isFailed($status) ? 'error' : 'warning',
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function healthPayload(Response $response): ?array
    {
        $payload = $response->json();

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function resolveStatus(Response $response, ?array $payload): string
    {
        $payloadStatus = Str::lower((string) data_get($payload, 'status', ''));

        return match ($payloadStatus) {
            IntegrationClientHealthCheck::STATUS_HEALTHY, 'ok' => IntegrationClientHealthCheck::STATUS_HEALTHY,
            IntegrationClientHealthCheck::STATUS_DEGRADED => IntegrationClientHealthCheck::STATUS_DEGRADED,
            IntegrationClientHealthCheck::STATUS_UNHEALTHY, 'failed', 'error' => IntegrationClientHealthCheck::STATUS_UNHEALTHY,
            default => $this->fallbackStatus($response, $payload),
        };
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function fallbackStatus(Response $response, ?array $payload): string
    {
        if (! $response->successful()) {
            return IntegrationClientHealthCheck::STATUS_UNHEALTHY;
        }

        return data_get($payload, 'ok') === false
            ? IntegrationClientHealthCheck::STATUS_UNHEALTHY
            : IntegrationClientHealthCheck::STATUS_HEALTHY;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function resultSummary(string $status, Response $response, ?array $payload): ?string
    {
        if ($this->isHealthy($status)) {
            return null;
        }

        $summary = $this->checkSummaries($payload);

        if ($summary !== []) {
            return implode('; ', $summary);
        }

        if (! $response->successful()) {
            return 'HTTP '.$response->status();
        }

        return 'Health reported '.$status.'.';
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<int, string>
     */
    private function checkSummaries(?array $payload): array
    {
        $checks = data_get($payload, 'checks');

        if (! is_array($checks)) {
            return [];
        }

        return collect($checks)
            ->map(function ($check, string $name): ?string {
                if (! is_array($check)) {
                    return null;
                }

                $status = Str::lower((string) ($check['status'] ?? ''));
                $ok = $check['ok'] ?? null;

                if (($status === '' || $status === IntegrationClientHealthCheck::STATUS_HEALTHY) && $ok !== false) {
                    return null;
                }

                $label = Str::headline($name);
                $details = $this->checkDetailSummary($check);

                return trim($label.': '.($status ?: 'unhealthy').($details !== null ? ' ('.$details.')' : ''));
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $check
     */
    private function checkDetailSummary(array $check): ?string
    {
        $details = [];

        foreach ([
            'warnCount' => 'warn',
            'errorCount' => 'error',
            'ignoredCount' => 'ignored',
            'queued' => 'queued',
            'processing' => 'processing',
            'failedLastWindow' => 'failed',
            'stuckProcessing' => 'stuck',
            'ping_ms' => 'ping ms',
        ] as $key => $label) {
            if (array_key_exists($key, $check) && $check[$key] !== null) {
                $details[] = $label.': '.$check[$key];
            }
        }

        if (($check['ready'] ?? null) === false) {
            $details[] = 'not ready';
        }

        if (isset($check['lastFailureAt'])) {
            $details[] = 'last failure: '.$check['lastFailureAt'];
        }

        return $details === [] ? null : implode(', ', $details);
    }

    private function isHealthy(string $status): bool
    {
        return in_array($status, [
            IntegrationClientHealthCheck::STATUS_HEALTHY,
            IntegrationClientHealthCheck::STATUS_OK,
        ], true);
    }

    private function isFailed(string $status): bool
    {
        return in_array($status, [
            IntegrationClientHealthCheck::STATUS_UNHEALTHY,
            IntegrationClientHealthCheck::STATUS_FAILED,
        ], true);
    }

    private function durationMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }
}
