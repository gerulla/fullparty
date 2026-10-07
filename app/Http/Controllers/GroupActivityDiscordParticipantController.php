<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\Group;
use App\Services\Groups\ActivityDiscordParticipantSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GroupActivityDiscordParticipantController extends Controller
{
    public function store(Request $request, Group $group, Activity $activity, ActivitySlot $slot, ActivityDiscordParticipantSyncService $sync): JsonResponse
    {
        $this->authorize('manageDashboard', [$activity, $group]);
        abort_unless((int) $slot->activity_id === (int) $activity->id, 404);

        $data = $request->validate([
            'discord_user_id' => ['required', 'string', 'regex:/^[1-9][0-9]{16,19}$/D'],
            'expected_slot_state_token' => ['required', 'string'],
        ], ['discord_user_id.*' => __('groups.activities.management.discord_sync.invalid_id')]);

        $sync->sync($group, $activity, $slot, $request->user(), $data['discord_user_id'], $data['expected_slot_state_token']);

        return response()->json(['success' => true])->header('Cache-Control', 'private, no-store');
    }
}
