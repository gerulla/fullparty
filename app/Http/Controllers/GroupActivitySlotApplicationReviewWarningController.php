<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\Group;
use App\Services\Groups\ActivityManagementRealtimeService;
use App\Services\Groups\ActivitySlotBench;
use App\Services\Groups\ActivitySlotSerializer;
use App\Services\Groups\ActivitySlotStateTokenService;
use App\Services\Groups\GroupActivityAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GroupActivitySlotApplicationReviewWarningController extends Controller
{
    public function store(
        Request $request,
        Group $group,
        Activity $activity,
        ActivitySlot $slot,
        ActivitySlotStateTokenService $slotStateTokenService,
        ActivitySlotBench $slotBench,
        ActivitySlotSerializer $slotSerializer,
        GroupActivityAuditService $activityAuditService,
        ActivityManagementRealtimeService $activityManagementRealtimeService,
    ): JsonResponse {
        $this->authorize('manageDashboard', [$activity, $group]);

        if ($activity->isArchived()) {
            throw ValidationException::withMessages([
                'activity' => 'Archived activities cannot review roster warnings.',
            ]);
        }

        if ((int) $slot->activity_id !== (int) $activity->id) {
            abort(404);
        }

        $validated = $request->validate([
            'expected_slot_state_token' => ['required', 'string'],
        ]);

        $slot->load(['activity', 'assignedCharacter', 'fieldValues', 'assignments']);
        $slotStateTokenService->assertMatches($slot, $validated['expected_slot_state_token']);

        if (! $slot->assigned_character_id || ! $slot->application_review_required_application_id) {
            throw ValidationException::withMessages([
                'slot' => 'This slot does not have an application warning to clear.',
            ]);
        }

        /** @var ActivityApplication|null $application */
        $application = $activity->applications()
            ->whereKey($slot->application_review_required_application_id)
            ->first();

        if (! $application || ! in_array($application->status, [
            ActivityApplication::STATUS_PENDING,
            ActivityApplication::STATUS_APPROVED,
            ActivityApplication::STATUS_ON_BENCH,
        ], true)) {
            throw ValidationException::withMessages([
                'slot' => 'This application warning can no longer be cleared.',
            ]);
        }

        $wasPending = $application->status === ActivityApplication::STATUS_PENDING;

        if ((int) $application->selected_character_id !== (int) $slot->assigned_character_id) {
            throw ValidationException::withMessages([
                'slot' => 'The edited application selected a different character. Change the assignment or decline the application instead.',
            ]);
        }

        $reviewedStatus = $slotBench->isBench($slot)
            ? ActivityApplication::STATUS_ON_BENCH
            : ActivityApplication::STATUS_APPROVED;

        DB::transaction(function () use ($slot, $application, $request, $reviewedStatus, $activityAuditService): void {
            $slot->update([
                'application_review_required_application_id' => null,
                'application_review_required_at' => null,
            ]);

            $application->update([
                'status' => $reviewedStatus,
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
                'review_reason' => null,
            ]);

            $slot->load(['activity.group', 'assignedCharacter']);
            $activityAuditService->logRosterEvent(
                'updated',
                $slot,
                $request->user(),
                [
                    'application_status' => $reviewedStatus,
                    'application_review_warning_cleared' => true,
                ],
            );
        });

        $slot->load(['assignedCharacter', 'fieldValues', 'assignments']);
        $serializedSlot = $slotSerializer->serialize($slot);
        $pendingApplicationCount = $activity->applications()
            ->where('status', ActivityApplication::STATUS_PENDING)
            ->count();
        $removedQueueApplicationIds = $wasPending ? [(int) $application->id] : [];

        $activityManagementRealtimeService->broadcastPatch($activity, [
            'updated_slots' => [$serializedSlot],
            'pending_application_count' => $pendingApplicationCount,
            'queue_application_remove_ids' => $removedQueueApplicationIds,
        ]);

        return response()->json([
            'slot' => $serializedSlot,
            'slots' => [$serializedSlot],
            'application_status' => $reviewedStatus,
            'pending_application_count' => $pendingApplicationCount,
            'queue_application_remove_ids' => $removedQueueApplicationIds,
        ]);
    }
}
