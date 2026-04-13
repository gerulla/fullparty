<?php

namespace App\Http\Middleware;

use App\Models\Group;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGroupDashboardAccess
{
    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        /** @var Group|null $group */
        $group = $request->route('group');

        if (!$group instanceof Group) {
            abort(404);
        }

        if ($group->hasMember($request->user()?->id)) {
            return $next($request);
        }

        if ($group->is_visible && $request->isMethodSafe()) {
            return redirect()->route('groups.show', $group);
        }

        abort(404);
    }
}
