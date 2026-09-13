<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountNotBanned
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->banned_at && ! $request->routeIs('account.banned', 'logout', 'locale.*', 'reports.feedback.*')) {
            if ($request->is('api/*') || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
                abort(403, __('reports.errors.banned'));
            }

            // A rejected form submission must become a GET of the notice page.
            return redirect()->route('account.banned', status: $request->isMethodSafe() ? 302 : 303);
        }

        return $next($request);
    }
}
