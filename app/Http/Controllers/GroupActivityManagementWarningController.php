<?php

namespace App\Http\Controllers;

use App\Http\Resources\ActivityManagementWarningResource;
use App\Models\Activity;
use App\Models\ActivityManagementWarning;
use App\Models\Group;
use App\Services\Groups\ActivityManagementRealtimeService;
use App\Services\Groups\ActivityManagementWarningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GroupActivityManagementWarningController extends Controller
{
    public function destroy(
        Request $request,
        Group $group,
        Activity $activity,
        ActivityManagementWarning $managementWarning,
        ActivityManagementWarningService $warningService,
        ActivityManagementRealtimeService $realtimeService,
    ): JsonResponse {
        $this->authorize('manageDashboard', [$activity, $group]);

        if ((int) $managementWarning->activity_id !== (int) $activity->id) {
            abort(404);
        }

        $warning = $warningService->dismiss($managementWarning, $request->user());

        $realtimeService->broadcastPatch($activity, [
            'remove_management_warning_ids' => [(int) $warning->id],
        ]);

        return response()->json([
            'data' => ActivityManagementWarningResource::make($warning)->resolve(),
        ]);
    }
}
