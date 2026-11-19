<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivitySlotFieldValue;
use App\Models\Group;
use App\Services\Runs\AssignedHolsterLoadout;
use App\Services\Runs\RunAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GroupActivityHolsterLoadoutController extends Controller
{
    public function __invoke(Request $request, Group $group, Activity $activity, int $field, RunAccessService $access, AssignedHolsterLoadout $loadout): JsonResponse
    {
        abort_unless($access->canView($group, $activity, $request->user()?->id), 404);
        $assignment = ActivitySlotFieldValue::where('source', 'bozja_holsters')
            ->whereHas('activitySlot', fn ($slot) => $slot->where('activity_id', $activity->id)->whereNotNull('assigned_character_id'))
            ->findOrFail($field);

        return response()->json($loadout->forAssignment($group, $assignment, $request->user()));
    }
}
