<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\Group;
use App\Services\Runs\RunAccessService;
use Illuminate\Validation\ValidationException;

final class GuestSelfAssignmentService
{
    public function __construct(
        private readonly ActivityRosterLock $rosterLock,
        private readonly RunAccessService $access,
        private readonly GuestActivityCharacterService $characters,
        private readonly ActivitySlotAssignmentService $assignments,
        private readonly ActivitySlotFieldDefinitionBuilder $fields,
        private readonly ActivitySlotSerializer $slots,
        private readonly ActivityManagementRealtimeService $realtime,
    ) {}

    public function assign(Group $group, Activity $activity, ActivitySlot $slot, array $input): ActivityApplication
    {
        return $this->rosterLock->run((int) $activity->id, function () use ($group, $activity, $slot, $input): ActivityApplication {
            $activity->refresh();
            abort_unless($this->access->canSelfAssign($group, $activity, null)
                && (int) $slot->activity_id === (int) $activity->id, 404);
            $this->rosterLock->refreshSlot($slot, $input['expected_slot_state_token']);
            if ($slot->assigned_character_id !== null) {
                throw ValidationException::withMessages([
                    'slot' => __('errors.only_free_slots_can_be_self_assigned'),
                ]);
            }

            $applicant = $input['guest_applicant'];
            $character = $this->characters->resolve($applicant);
            if ($activity->applications()->where('applicant_lodestone_id', $character->lodestone_id)
                ->whereIn('status', ActivityApplication::ACTIVE_STATUSES)->exists()
                || $activity->slots()->where('assigned_character_id', $character->id)->exists()) {
                throw ValidationException::withMessages([
                    'guest_applicant.lodestone_id' => __('errors.this_character_is_already_assigned_to_another_slot_in_this_run'),
                ]);
            }

            // The registration retains the existing private guest-token lifecycle; no review queue is involved.
            $registration = $activity->applications()->create([
                'user_id' => null,
                'selected_character_id' => $character->id,
                'applicant_lodestone_id' => $character->lodestone_id,
                'applicant_character_name' => $character->name,
                'applicant_world' => $character->world,
                'applicant_datacenter' => $character->datacenter,
                'applicant_avatar_url' => $character->avatar_url,
                'guest_access_token' => ActivityApplication::generateGuestAccessToken(),
                'is_self_assignment' => true,
                'status' => ActivityApplication::STATUS_APPROVED,
                'submitted_at' => now(),
            ]);
            $definitions = collect($this->fields->build($activity->activityTypeVersion, $group->id))->keyBy('key')->all();
            $this->assignments->assignFromApplication(
                $slot, $registration, $input['field_values'] ?? [], $definitions, assignedByUserId: null,
            );
            $this->realtime->broadcastPatch($activity, [
                'updated_slots' => [$this->slots->serialize($slot->fresh(['assignedCharacter', 'fieldValues', 'assignments']))],
                'pending_application_count' => $activity->applications()->where('status', ActivityApplication::STATUS_PENDING)->count(),
            ]);

            return $registration->refresh();
        });
    }
}
