<?php

namespace App\Support\Integrations;

use App\Exceptions\InsecureIntegrationEndpointException;

final class IntegrationEndpoint
{
    public static function validationRule(): string
    {
        return app()->environment('local', 'testing') ? 'url:http,https' : 'url:https';
    }

    public static function assertSecure(string $url): void
    {
        if (! app()->environment('local', 'testing') && strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new InsecureIntegrationEndpointException('Integration delivery requires an HTTPS endpoint outside local development.');
        }
    }
}
