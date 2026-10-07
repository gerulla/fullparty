<?php

namespace App\Http\Middleware;

use App\Models\IntegrationClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateIntegrationClient
{
    public function __construct(private readonly ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        // Count failures before looking up credentials or resolving a linked account.
        return $this->throttle->handle($request, fn (Request $request) => $this->authenticate($request, $next, $scopes), 'integration.authentication');
    }

    private function authenticate(Request $request, Closure $next, array $scopes): Response
    {
        $token = $request->bearerToken();

        if (! is_string($token) || blank($token)) {
            abort(401);
        }

        $client = IntegrationClient::query()
            ->where('api_token_hash', IntegrationClient::hashApiToken($token))
            ->first();

        if (! $client?->isActive()) {
            abort(401);
        }

        $request->attributes->set('integration_client', $client);

        return $this->throttle->handle($request, function (Request $request) use ($client, $scopes, $next): Response {
            foreach ($scopes as $scope) {
                abort_unless($client->hasScope($scope), 403);
            }

            $response = $next($request);

            if ($response->getStatusCode() < 400 && (! $client->last_api_used_at || $client->last_api_used_at->lt(now()->subMinute()))) {
                // Coalesce usage writes, including simultaneous admitted requests.
                IntegrationClient::query()->whereKey($client->id)
                    ->where(fn ($query) => $query->whereNull('last_api_used_at')->orWhere('last_api_used_at', '<', now()->subMinute()))
                    ->update(['last_api_used_at' => now()]);
            }

            return $response;
        }, 'integration.api');
    }
}
