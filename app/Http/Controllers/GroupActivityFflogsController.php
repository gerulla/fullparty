<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Character;
use App\Models\Group;
use App\Services\FFLogs\CharacterZoneProgressFetcher;
use Illuminate\Http\JsonResponse;

class GroupActivityFflogsController extends Controller
{
    public function show(Group $group, Activity $activity, Character $character, CharacterZoneProgressFetcher $fetcher): JsonResponse
    {
        $this->authorize('viewCharacterFflogs', [$activity, $group, $character]);

        $activity->loadMissing('activityTypeVersion');

        $zoneId = (int) ($activity->activityTypeVersion?->fflogs_zone_id ?? 0);

        if ($zoneId <= 0) {
            return response()->json([
                'progress' => null,
            ]);
        }

        return response()->json([
            'progress' => [
                'title' => 'FF Logs Progress',
                ...$fetcher->fetchEncounterProgressForCharacter($character, $zoneId),
            ],
        ]);
    }
}
