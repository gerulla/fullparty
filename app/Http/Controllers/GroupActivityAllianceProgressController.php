<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithGroupActivityAttendees;
use App\Models\Activity;
use App\Models\Group;
use App\Services\Groups\AllianceProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GroupActivityAllianceProgressController extends Controller
{
    use InteractsWithGroupActivityAttendees;

    public function __invoke(Request $request, Group $group, Activity $activity, AllianceProgressService $progress): JsonResponse
    {
        $this->ensureActivityBelongsToGroup($group, $activity);
        abort_unless($this->canAccessOverview($request, $group, $activity, null), 404);
        $validated = $request->validate([
            'character_ids' => ['required', 'array', 'min:1', 'max:4'],
            'character_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $characters = $activity->slots()->whereIn('assigned_character_id', $validated['character_ids'])
            ->with('assignedCharacter')->get()->pluck('assignedCharacter')->filter()->unique('id');
        abort_unless($characters->count() === count($validated['character_ids']), 404);

        return response()->json(['characters' => $progress->forCharacters($activity, $characters)]);
    }
}
