<?php

namespace App\Http\Controllers;

use App\Http\Requests\GuestSelfAssignmentRequest;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\Group;
use App\Services\Groups\ActivityApplicationWithdrawalService;
use App\Services\Groups\ActivitySlotSerializer;
use App\Services\Groups\GuestSelfAssignmentService;
use App\Services\Runs\RunAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GuestActivitySelfAssignmentController extends Controller
{
    public function __construct(
        private readonly RunAccessService $access,
        private readonly ActivityApplicationWithdrawalService $withdrawals,
        private readonly ActivitySlotSerializer $slots,
    ) {}

    public function store(GuestSelfAssignmentRequest $request, Group $group, Activity $activity, ActivitySlot $slot, GuestSelfAssignmentService $service): JsonResponse
    {
        $registration = $service->assign($group, $activity, $slot, $request->validated());

        return response()->json([
            ...$this->present($registration),
            'guest_access_token' => $registration->guest_access_token,
            'manage_url' => route('groups.activities.guest-self-assignments.show', [
                'group' => $group, 'activity' => $activity, 'accessToken' => $registration->guest_access_token,
            ]),
        ], 201, ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function show(Request $request, Group $group, Activity $activity, string $accessToken): JsonResponse
    {
        return response()->json($this->present($this->find($request, $group, $activity, $accessToken)),
            headers: ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function destroy(Request $request, Group $group, Activity $activity, string $accessToken): JsonResponse
    {
        $registration = $this->find($request, $group, $activity, $accessToken);

        return response()->json($this->withdrawals->withdraw($registration, null),
            headers: ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    private function find(Request $request, Group $group, Activity $activity, string $accessToken): ActivityApplication
    {
        abort_unless($this->access->canParticipate($group, $activity, null)
            && $this->access->canView($group, $activity, $request->user()?->id), 404);

        return $activity->applications()->whereNull('user_id')->where('is_self_assignment', true)
            ->where('guest_access_token', $accessToken)->whereIn('status', ActivityApplication::ACTIVE_STATUSES)->firstOrFail();
    }

    private function present(ActivityApplication $registration): array
    {
        $slot = $registration->activity->slots()->with(['assignedCharacter', 'fieldValues', 'assignments'])
            ->where('assigned_character_id', $registration->selected_character_id)
            ->whereHas('assignments', fn ($query) => $query->where('application_id', $registration->id)->whereNull('ended_at'))
            ->first();

        return [
            'registration_id' => $registration->id,
            'status' => $registration->status,
            'can_withdraw' => $this->withdrawals->applicationCanBeWithdrawn($registration->activity, $registration),
            'slot' => $slot ? $this->slots->serialize($slot) : null,
        ];
    }
}
