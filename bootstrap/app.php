<?php

use App\Http\Middleware\EnsureGroupDashboardAccess;
use App\Http\Middleware\ApplyLocale;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'group.dashboard.access' => EnsureGroupDashboardAccess::class,
        ]);

        $middleware->web(append: [
			ApplyLocale::class,
			HandleInertiaRequests::class,
		]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (!$request->is('auth/*')) {
                $request->session()->put('url.intended', $request->fullUrl());
            }

            return redirect()
                ->guest(route('login'))
                ->with('error', 'session_expired');
        });

        $exceptions->render(function (TokenMismatchException $exception, Request $request) {
            if ($request->user() !== null && !$request->is('auth/*')) {
                $request->session()->put('url.intended', $request->fullUrl());
            }

            return redirect()
                ->guest(route('login'))
                ->with('error', 'session_expired');
        });
    })->create();
