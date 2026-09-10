<?php

namespace App\Http\Controllers;

use App\Models\IntegrationClient;
use App\Models\IntegrationClientHealthCheck;
use App\Services\Integrations\IntegrationHealthcheckService;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationClientController extends Controller
{
    public function index(): Response
    {
        $this->authorizeAdminAccess();

        return Inertia::render('Admin/Integrations', [
            'clients' => IntegrationClient::query()
                ->latest()
                ->get()
                ->map(fn (IntegrationClient $client): array => $this->serializeClient($client))
                ->values(),
            'options' => [
                'types' => [
                    IntegrationClient::TYPE_DISCORD_BOT,
                ],
                'statuses' => [
                    IntegrationClient::STATUS_ACTIVE,
                    IntegrationClient::STATUS_PAUSED,
                    IntegrationClient::STATUS_REVOKED,
                ],
                'scopes' => [
                    IntegrationClient::SCOPE_RUNS_READ,
                    IntegrationClient::SCOPE_USERS_READ,
                    IntegrationClient::SCOPE_USERS_WRITE,
                    IntegrationClient::SCOPE_GUILDS_WRITE,
                    IntegrationClient::SCOPE_RESOURCES_READ,
                ],
                'events' => [
                    IntegrationClient::EVENT_DISCORD_USER_APP_INSTALLED,
                    IntegrationClient::EVENT_DISCORD_USER_APP_DISCONNECTED,
                    IntegrationClient::EVENT_DISCORD_NOTIFICATION_DELIVERY,
                    IntegrationClient::EVENT_DISCORD_GUILD_RUN_STARTING_SOON,
                    IntegrationClient::EVENT_DISCORD_GUILD_RUN_STARTING_NOW,
                    IntegrationClient::EVENT_DISCORD_GUILD_RUN_COMPLETED,
                    IntegrationClient::EVENT_DISCORD_GUILD_RUN_CANCELLED,
                    IntegrationClient::EVENT_DISCORD_GUILD_SNAPSHOT_REQUESTED,
                    IntegrationClient::EVENT_DISCORD_GUILD_MEMBERSHIP_SNAPSHOT_REQUESTED,
                    IntegrationClient::EVENT_DISCORD_GUILD_SETTINGS_UPDATED,
                ],
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdminAccess();

        $validated = $this->validatedClientData($request);
        $plainToken = IntegrationClient::makePlainApiToken();
        $plainSecret = bin2hex(random_bytes(32));

        $client = IntegrationClient::query()->create([
            ...$validated,
            'created_by_user_id' => $request->user()->id,
            'api_token_hash' => IntegrationClient::hashApiToken($plainToken),
            'webhook_signing_secret' => $plainSecret,
        ]);

        return back()
            ->with('success', 'integration_client_created')
            ->with('flash_data', [
                'integration_credentials' => [
                    'client_id' => $client->id,
                    'client_name' => $client->name,
                    'api_token' => $plainToken,
                    'webhook_signing_secret' => $plainSecret,
                ],
            ]);
    }

    public function update(Request $request, IntegrationClient $integrationClient): RedirectResponse
    {
        $this->authorizeAdminAccess();

        $integrationClient->update($this->validatedClientData($request));

        return back()->with('success', 'integration_client_updated');
    }

    public function regenerateApiToken(IntegrationClient $integrationClient): RedirectResponse
    {
        $this->authorizeAdminAccess();

        $plainToken = IntegrationClient::makePlainApiToken();

        $integrationClient->update([
            'api_token_hash' => IntegrationClient::hashApiToken($plainToken),
        ]);

        return back()
            ->with('success', 'integration_client_api_token_regenerated')
            ->with('flash_data', [
                'integration_credentials' => [
                    'client_id' => $integrationClient->id,
                    'client_name' => $integrationClient->name,
                    'api_token' => $plainToken,
                    'webhook_signing_secret' => null,
                ],
            ]);
    }

    public function regenerateWebhookSecret(IntegrationClient $integrationClient): RedirectResponse
    {
        $this->authorizeAdminAccess();

        $plainSecret = bin2hex(random_bytes(32));

        $integrationClient->update([
            'webhook_signing_secret' => $plainSecret,
        ]);

        return back()
            ->with('success', 'integration_client_webhook_secret_regenerated')
            ->with('flash_data', [
                'integration_credentials' => [
                    'client_id' => $integrationClient->id,
                    'client_name' => $integrationClient->name,
                    'api_token' => null,
                    'webhook_signing_secret' => $plainSecret,
                ],
            ]);
    }

    public function runHealthcheck(
        IntegrationClient $integrationClient,
        IntegrationHealthcheckService $healthcheckService,
    ): RedirectResponse {
        $this->authorizeAdminAccess();

        if (blank($integrationClient->healthcheck_url)) {
            return back()->withErrors([
                'healthcheck_url' => 'integration_client_healthcheck_url_missing',
            ]);
        }

        $healthcheckService->check($integrationClient);

        return back()->with('success', 'integration_client_healthcheck_ran');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedClientData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in([IntegrationClient::TYPE_DISCORD_BOT])],
            'status' => ['required', Rule::in([
                IntegrationClient::STATUS_ACTIVE,
                IntegrationClient::STATUS_PAUSED,
                IntegrationClient::STATUS_REVOKED,
            ])],
            'outbound_events_url' => ['nullable', 'url:http,https', 'max:2048'],
            'healthcheck_url' => ['nullable', 'url:http,https', 'max:2048'],
            'scopes' => ['array'],
            'scopes.*' => [Rule::in([
                IntegrationClient::SCOPE_RUNS_READ,
                IntegrationClient::SCOPE_USERS_READ,
                IntegrationClient::SCOPE_USERS_WRITE,
                IntegrationClient::SCOPE_GUILDS_WRITE,
                IntegrationClient::SCOPE_RESOURCES_READ,
            ])],
            'allowed_events' => ['array'],
            'allowed_events.*' => [Rule::in([
                IntegrationClient::EVENT_DISCORD_USER_APP_INSTALLED,
                IntegrationClient::EVENT_DISCORD_USER_APP_DISCONNECTED,
                IntegrationClient::EVENT_DISCORD_NOTIFICATION_DELIVERY,
                IntegrationClient::EVENT_DISCORD_GUILD_RUN_STARTING_SOON,
                IntegrationClient::EVENT_DISCORD_GUILD_RUN_STARTING_NOW,
                IntegrationClient::EVENT_DISCORD_GUILD_RUN_COMPLETED,
                IntegrationClient::EVENT_DISCORD_GUILD_RUN_CANCELLED,
                IntegrationClient::EVENT_DISCORD_GUILD_SNAPSHOT_REQUESTED,
                IntegrationClient::EVENT_DISCORD_GUILD_MEMBERSHIP_SNAPSHOT_REQUESTED,
                IntegrationClient::EVENT_DISCORD_GUILD_SETTINGS_UPDATED,
            ])],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeClient(IntegrationClient $client): array
    {
        $latestHealthcheck = $client->healthChecks()
            ->latest('checked_at')
            ->first();

        return [
            'id' => $client->id,
            'name' => $client->name,
            'type' => $client->type,
            'status' => $client->status,
            'outbound_events_url' => $client->outbound_events_url,
            'healthcheck_url' => $client->healthcheck_url,
            'has_api_token' => filled($client->api_token_hash),
            'has_webhook_signing_secret' => filled($client->webhook_signing_secret),
            'scopes' => $client->scopes ?? [],
            'allowed_events' => $client->allowed_events ?? [],
            'last_event_sent_at' => $client->last_event_sent_at?->toIso8601String(),
            'last_event_failed_at' => $client->last_event_failed_at?->toIso8601String(),
            'last_event_error' => $client->last_event_error,
            'last_healthcheck_at' => $client->last_healthcheck_at?->toIso8601String(),
            'last_healthcheck_ok_at' => $client->last_healthcheck_ok_at?->toIso8601String(),
            'last_healthcheck_failed_at' => $client->last_healthcheck_failed_at?->toIso8601String(),
            'last_healthcheck_error' => $client->last_healthcheck_error,
            'latest_healthcheck' => $latestHealthcheck ? [
                'status' => $this->normalizeHealthStatus($latestHealthcheck->status),
                'checked_at' => $latestHealthcheck->checked_at?->toIso8601String(),
                'response_status' => $latestHealthcheck->response_status,
                'duration_ms' => $latestHealthcheck->duration_ms,
                'error' => $latestHealthcheck->error,
            ] : null,
            'healthcheck_stats' => $this->serializeHealthcheckStats($client),
            'last_api_used_at' => $client->last_api_used_at?->toIso8601String(),
            'created_at' => $client->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{day: array<string, mixed>, week: array<string, mixed>}
     */
    private function serializeHealthcheckStats(IntegrationClient $client): array
    {
        $now = now();

        return [
            'day' => $this->serializeHealthcheckPeriod($client, $now->copy()->subDay(), $now, 60),
            'week' => $this->serializeHealthcheckPeriod($client, $now->copy()->subWeek(), $now, 360),
        ];
    }

    /**
     * @return array{total: int, failed: int, degraded: int, uptime: int|null, buckets: array<int, array{status: string, checked: int, failed: int, degraded: int, started_at: string, ended_at: string}>}
     */
    private function serializeHealthcheckPeriod(IntegrationClient $client, CarbonInterface $since, CarbonInterface $until, int $bucketMinutes): array
    {
        $checks = $client->healthChecks()
            ->where('checked_at', '>=', $since)
            ->where('checked_at', '<=', $until)
            ->orderBy('checked_at')
            ->get(['status', 'checked_at']);

        $total = $checks->count();

        if ($total === 0) {
            return [
                'total' => 0,
                'failed' => 0,
                'degraded' => 0,
                'uptime' => null,
                'buckets' => $this->emptyHealthcheckBuckets($since, $until, $bucketMinutes),
            ];
        }

        $unhealthy = $checks->filter(fn (IntegrationClientHealthCheck $check): bool => $this->isUnhealthyStatus($check->status))->count();

        return [
            'total' => $total,
            'failed' => $unhealthy,
            'degraded' => $checks->filter(fn (IntegrationClientHealthCheck $check): bool => $this->normalizeHealthStatus($check->status) === IntegrationClientHealthCheck::STATUS_DEGRADED)->count(),
            'uptime' => (int) round((($total - $unhealthy) / $total) * 100),
            'buckets' => $this->healthcheckBuckets($checks, $since, $until, $bucketMinutes),
        ];
    }

    /**
     * @param  Collection<int, IntegrationClientHealthCheck>  $checks
     * @return array<int, array{status: string, checked: int, failed: int, degraded: int, started_at: string, ended_at: string}>
     */
    private function healthcheckBuckets($checks, CarbonInterface $since, CarbonInterface $until, int $bucketMinutes): array
    {
        $buckets = [];
        $cursor = $since->copy();

        while ($cursor->lessThan($until)) {
            $bucketStart = $cursor->copy();
            $bucketEnd = $cursor->copy()->addMinutes($bucketMinutes)->min($until);
            $bucketChecks = $checks->filter(
                fn (IntegrationClientHealthCheck $check): bool => $check->checked_at->greaterThanOrEqualTo($bucketStart)
                    && $check->checked_at->lessThan($bucketEnd)
            );
            $unhealthy = $bucketChecks->filter(fn (IntegrationClientHealthCheck $check): bool => $this->isUnhealthyStatus($check->status))->count();
            $degraded = $bucketChecks->filter(fn (IntegrationClientHealthCheck $check): bool => $this->normalizeHealthStatus($check->status) === IntegrationClientHealthCheck::STATUS_DEGRADED)->count();

            $buckets[] = [
                'status' => $bucketChecks->isEmpty()
                    ? 'unknown'
                    : ($unhealthy > 0
                        ? IntegrationClientHealthCheck::STATUS_UNHEALTHY
                        : ($degraded > 0 ? IntegrationClientHealthCheck::STATUS_DEGRADED : IntegrationClientHealthCheck::STATUS_HEALTHY)),
                'checked' => $bucketChecks->count(),
                'failed' => $unhealthy,
                'degraded' => $degraded,
                'started_at' => $bucketStart->toIso8601String(),
                'ended_at' => $bucketEnd->toIso8601String(),
            ];

            $cursor = $bucketEnd;
        }

        return $buckets;
    }

    /**
     * @return array<int, array{status: string, checked: int, failed: int, degraded: int, started_at: string, ended_at: string}>
     */
    private function emptyHealthcheckBuckets(CarbonInterface $since, CarbonInterface $until, int $bucketMinutes): array
    {
        return $this->healthcheckBuckets(collect(), $since, $until, $bucketMinutes);
    }

    private function normalizeHealthStatus(string $status): string
    {
        return match ($status) {
            IntegrationClientHealthCheck::STATUS_OK, IntegrationClientHealthCheck::STATUS_HEALTHY => IntegrationClientHealthCheck::STATUS_HEALTHY,
            IntegrationClientHealthCheck::STATUS_DEGRADED => IntegrationClientHealthCheck::STATUS_DEGRADED,
            IntegrationClientHealthCheck::STATUS_FAILED, IntegrationClientHealthCheck::STATUS_UNHEALTHY => IntegrationClientHealthCheck::STATUS_UNHEALTHY,
            default => $status,
        };
    }

    private function isUnhealthyStatus(string $status): bool
    {
        return $this->normalizeHealthStatus($status) === IntegrationClientHealthCheck::STATUS_UNHEALTHY;
    }

    private function authorizeAdminAccess(): void
    {
        if (! auth()->user()?->is_admin) {
            abort(403);
        }
    }
}
