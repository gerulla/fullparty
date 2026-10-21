<?php

namespace App\Services\Characters;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\Character;
use App\Models\CharacterTransfer;
use App\Models\CharacterTransferRun;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Groups\ActivityManagementRealtimeService;
use App\Services\Groups\ActivityManagementWarningService;
use App\Services\Groups\ActivitySlotAttendanceService;
use App\Services\Groups\ActivitySlotDesignationService;
use App\Services\Notifications\ApplicationNotificationService;
use App\Services\Notifications\AssignmentNotificationService;
use App\Services\Runs\RunAccessService;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CharacterTransferRunService
{
    public function __construct(
        private readonly ActivitySlotAttendanceService $attendance,
        private readonly ActivitySlotDesignationService $designations,
        private readonly ApplicationNotificationService $applications,
        private readonly AssignmentNotificationService $assignments,
        private readonly ActivityManagementRealtimeService $realtime,
        private readonly ActivityManagementWarningService $warnings,
        private readonly AuditLogger $audit,
        private readonly RunAccessService $access,
    ) {}

    /** Caller holds the receiving account, character, transfer and activity locks. */
    public function apply(CharacterTransferRun $run, CharacterTransfer $transfer, Character $character, User $user, string $choice): void
    {
        $activity = Activity::findOrFail($run->activity_id);
        // A delayed decision must never rewrite a completed or already-started roster.
        if ($activity->isArchived() || $activity->is_completed || $activity->starts_at?->isPast()) {
            return;
        }
        $application = ActivityApplication::whereKey($run->application_id)
            ->where('user_id', $transfer->from_user_id)->where('selected_character_id', $character->id)->lockForUpdate()->first();
        $activeApplication = $application && in_array($application->status, ActivityApplication::ACTIVE_STATUSES, true);
        // Follow an existing assignment if a moderator moved it, but never reclaim a replaced assignment.
        $activeAssignments = $activity->slotAssignments()->whereIn('id', $run->assignment_ids)
            ->where('character_id', $character->id)->whereNull('ended_at')->get();
        $slots = $activity->slots()->where('assigned_character_id', $character->id)
            ->where(function ($query) use ($activeAssignments, $run) {
                $query->whereIn('id', $activeAssignments->pluck('activity_slot_id'))
                    ->orWhere(fn ($legacy) => $legacy->whereIn('id', $run->slot_ids)->whereDoesntHave('assignments'));
            })->get();
        if (! $activeApplication && $slots->isEmpty()) {
            return;
        }
        if ($choice === 'keep') {
            if (! $this->access->canParticipate($activity->group, $activity, $user->id)) {
                throw ValidationException::withMessages(['transfer' => 'ineligible']);
            }
            if ($activity->applications()->where('user_id', $user->id)->where('status', '!=', ActivityApplication::STATUS_WITHDRAWN)->exists()
                || $activity->slots()->where('assigned_character_id', '!=', $character->id)
                    ->whereHas('assignedCharacter', fn ($query) => $query->where('user_id', $user->id))->exists()) {
                throw ValidationException::withMessages(['transfer' => 'duplicate']);
            }
        }

        $replacement = null;
        if ($activeApplication) {
            $status = $application->status;
            // Keep the old account's answers, notes, review and timestamps on its original row.
            $application->update(['status' => ActivityApplication::STATUS_WITHDRAWN, 'guest_access_token' => null]);
            if ($choice === 'keep') {
                $replacement = ActivityApplication::create([
                    'activity_id' => $activity->id, 'user_id' => $user->id, 'selected_character_id' => $character->id,
                    'applicant_lodestone_id' => $character->lodestone_id, 'applicant_character_name' => $character->name,
                    'applicant_world' => $character->world, 'applicant_datacenter' => $character->datacenter,
                    'applicant_avatar_url' => $character->avatar_url, 'status' => $status,
                    'submitted_at' => $application->submitted_at,
                ]);
                $replacement->forceFill(['created_at' => $application->created_at])->save();
            }
        }
        foreach ($slots as $slot) {
            // Finish the old account's assignment record; a kept place gets a fresh attribution.
            $this->attendance->endActiveAssignment($activity, $character->id);
            $slot->update(['application_review_required_application_id' => null, 'application_review_required_at' => null]);
            if ($choice === 'keep') {
                $this->attendance->moveOrCreateActiveAssignment($slot, $character->id, $replacement?->id,
                    $slot->assigned_by_user_id, $this->attendance->buildFieldValueSnapshot($slot));
            } else {
                if ($activeApplication && $slot->is_raid_leader) {
                    $this->warnings->createRaidLeaderWithdrawal($application, $slot);
                }
                $slot->update(['assigned_character_id' => null, 'assigned_by_user_id' => null]);
                $slot->fieldValues()->update(['value' => null]);
                $this->designations->clearInvalidDesignations([$slot], $user);
            }
        }
        $this->audit->log(
            action: 'group.activity.character_transfer_completed', severity: AuditSeverity::MODERATION_CHANGE,
            scopeType: AuditScope::GROUP, scopeId: $activity->group_id,
            message: 'characters.transfer.audit_completed', actor: $user, subject: $activity,
            metadata: [
                'activity_id' => $activity->id, 'activity_title' => $activity->title, 'character_name' => $character->name,
                'transfer_id' => $transfer->id, 'from_user_id' => $transfer->from_user_id, 'to_user_id' => $user->id,
                'upcoming_choice' => $choice, 'original_application_id' => $application?->id,
                'new_application_id' => $replacement?->id, 'slot_ids' => $slots->modelKeys(),
            ],
        );
        // Deliver only after the entire choice commits; retries must not send duplicate notifications.
        DB::afterCommit(function () use ($activity, $character, $application, $replacement, $slots, $choice, $user, $activeApplication) {
            if ($choice === 'discard' && $activeApplication) {
                $this->applications->notifyWithdrawn($application->fresh(), $user);
            } elseif ($choice === 'discard' && $slots->isNotEmpty()) {
                $this->assignments->notifyManualPlacementRemoved($activity, $character, null, $user);
            } elseif ($choice === 'keep' && $slots->isNotEmpty()) {
                if ($replacement) {
                    $this->assignments->notifyPlacementChanged($replacement, $slots->first()->fresh(), $user);
                } else {
                    $this->assignments->notifyManualPlacementChanged($activity, $character, $slots->first()->fresh(), $user);
                }
            }
            $this->realtime->broadcastPatch($activity, ['type' => 'reload', 'reason' => 'character_transfer']);
        });
    }
}
