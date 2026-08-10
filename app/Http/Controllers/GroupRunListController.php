<?php

namespace App\Http\Controllers;

use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GroupRunListController extends Controller
{
    public function store(Request $request, Group $group): JsonResponse
    {
        $this->ensureGroupIsAvailable($request, $group);
        $request->user()->runListGroups()->syncWithoutDetaching([$group->id]);

        return response()->json(['is_in_my_runs' => true]);
    }

    public function destroy(Request $request, Group $group): JsonResponse
    {
        $request->user()->runListGroups()->detach($group->id);

        return response()->json(['is_in_my_runs' => false]);
    }

    private function ensureGroupIsAvailable(Request $request, Group $group): void
    {
        $group->loadMissing('memberships');

        if (! $group->hasMember($request->user()->id) && ! $group->allowsPublicDashboardAccess()) {
            abort(404);
        }
    }
}
