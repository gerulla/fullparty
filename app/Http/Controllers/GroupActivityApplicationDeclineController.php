<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\Group;
use App\Services\Groups\ActivityApplicationReviewSlotStateService;
use App\Services\Groups\ActivityManagementRealtimeService;
use App\Services\Groups\ApplicantQueue\ApplicantQueuePayloadBuilder;
use App\Services\Groups\GroupActivityAuditService;
use App\Services\Notifications\ApplicationNotificationService;
use App\Support\Input\RequestTextInputSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GroupActivityApplicationDeclineController extends Controller
{
    public function store(
        Request $request,
        Group $group,
        Activity $activity,
        ActivityApplication $application,
        RequestTextInputSanitizer $requestTextInputSanitizer,
        GroupActivityAuditService $activityAuditService,
        ActivityApplicationReviewSlotStateService $applicationReviewSlotStateService,
        ApplicantQueuePayloadBuilder $queuePayloadBuilder,
        ApplicationNotificationService $applicationNotificationService,
        ActivityManagementRealtimeService $activityManagementRealtimeService,
    ): JsonResponse {
        $this->authorize('manageDashboard', [$activity, $group]);

        if ((int) $application->activity_id !== (int) $activity->id) {
            abort(404);
        }

        if ($activity->isArchived()) {
            throw ValidationException::withMessages([
                'activity' => 'Archived activities cannot review applications.',
            ]);
        }

        if ($application->status !== ActivityApplication::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'application' => 'Only pending applications can be declined.',
            ]);
        }

        $requestTextInputSanitizer->sanitize($request, [], ['reason']);

        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:'.ActivityApplication::REVIEW_REASON_MAX_LENGTH],
        ]);

        $releasedAssignments = [
            'updated_slots' => [],
            'removed_slot_ids' => [],
        ];

        DB::transaction(function () use (
            $activity,
            $application,
            $validated,
            $request,
            $activityAuditService,
            $applicationReviewSlotStateService,
            &$releasedAssignments,
        ): void {
            $releasedAssignments = $applicationReviewSlotStateService->releaseFlaggedAssignmentsForApplication(
                $activity,
                $application,
                $request->user(),
            );

            $application->update([
                'status' => ActivityApplication::STATUS_DECLINED,
                'guest_access_token' => null,
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
                'review_reason' => filled($validated['reason'] ?? null)
                    ? trim((string) $validated['reason'])
                    : null,
            ]);

            $application->loadMissing(['activity.group', 'selectedCharacter', 'user']);
            $activityAuditService->logApplicationDeclined($application, $request->user());
        });

        $applicationNotificationService->notifyDeclined(
            $application->fresh(['activity.group', 'selectedCharacter', 'user']),
            $request->user(),
        );

        $serializedApplication = $queuePayloadBuilder->serializeApplicationForModerator(
            $application->fresh(['answers', 'selectedCharacter.occultProgress', 'selectedCharacter.phantomJobs', 'user']),
            $activity->activityTypeVersion,
            $activity->group,
            (int) $request->user()->id,
        );
        $pendingApplicationCount = $activity->applications()
            ->where('status', ActivityApplication::STATUS_PENDING)
            ->count();

        $patch = [
            'pending_application_count' => $pendingApplicationCount,
            'queue_application_sync_ids' => [],
            'queue_application_remove_ids' => [(int) $application->id],
        ];

        if ($releasedAssignments['updated_slots'] !== []) {
            $patch['updated_slots'] = $releasedAssignments['updated_slots'];
        }

        if ($releasedAssignments['removed_slot_ids'] !== []) {
            $patch['removed_slot_ids'] = $releasedAssignments['removed_slot_ids'];
        }

        $activityManagementRealtimeService->broadcastPatch($activity, $patch);

        return response()->json([
            'application' => $serializedApplication,
            'pending_application_count' => $pendingApplicationCount,
            'removed_slot_ids' => $releasedAssignments['removed_slot_ids'],
        ]);
    }
}
