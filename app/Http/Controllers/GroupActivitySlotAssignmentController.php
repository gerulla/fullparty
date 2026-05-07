<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\Character;
use App\Models\Group;
use App\Services\Groups\ActivityManagementRealtimeService;
use App\Services\Groups\ActivitySlotAssignmentService;
use App\Services\Groups\ActivitySlotFieldDefinitionBuilder;
use App\Services\Groups\ActivitySlotSerializer;
use App\Services\Groups\ActivitySlotStateTokenService;
use App\Services\Groups\ApplicantQueue\ApplicantQueuePayloadBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GroupActivitySlotAssignmentController extends Controller
{
    public function store(
        Request $request,
        Group $group,
        Activity $activity,
        ActivitySlot $slot,
        ActivitySlotFieldDefinitionBuilder $fieldDefinitionBuilder,
        ActivitySlotAssignmentService $slotAssignmentService,
        ActivitySlotSerializer $slotSerializer,
        ActivitySlotStateTokenService $slotStateTokenService,
        ApplicantQueuePayloadBuilder $queuePayloadBuilder,
        ActivityManagementRealtimeService $activityManagementRealtimeService,
    ): JsonResponse {
        $this->authorize('manageDashboard', [$activity, $group]);

        if ($activity->isArchived()) {
            abort(403);
        }

        if ((int) $slot->activity_id !== (int) $activity->id) {
            abort(404);
        }

        $validated = $request->validate([
            'application_id' => ['sometimes', 'nullable', 'integer', 'required_without:character_id'],
            'character_id' => ['sometimes', 'nullable', 'integer', 'required_without:application_id'],
            'field_values' => ['sometimes', 'array'],
            'source_slot_id' => ['sometimes', 'nullable', 'integer'],
            'expected_slot_state_token' => ['required', 'string'],
            'expected_source_slot_state_token' => ['sometimes', 'nullable', 'string'],
        ]);

        $sourceSlot = null;

        if (!empty($validated['source_slot_id'])) {
            $sourceSlot = $activity->slots()
                ->with(['assignedCharacter', 'fieldValues', 'activity', 'assignments'])
                ->find((int) $validated['source_slot_id']);

            if (!$sourceSlot) {
                abort(404);
            }
        }

        $fieldDefinitions = collect($fieldDefinitionBuilder->build($activity->activityTypeVersion))
            ->keyBy(fn (array $definition) => (string) $definition['key'])
            ->all();

        $slot->load(['assignedCharacter', 'fieldValues', 'activity', 'assignments']);
        $slotStateTokenService->assertMatches($slot, $validated['expected_slot_state_token']);

        if ($sourceSlot) {
            $slotStateTokenService->assertMatches($sourceSlot, $validated['expected_source_slot_state_token'] ?? null);
        }

        $targetPreviousCharacterId = $slot->assigned_character_id;
        $removedQueueApplicationIds = [];
        $restoredQueueApplication = null;
        $queueApplicationSyncIds = [];

        if (!empty($validated['character_id'])) {
            $groupMemberUserIds = $group->memberships()
                ->pluck('user_id')
                ->push($group->owner_id)
                ->filter()
                ->unique()
                ->values();

            /** @var Character|null $character */
            $character = Character::query()
                ->with(['user', 'classes', 'phantomJobs'])
                ->whereNotNull('verified_at')
                ->whereIn('user_id', $groupMemberUserIds)
                ->find((int) $validated['character_id']);

            if (!$character) {
                abort(404);
            }

            $slotAssignmentService->assignManualCharacter(
                $slot,
                $character,
                $validated['field_values'] ?? [],
                $fieldDefinitions,
                (int) $request->user()->id,
                $sourceSlot,
            );
        } else {
            /** @var ActivityApplication|null $application */
            $application = $activity->applications()
                ->with(['answers', 'selectedCharacter'])
                ->find((int) $validated['application_id']);

            if (!$application) {
                abort(404);
            }

            $isAllowedStatus = $application->status === ActivityApplication::STATUS_PENDING
                || (
                    $application->status === ActivityApplication::STATUS_APPROVED
                    && (int) $application->selected_character_id === (int) $slot->assigned_character_id
                )
                || (
                    $application->status === ActivityApplication::STATUS_ON_BENCH
                    && $sourceSlot !== null
                );

            if (!$isAllowedStatus) {
                abort(404);
            }

            $applicationFieldDefinitions = collect($fieldDefinitions)
                ->filter(fn (array $definition) => filled($definition['application_key'] ?? null))
                ->all();
            $wasPendingQueueApplication = $sourceSlot === null
                && $application->status === ActivityApplication::STATUS_PENDING;

            $slotAssignmentService->assignFromApplication(
                $slot,
                $application,
                $validated['field_values'] ?? [],
                $applicationFieldDefinitions,
                (int) $request->user()->id,
                $sourceSlot,
            );

            if ($wasPendingQueueApplication) {
                $removedQueueApplicationIds[] = (int) $application->id;
            }
        }

        $slot->load(['assignedCharacter', 'fieldValues', 'assignments']);
        $updatedSlots = [$slotSerializer->serialize($slot)];

        if ($sourceSlot) {
            $sourceSlot->load(['assignedCharacter', 'fieldValues', 'assignments']);
            $updatedSlots[] = $slotSerializer->serialize($sourceSlot);
        }

        if (
            empty($validated['character_id'])
            && $sourceSlot === null
            && $targetPreviousCharacterId !== null
            && (int) $targetPreviousCharacterId !== (int) $slot->assigned_character_id
        ) {
            $displacedApplication = $activity->applications()
                ->with(['answers', 'selectedCharacter.occultProgress', 'selectedCharacter.phantomJobs', 'user'])
                ->where('selected_character_id', $targetPreviousCharacterId)
                ->where('status', ActivityApplication::STATUS_PENDING)
                ->latest('submitted_at')
                ->first();

            if ($displacedApplication) {
                $restoredQueueApplication = $queuePayloadBuilder->serializeApplicationForModerator(
                    $displacedApplication,
                    $activity->activityTypeVersion,
                    $activity->group,
                    (int) $request->user()->id,
                );
                $queueApplicationSyncIds[] = (int) $displacedApplication->id;
            }
        }

        $pendingApplicationCount = $activity->applications()
            ->where('status', ActivityApplication::STATUS_PENDING)
            ->count();

        $activityManagementRealtimeService->broadcastPatch($activity, [
            'updated_slots' => $updatedSlots,
            'pending_application_count' => $pendingApplicationCount,
            'queue_application_sync_ids' => array_values(array_unique($queueApplicationSyncIds)),
            'queue_application_remove_ids' => array_values(array_unique($removedQueueApplicationIds)),
        ]);

        return response()->json([
            'slot' => $slotSerializer->serialize($slot),
            'slots' => $updatedSlots,
            'pending_application_count' => $pendingApplicationCount,
            'queue_application_remove_ids' => array_values(array_unique($removedQueueApplicationIds)),
            'restored_queue_application' => $restoredQueueApplication,
        ]);
    }
}
