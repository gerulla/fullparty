<?php

namespace App\Services\Integrations;

use App\Models\IntegrationClient;
use App\Support\Integrations\IntegrationEndpoint;
use App\Support\Notifications\AdminReportDiagnostics;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class IntegrationWebhookDispatcher
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{attempted: int, sent: int, failed: int, deliveries: array<int, array<string, mixed>>}
     */
    public function dispatchDiscordBotEvent(string $event, array $data, ?string $permissionEvent = null, ?string $deliveryId = null): array
    {
        $deliveries = $this->discordBotClientsForEvent($permissionEvent ?? $event)
            ->map(fn (IntegrationClient $client): array => $this->dispatch($client, $event, $data, deliveryId: $deliveryId))
            ->values()
            ->all();

        return [
            'attempted' => count($deliveries),
            'sent' => collect($deliveries)->where('status', 'sent')->count(),
            'failed' => collect($deliveries)->where('status', 'failed')->count(),
            'deliveries' => $deliveries,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    public function requestDiscordBotEvent(string $event, array $data, ?string $permissionEvent = null): ?array
    {
        foreach ($this->discordBotClientsForEvent($permissionEvent ?? $event) as $client) {
            $delivery = $this->dispatch($client, $event, $data, captureResponse: true);

            if ($delivery['status'] === 'sent') {
                return is_array($delivery['response'] ?? null) ? $delivery['response'] : [];
            }
        }

        return null;
    }

    /**
     * @return Collection<int, IntegrationClient>
     */
    private function discordBotClientsForEvent(string $event): Collection
    {
        return IntegrationClient::query()
            ->where('type', IntegrationClient::TYPE_DISCORD_BOT)
            ->where('status', IntegrationClient::STATUS_ACTIVE)
            ->get()
            ->filter(fn (IntegrationClient $client): bool => filled($client->outbound_events_url)
                && filled($client->webhook_signing_secret)
                && $client->allowsEvent($event));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function dispatch(IntegrationClient $client, string $event, array $data, bool $captureResponse = false, ?string $deliveryId = null): array
    {
        $deliveryId ??= (string) Str::uuid();
        $timestamp = (string) now()->unix();
        $payload = [
            'integration_client_id' => $client->id,
            'id' => $deliveryId,
            'event' => $event,
            'occurred_at' => now()->toIso8601String(),
            'data' => $data,
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        try {
            IntegrationEndpoint::assertSecure((string) $client->outbound_events_url);
            $response = Http::timeout(5)->withoutRedirecting()->retry(2, 250)->withHeaders([
                'User-Agent' => 'FullParty-Integrations/1.0',
                'X-FullParty-Event' => $event,
                'X-FullParty-Delivery' => $deliveryId,
                'X-FullParty-Timestamp' => $timestamp,
                'X-FullParty-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) $client->webhook_signing_secret),
            ])->withBody($body, 'application/json')
                ->post((string) $client->outbound_events_url)
                ->throw();

            if (! $response->successful()) {
                throw new RuntimeException('Integration endpoint returned an unexpected HTTP status '.$response->status().'.');
            }

            $client->forceFill([
                'last_event_sent_at' => now(),
                'last_event_failed_at' => null,
                'last_event_error' => null,
            ])->save();

            return [
                'client_id' => $client->id,
                'delivery_id' => $deliveryId,
                'status' => 'sent',
                'error' => null,
                'response' => $captureResponse ? $this->decodeResponse($response) : null,
            ];
        } catch (Throwable $exception) {
            // The participant ID is temporary. A bot error may echo it in its body.
            $error = $event === IntegrationClient::EVENT_DISCORD_GUILD_RUN_PARTICIPANT_SYNC
                ? 'Discord run participant synchronization failed.'
                : $exception->getMessage();
            $client->forceFill([
                'last_event_failed_at' => now(),
                'last_event_error' => $error,
            ])->save();

            app(IntegrationAdminNotificationService::class)->notifyEventDeliveryFailed($client->fresh(), $event, $error, [
                ...AdminReportDiagnostics::exception($exception),
                'delivery_id' => $deliveryId,
            ]);

            return [
                'client_id' => $client->id,
                'delivery_id' => $deliveryId,
                'status' => 'failed',
                'error' => $error,
                'response' => null,
            ];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeResponse(Response $response): ?array
    {
        $decoded = $response->json();

        return is_array($decoded) ? $decoded : null;
    }
}
