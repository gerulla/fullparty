<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\Character;
use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GroupActivityManualSlotAssignmentOptionsController extends Controller
{
    public function show(
        Request $request,
        Group $group,
        Activity $activity,
        ActivitySlot $slot,
    ): JsonResponse {
        $this->authorize('manageDashboard', [$activity, $group]);

        if ($activity->isArchived()) {
            abort(403);
        }

        if ((int) $slot->activity_id !== (int) $activity->id) {
            abort(404);
        }

        $validated = $request->validate([
            'source_slot_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $sourceSlotId = isset($validated['source_slot_id']) ? (int) $validated['source_slot_id'] : null;

        $activity->loadMissing([
            'slots',
            'group.memberships',
        ]);

        $groupMemberUserIds = $group->memberships
            ->pluck('user_id')
            ->push($group->owner_id)
            ->filter()
            ->unique()
            ->values();

        $reservedCharacterIds = $activity->slots
            ->filter(fn (ActivitySlot $activitySlot) => $activitySlot->assigned_character_id !== null)
            ->reject(fn (ActivitySlot $activitySlot) => (int) $activitySlot->id === (int) $slot->id)
            ->reject(fn (ActivitySlot $activitySlot) => $sourceSlotId !== null && (int) $activitySlot->id === $sourceSlotId)
            ->pluck('assigned_character_id')
            ->filter()
            ->unique()
            ->values();

        $characters = Character::query()
            ->with([
                'user:id,name,avatar_url',
                'classes:id,name,shorthand,role,icon_url,flaticon_url',
                'phantomJobs:id,name,icon_url,transparent_icon_url,sprite_url',
            ])
            ->whereIn('user_id', $groupMemberUserIds)
            ->whereNotNull('verified_at')
            ->when($reservedCharacterIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $reservedCharacterIds))
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get();

        return response()->json([
            'characters' => $characters->map(fn (Character $character) => [
                'id' => $character->id,
                'name' => $character->name,
                'avatar_url' => $character->avatar_url,
                'world' => $character->world,
                'datacenter' => $character->datacenter,
                'user' => $character->user ? [
                    'id' => $character->user->id,
                    'name' => $character->user->name,
                    'avatar_url' => $character->user->avatar_url,
                ] : null,
                'character_class_ids' => $character->classes
                    ->pluck('id')
                    ->map(fn ($id) => (string) $id)
                    ->values()
                    ->all(),
                'phantom_job_ids' => $character->phantomJobs
                    ->pluck('id')
                    ->map(fn ($id) => (string) $id)
                    ->values()
                    ->all(),
            ])->values(),
        ]);
    }
}
