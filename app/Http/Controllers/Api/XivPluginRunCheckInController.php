<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Services\Groups\ActivityManagementRealtimeService;
use App\Services\Groups\ActivitySlotAttendanceService;
use App\Services\Groups\ActivitySlotSerializer;
use App\Services\Groups\GroupActivityAuditService;
use App\Services\XivPlugin\XivPluginRunRealtimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class XivPluginRunCheckInController extends Controller
{
    public function store(
        Request $request,
        Activity $activity,
        XivPluginRunRealtimeService $realtimeService,
        ActivitySlotAttendanceService $attendanceService,
        GroupActivityAuditService $activityAuditService,
        ActivitySlotSerializer $slotSerializer,
        ActivityManagementRealtimeService $activityManagementRealtimeService,
    ): JsonResponse {
        $validated = $request->validate([
            'slot_ids' => ['sometimes', 'array', 'max:48'],
            'slot_ids.*' => ['integer', 'distinct', 'exists:activity_slots,id'],
            'character_ids' => ['sometimes', 'array', 'max:48'],
            'character_ids.*' => ['integer', 'distinct', 'exists:characters,id'],
        ]);

        $slotIds = collect($validated['slot_ids'] ?? [])->map(fn ($id): int => (int) $id)->unique()->values();
        $characterIds = collect($validated['character_ids'] ?? [])->map(fn ($id): int => (int) $id)->unique()->values();

        if ($slotIds->isEmpty() && $characterIds->isEmpty()) {
            throw ValidationException::withMessages([
                'slots' => 'Provide at least one slot id or character id to check in.',
            ]);
        }

        $user = $request->user();

        abort_unless($realtimeService->canCheckInPartyMembers($activity, $user), 403);

        $slots = $this->resolveSlots($activity, $slotIds, $characterIds);
        $this->assertAllRequestedTargetsResolved($slots, $slotIds, $characterIds);

        $checkedInSlots = $slots
            ->map(function (ActivitySlot $slot) use ($attendanceService, $activityAuditService, $user): ActivitySlot {
                $attendanceService->checkInSlot($slot, $user->id);
                $slot->load(['assignedCharacter', 'fieldValues', 'assignments']);

                $activityAuditService->logAttendanceEvent(
                    'checked_in',
                    $slot,
                    $user,
                    [
                        'checked_in_at' => now()->toIso8601String(),
                        'source' => 'xiv_plugin',
                    ],
                );

                return $slot;
            })
            ->values();

        $serializedSlots = $checkedInSlots
            ->map(fn (ActivitySlot $slot): array => $slotSerializer->serialize($slot))
            ->values()
            ->all();

        $activityManagementRealtimeService->broadcastPatch($activity, [
            'updated_slots' => $serializedSlots,
        ]);

        return new JsonResponse([
            'data' => [
                'run_id' => $activity->id,
                'checked_in_count' => count($serializedSlots),
                'slots' => $serializedSlots,
            ],
        ]);
    }

    /**
     * @param  Collection<int, int>  $slotIds
     * @param  Collection<int, int>  $characterIds
     * @return Collection<int, ActivitySlot>
     */
    private function resolveSlots(Activity $activity, Collection $slotIds, Collection $characterIds): Collection
    {
        return $activity->slots()
            ->with(['activity', 'assignedCharacter', 'fieldValues', 'assignments'])
            ->whereNotNull('assigned_character_id')
            ->where(function ($query) use ($slotIds, $characterIds): void {
                if ($slotIds->isNotEmpty()) {
                    $query->whereIn('id', $slotIds->all());
                }

                if ($characterIds->isNotEmpty()) {
                    $method = $slotIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                    $query->{$method}('assigned_character_id', $characterIds->all());
                }
            })
            ->orderBy('sort_order')
            ->get()
            ->unique('id')
            ->values();
    }

    /**
     * @param  Collection<int, ActivitySlot>  $slots
     * @param  Collection<int, int>  $slotIds
     * @param  Collection<int, int>  $characterIds
     */
    private function assertAllRequestedTargetsResolved(Collection $slots, Collection $slotIds, Collection $characterIds): void
    {
        $resolvedSlotIds = $slots->pluck('id')->map(fn ($id): int => (int) $id);
        $resolvedCharacterIds = $slots->pluck('assigned_character_id')->map(fn ($id): int => (int) $id);

        $missingSlotIds = $slotIds->diff($resolvedSlotIds)->values();
        $missingCharacterIds = $characterIds->diff($resolvedCharacterIds)->values();

        $errors = [];

        if ($missingSlotIds->isNotEmpty()) {
            $errors['slot_ids'] = 'One or more slots are not assigned on this run.';
        }

        if ($missingCharacterIds->isNotEmpty()) {
            $errors['character_ids'] = 'One or more characters are not assigned on this run.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
