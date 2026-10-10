<?php

namespace App\Services\Notifications;

use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\Character;
use App\Models\User;
use App\Services\Groups\ActivitySlotKind;

class RunReminderContactPayloadBuilder
{
    public function __construct(private readonly ActivitySlotKind $slotKind) {}

    /** @return array{party_leads: array, run_host: ?array} */
    public function forRecipient(Activity $activity, int $userId): array
    {
        $activity->loadMissing([
            'slots.assignedCharacter.user.discordUserIntegration',
            'organizer.discordUserIntegration',
            'organizer.primaryCharacter',
            'organizerCharacter',
        ]);

        $partyKeys = $activity->slots
            ->filter(fn (ActivitySlot $slot): bool => $slot->assignedCharacter?->user_id === $userId)
            ->map(fn (ActivitySlot $slot): ?string => match (true) {
                $this->slotKind->isBench($slot) => null,
                $this->slotKind->isFillIn($slot) => $slot->filled_group_key,
                default => $slot->group_key,
            })
            ->filter(fn (?string $key): bool => filled($key))
            ->unique();

        $partyLeads = $activity->slots
            ->filter(fn (ActivitySlot $slot): bool => $this->slotKind->isMainRoster($slot)
                && ($slot->is_raid_leader || $slot->is_host)
                && $slot->assignedCharacter !== null
                && $partyKeys->contains($slot->group_key))
            ->map(fn (ActivitySlot $slot): array => $this->contact($slot->assignedCharacter->user, $slot->assignedCharacter))
            ->unique(fn (array $contact): string => $contact['user_id'] !== null
                ? 'user:'.$contact['user_id']
                : 'character:'.$contact['character_id'])
            ->values()
            ->all();

        return [
            'party_leads' => $partyLeads,
            'run_host' => $activity->organizer
                ? $this->contact($activity->organizer, $activity->organizerCharacter ?? $activity->organizer->primaryCharacter)
                : null,
        ];
    }

    /** @return array{user_id: ?int, discord_user_id: ?string, character_id: ?int, character_name: ?string, character_world: ?string} */
    private function contact(?User $user, ?Character $character): array
    {
        $integration = $user?->discordUserIntegration;

        return [
            'user_id' => $user?->id,
            'discord_user_id' => $integration?->user_app_installed_at !== null && filled($integration?->discord_user_id)
                ? $integration->discord_user_id
                : null,
            'character_id' => $character?->id,
            'character_name' => $character?->name,
            'character_world' => $character?->world,
        ];
    }
}
