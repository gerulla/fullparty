<?php

namespace App\Services\Characters;

use App\Models\ActivityApplication;
use App\Models\Character;
use App\Models\User;
use Illuminate\Support\Facades\Log;

final class CharacterApplicationClaimService
{
    /** Called inside the character claim transaction. */
    public function claim(Character $character, User $user): void
    {
        $applications = ActivityApplication::query()
            ->whereNull('user_id')
            ->where('applicant_lodestone_id', $character->lodestone_id)
            ->lockForUpdate()
            ->get();

        if ($applications->isEmpty()) {
            return;
        }

        $conflictingActivityIds = ActivityApplication::query()
            ->where('user_id', $user->id)
            ->whereIn('activity_id', $applications->pluck('activity_id'))
            ->pluck('activity_id')
            ->all();

        foreach ($applications as $application) {
            if (in_array($application->activity_id, $conflictingActivityIds, true)) {
                Log::warning('Skipping guest application claim because the user already has an application for the activity.', [
                    'application_id' => $application->id,
                    'activity_id' => $application->activity_id,
                    'user_id' => $user->id,
                    'character_id' => $character->id,
                    'lodestone_id' => $character->lodestone_id,
                ]);

                continue;
            }

            $application->update([
                'user_id' => $user->id,
                'selected_character_id' => $character->id,
                'guest_access_token' => null,
            ]);
            $conflictingActivityIds[] = $application->activity_id;
        }
    }
}
