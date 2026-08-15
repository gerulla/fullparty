<?php

namespace App\Http\Controllers;

use App\DTOs\QuotaCheck;
use App\Models\Group;
use App\Services\Quotas\QuotaService;
use App\Support\Quotas\QuotaKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GroupRunListController extends Controller
{
    public function __construct(private readonly QuotaService $quotaService) {}

    public function store(Request $request, Group $group): JsonResponse
    {
        $this->ensureGroupIsAvailable($request, $group);
        $user = $request->user();

        $this->quotaService->runIf([
            new QuotaCheck(QuotaKey::RUN_LIST_GROUPS_TOTAL, $user),
        ], fn (): bool => ! $user->runListGroups()
            ->whereKey($group->id)
            ->exists(), fn () => $user->runListGroups()->syncWithoutDetaching([$group->id]));

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
