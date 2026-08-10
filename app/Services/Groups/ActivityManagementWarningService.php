<?php

namespace App\Services\Groups;

use App\Models\ActivityApplication;
use App\Models\ActivityManagementWarning;
use App\Models\ActivitySlot;
use App\Models\User;

class ActivityManagementWarningService
{
    public function createRaidLeaderWithdrawal(
        ActivityApplication $application,
        ActivitySlot $slot,
    ): ActivityManagementWarning {
        $slot->loadMissing('assignedCharacter');

        $characterName = $slot->assignedCharacter?->name
            ?: $application->applicant_character_name
            ?: $application->selectedCharacter?->name
            ?: $application->user?->name
            ?: 'Applicant';

        return ActivityManagementWarning::query()->create([
            'activity_id' => $application->activity_id,
            'type' => ActivityManagementWarning::TYPE_RAID_LEADER_WITHDRAWN,
            'severity' => ActivityManagementWarning::SEVERITY_ERROR,
            'payload' => [
                'application_id' => (int) $application->id,
                'user_id' => $application->user_id ? (int) $application->user_id : null,
                'character_id' => $slot->assigned_character_id ? (int) $slot->assigned_character_id : null,
                'character_name' => $characterName,
                'slot_id' => (int) $slot->id,
                'slot_key' => $slot->slot_key,
                'slot_label' => $slot->slot_label,
                'group_key' => $slot->group_key,
                'group_label' => $slot->group_label,
            ],
            'occurred_at' => now(),
        ]);
    }

    public function dismiss(ActivityManagementWarning $warning, User $user): ActivityManagementWarning
    {
        if ($warning->dismissed_at === null) {
            $warning->update([
                'dismissed_by_user_id' => $user->id,
                'dismissed_at' => now(),
            ]);
        }

        return $warning->refresh();
    }
}
