<?php

namespace App\Http\Controllers;

use App\Exceptions\LodestoneFetchException;
use App\Exceptions\LodestoneInvalidInputException;
use App\Exceptions\LodestoneParseException;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\Group;
use App\Services\Groups\ActivityApplicationCharacterRefreshService;
use App\Services\Groups\ApplicantQueue\ApplicantQueuePayloadBuilder;
use Illuminate\Http\JsonResponse;

class GroupActivityApplicantQueueController extends Controller
{
    private const MODERATOR_CHARACTER_REFRESH_COOLDOWN_SECONDS = 300;

    public function show(Group $group, Activity $activity, ApplicantQueuePayloadBuilder $payloadBuilder): JsonResponse
    {
        $this->authorize('manageDashboard', [$activity, $group]);

        $activity->load([
            'group.memberships',
            'activityTypeVersion',
            'applications' => fn ($query) => $query
                ->where('status', 'pending')
                ->with([
                    'user',
                    'selectedCharacter.occultProgress',
                    'selectedCharacter.phantomJobs',
                    'answers',
                ]),
        ]);

        return response()->json($payloadBuilder->build($activity, auth()->id()));
    }

    public function showApplication(
        Group $group,
        Activity $activity,
        ActivityApplication $application,
        ApplicantQueuePayloadBuilder $payloadBuilder,
    ): JsonResponse {
        $this->authorize('manageDashboard', [$activity, $group]);

        if ((int) $application->activity_id !== (int) $activity->id) {
            abort(404);
        }

        $application->load([
            'activity.group',
            'answers',
            'selectedCharacter.occultProgress',
            'selectedCharacter.phantomJobs',
            'user',
        ]);

        return response()->json([
            'application' => $payloadBuilder->serializeApplicationForModerator(
                $application,
                $activity->activityTypeVersion,
                $activity->group,
                (int) auth()->id(),
            ),
        ]);
    }

    public function refreshApplicationCharacter(
        Group $group,
        Activity $activity,
        ActivityApplication $application,
        ActivityApplicationCharacterRefreshService $applicationCharacterRefreshService,
        ApplicantQueuePayloadBuilder $payloadBuilder,
    ): JsonResponse {
        $this->authorize('manageDashboard', [$activity, $group]);

        if ((int) $application->activity_id !== (int) $activity->id) {
            abort(404);
        }

        if ($application->selected_character_id === null) {
            return response()->json([
                'message' => __('groups.activities.management.queue.modal.character_refresh_unavailable'),
            ], 422);
        }

        try {
            $refreshResult = $applicationCharacterRefreshService->refreshSelectedCharacterIfDue(
                $application,
                self::MODERATOR_CHARACTER_REFRESH_COOLDOWN_SECONDS,
            );
        } catch (LodestoneInvalidInputException|LodestoneParseException $exception) {
            return response()->json([
                'message' => __('groups.activities.management.queue.modal.character_refresh_failed'),
            ], 422);
        } catch (LodestoneFetchException $exception) {
            return response()->json([
                'message' => $exception->getCode() === 404
                    ? __('groups.activities.management.queue.modal.character_refresh_not_found')
                    : __('groups.activities.management.queue.modal.character_refresh_failed'),
            ], 422);
        }

        if (! $refreshResult['refreshed']) {
            return response()->json([
                'message' => __('groups.activities.management.queue.modal.character_refresh_cooldown'),
                'refresh_available_at' => $refreshResult['available_at']?->toIso8601String(),
            ], 429);
        }

        $application = $application->fresh([
            'activity.group',
            'answers',
            'selectedCharacter.occultProgress',
            'selectedCharacter.phantomJobs',
            'user',
        ]);

        return response()->json([
            'application' => $payloadBuilder->serializeApplicationForModerator(
                $application,
                $activity->activityTypeVersion,
                $activity->group,
                (int) auth()->id(),
            ),
            'lodestone_refreshed_at' => $refreshResult['character']?->lodestone_refreshed_at?->toIso8601String(),
            'refresh_available_at' => $refreshResult['available_at']?->toIso8601String(),
            'fflogs_error' => $refreshResult['fflogs_error'],
        ]);
    }
}
