<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\Group;
use App\Services\Groups\ActivityManagementRealtimeService;
use App\Services\Groups\ApplicantQueue\ApplicantQueuePayloadBuilder;
use App\Services\Groups\GroupActivityAuditService;
use App\Services\Notifications\ApplicationNotificationService;
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
        GroupActivityAuditService $activityAuditService,
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

        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($application, $validated, $request, $activityAuditService): void {
            $application->update([
                'status' => ActivityApplication::STATUS_DECLINED,
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

        $activityManagementRealtimeService->broadcastPatch($activity, [
            'pending_application_count' => $pendingApplicationCount,
            'queue_application_sync_ids' => [],
            'queue_application_remove_ids' => [(int) $application->id],
        ]);

        return response()->json([
            'application' => $serializedApplication,
            'pending_application_count' => $pendingApplicationCount,
        ]);
    }
}
