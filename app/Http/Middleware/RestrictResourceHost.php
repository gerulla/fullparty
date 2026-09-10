<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictResourceHost
{
    public function handle(Request $request, Closure $next): Response
    {
        // Domain-less application routes must not fall through on the anonymous wiki host.
        if ($request->getHost() === config('group_resources.public_host')) {
            abort_unless($request->routeIs('public-resources.*'), 404);
        }

        return $next($request);
    }
}
