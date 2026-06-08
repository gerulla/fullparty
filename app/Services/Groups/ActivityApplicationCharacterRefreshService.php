<?php

namespace App\Services\Groups;

use App\Models\ActivityApplication;
use App\Models\Character;
use App\Services\Characters\CharacterProfileRefreshService;
use Carbon\CarbonInterface;

class ActivityApplicationCharacterRefreshService
{
    public function __construct(
        private readonly CharacterProfileRefreshService $characterProfileRefreshService,
    ) {}

    /**
     * @return array{refreshed: bool, available_at: CarbonInterface|null, character: Character|null, fflogs_error: array<string, mixed>|null}
     */
    public function refreshSelectedCharacterIfDue(ActivityApplication $application, int $cooldownSeconds): array
    {
        $application->loadMissing('selectedCharacter');

        if (! $application->selectedCharacter instanceof Character) {
            return [
                'refreshed' => false,
                'available_at' => null,
                'character' => null,
                'fflogs_error' => null,
            ];
        }

        $result = $this->characterProfileRefreshService->refreshIfOlderThan(
            $application->selectedCharacter,
            $cooldownSeconds,
        );

        $character = $application->selectedCharacter->fresh();
        $this->syncApplicantSnapshot($application, $character);

        return [
            'refreshed' => $result['refreshed'],
            'available_at' => $result['available_at'],
            'character' => $character,
            'fflogs_error' => $result['fflogs_error'],
        ];
    }

    private function syncApplicantSnapshot(ActivityApplication $application, Character $character): void
    {
        $application->forceFill([
            'applicant_lodestone_id' => $character->lodestone_id,
            'applicant_character_name' => $character->name,
            'applicant_world' => $character->world,
            'applicant_datacenter' => $character->datacenter,
            'applicant_avatar_url' => $character->avatar_url,
        ])->save();
    }
}
