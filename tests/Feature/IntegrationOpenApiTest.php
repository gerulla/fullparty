<?php

use Illuminate\Support\Facades\Route;

it('documents every integration endpoint with its real scope and parameters', function () {
    $document = json_decode(file_get_contents(resource_path('openapi/fullparty.json')), true, flags: JSON_THROW_ON_ERROR);
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/integrations/'));
    $actual = [];
    $operationIds = [];
    foreach ($routes as $route) {
        expect($route->uri())->toStartWith('api/integrations/v1/');
        $path = '/'.preg_replace('/\{([^}:]+):[^}]+\}/', '{$1}', $route->uri());
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $method = strtolower($method);
            $actual[] = $method.' '.$path;
            $operation = $document['paths'][$path][$method] ?? null;
            expect($operation)->not->toBeNull($method.' '.$path);
            $operationIds[] = $operation['operationId'];
            $scope = collect($route->middleware())->first(fn ($middleware) => str_starts_with($middleware, 'integration.client:'));
            expect($operation['x-required-scope'])->toBe(substr($scope, strlen('integration.client:')));
            if (str_starts_with($route->getName(), 'api.members.')) {
                expect($operation['security'])->toBe([['IntegrationToken' => [], 'LinkedDiscordUser' => []]]);
            } else {
                expect($route->uri())->toStartWith('api/integrations/v1/bot/');
                expect($operation['security'])->toBe([['IntegrationToken' => []]]);
            }
            preg_match_all('/\{([^}]+)\}/', $path, $matches);
            expect(collect($operation['parameters'])->where('in', 'path')->pluck('name')->sort()->values()->all())->toBe(collect($matches[1])->sort()->values()->all());
        }
    }
    $documented = [];
    foreach ($document['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            $documented[] = $method.' '.$path;
        }
    }
    sort($actual);
    sort($documented);
    expect($documented)->toBe($actual)->and(array_unique($operationIds))->toHaveCount(count($operationIds));

    $checkReferences = function ($value) use (&$checkReferences, $document): void {
        if (! is_array($value)) {
            return;
        }
        if (isset($value['$ref'])) {
            $resolved = $document;
            foreach (explode('/', substr($value['$ref'], 2)) as $key) {
                expect($resolved)->toHaveKey($key);
                $resolved = $resolved[$key];
            }
        }
        foreach ($value as $child) {
            $checkReferences($child);
        }
    };
    $checkReferences($document);
});

it('serves the public specification on the API subdomain and prevents website fallthrough', function () {
    $host = config('integration_api.docs_host');
    $this->getJson('http://'.$host.'/openapi.json')->assertOk()->assertJsonPath('openapi', '3.1.0')->assertJsonPath('servers.0.url', 'http://'.$host);
    $this->get('http://'.$host.'/')->assertOk()->assertSee('api-reference')->assertSee('FullParty');
    $this->get('http://'.$host.'/en/settings')->assertNotFound();
});
