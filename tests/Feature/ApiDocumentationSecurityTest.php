<?php

use App\Http\Middleware\ApiDocumentationSecurityHeaders;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

it('restricts the documentation page to local assets and same-origin API requests', function () {
    $response = $this->get('https://'.config('integration_api.docs_host').'/')->assertOk();
    $policy = $response->headers->get('Content-Security-Policy');

    expect($policy)->toContain("script-src 'self';", "connect-src 'self';", "font-src 'self' data:;", "base-uri 'none'", "frame-ancestors 'none'", "object-src 'none'", "form-action 'none'")
        ->not->toContain('unsafe-eval', 'https:', 'http:', '*');
    $this->get(config('app.url').'/')->assertHeaderMissing('Content-Security-Policy');
});

it('allows the configured local Vite origin and HMR without relaxing production', function () {
    $hotFile = tempnam(sys_get_temp_dir(), 'fullparty-vite-test-');
    file_put_contents($hotFile, 'https://fullparty.test:5173');
    $middleware = new ApiDocumentationSecurityHeaders((new Vite)->useHotFile($hotFile));
    $request = Request::create('https://api.fullparty.test/');

    try {
        app()->instance('env', 'local');
        $local = $middleware->handle($request, fn () => new Response);
        expect($local->headers->get('Content-Security-Policy'))->toContain(
            "script-src 'self' https://fullparty.test:5173;",
            "connect-src 'self' https://fullparty.test:5173 wss://fullparty.test:5173;",
            "font-src 'self' https://fullparty.test:5173 data:;",
        );

        app()->instance('env', 'production');
        $production = $middleware->handle($request, fn () => new Response);
        expect($production->headers->get('Content-Security-Policy'))->not->toContain('5173', 'wss:', 'unsafe-eval');
    } finally {
        unlink($hotFile);
    }
});
