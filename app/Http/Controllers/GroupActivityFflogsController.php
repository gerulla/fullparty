<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityApplication;
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

    public function showForApplication(
        Group $group,
        Activity $activity,
        ActivityApplication $application,
        CharacterZoneProgressFetcher $fetcher,
    ): JsonResponse {
        $this->authorize('manageDashboard', [$activity, $group]);

        if ((int) $application->activity_id !== (int) $activity->id) {
            abort(404);
        }

        $activity->loadMissing('activityTypeVersion');

        $zoneId = (int) ($activity->activityTypeVersion?->fflogs_zone_id ?? 0);

        if ($zoneId <= 0) {
            return response()->json([
                'progress' => null,
            ]);
        }

        if ($application->user_id !== null && $application->selectedCharacter) {
            return response()->json([
                'progress' => [
                    'title' => 'FF Logs Progress',
                    ...$fetcher->fetchEncounterProgressForCharacter($application->selectedCharacter, $zoneId),
                ],
            ]);
        }

        if (
            blank($application->applicant_character_name)
            || blank($application->applicant_world)
            || blank($application->applicant_datacenter)
        ) {
            return response()->json([
                'progress' => null,
            ]);
        }

        return response()->json([
            'progress' => [
                'title' => 'FF Logs Progress',
                ...$fetcher->fetchEncounterProgressForIdentity(
                    $application->applicant_character_name,
                    $application->applicant_world,
                    $application->applicant_datacenter,
                    $application->applicant_lodestone_id,
                    $zoneId,
                ),
            ],
        ]);
    }
}
