<?php

namespace App\Services\FFLogs;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class FFLogsPlaygroundClient
{
    private const TOKEN_CACHE_KEY = 'fflogs:client_credentials_token';

    private const TOKEN_CACHE_TTL_BUFFER = 60;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(array $payload): Response
    {
        return Http::withToken($this->getAccessToken())
            ->acceptJson()
            ->post((string) config('services.ff_logs.graphql_url'), $payload);
    }

    public function endpoint(): string
    {
        return (string) config('services.ff_logs.graphql_url');
    }

    private function getAccessToken(): string
    {
        $cachedToken = Cache::get(self::TOKEN_CACHE_KEY);

        if ($cachedToken) {
            return $cachedToken;
        }

        $clientId = config('services.ff_logs.client_id');
        $clientSecret = config('services.ff_logs.client_secret');

        if (! $clientId || ! $clientSecret) {
            throw new RuntimeException('FF Logs credentials are not configured.');
        }

        $response = Http::asForm()
            ->withBasicAuth($clientId, $clientSecret)
            ->post((string) config('services.ff_logs.token_url'), [
                'grant_type' => 'client_credentials',
            ])
            ->throw()
            ->json();

        $token = $response['access_token'] ?? null;

        if (! $token) {
            throw new RuntimeException('FF Logs access token was not returned.');
        }

        $expiresIn = max(0, ((int) ($response['expires_in'] ?? 3600)) - self::TOKEN_CACHE_TTL_BUFFER);

        Cache::put(self::TOKEN_CACHE_KEY, $token, now()->addSeconds($expiresIn));

        return $token;
    }
}
