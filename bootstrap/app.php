<?php

use App\Http\Middleware\ApplyLocale;
use App\Http\Middleware\AuthenticateIntegrationClient;
use App\Http\Middleware\EnsureGroupDashboardAccess;
use App\Http\Middleware\EnsureWebsiteAdminAccess;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Laravel\Passport\Http\Middleware\CheckToken;
use Laravel\Passport\Http\Middleware\CheckTokenForAnyScope;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: [
            __DIR__.'/../routes/api.php',
            __DIR__.'/../routes/xivplugin.php',
        ],
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustHosts();

        $middleware->alias([
            'admin' => EnsureWebsiteAdminAccess::class,
            'group.dashboard.access' => EnsureGroupDashboardAccess::class,
            'integration.client' => AuthenticateIntegrationClient::class,
            'scopes' => CheckToken::class,
            'scope' => CheckTokenForAnyScope::class,
        ]);

        $middleware->web(append: [
            ApplyLocale::class,
            HandleInertiaRequests::class,
            SecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isAuthPath = static function (Request $request): bool {
            $segments = array_values(array_filter(explode('/', trim($request->path(), '/'))));
            $firstSegment = $segments[0] ?? null;

            if (in_array($firstSegment, ApplyLocale::SUPPORTED_LOCALES, true)) {
                array_shift($segments);
            }

            return ($segments[0] ?? null) === 'auth';
        };
        $rememberIntendedPath = static function (Request $request): void {
            $intended = $request->getRequestUri();

            if (is_string($intended) && str_starts_with($intended, '/')) {
                $request->session()->put('url.intended', $intended);
            }
        };

        $exceptions->render(function (AuthenticationException $exception, Request $request) use ($isAuthPath, $rememberIntendedPath) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            if (! $isAuthPath($request)) {
                $rememberIntendedPath($request);
            }

            return redirect()
                ->guest(route('login'))
                ->with('error', 'session_expired');
        });

        $exceptions->render(function (TokenMismatchException $exception, Request $request) use ($isAuthPath, $rememberIntendedPath) {
            if ($request->user() !== null && ! $isAuthPath($request)) {
                $rememberIntendedPath($request);
            }

            return redirect()
                ->guest(route('login'))
                ->with('error', 'session_expired');
        });
    })->create();
