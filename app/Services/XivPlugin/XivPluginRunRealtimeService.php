<?php

namespace App\Services\XivPlugin;

use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\Character;
use App\Models\User;
use App\Services\Groups\ActivitySlotBench;
use Illuminate\Support\Collection;

class XivPluginRunRealtimeService
{
    public function __construct(
        private readonly ActivitySlotBench $slotBench,
    ) {}

    public function loadRealtimeRelations(Activity $activity): Activity
    {
        return $activity->loadMissing([
            'group.memberships',
            'slots.assignedCharacter.user',
        ]);
    }

    public function canAccessRun(Activity $activity, User $user): bool
    {
        $this->loadRealtimeRelations($activity);

        if (! $this->runCanUseRealtime($activity)) {
            return false;
        }

        if ($activity->status === Activity::STATUS_DRAFT) {
            return $activity->group->hasModeratorAccess($user->id);
        }

        if ($activity->group->hasMember($user->id)) {
            return true;
        }

        return $this->assignedSlotsForUser($activity, $user)->isNotEmpty();
    }

    public function canIssueCommand(Activity $activity, User $user): bool
    {
        $this->loadRealtimeRelations($activity);

        if (! $this->canAccessRun($activity, $user)) {
            return false;
        }

        if ($activity->group->hasModeratorAccess($user->id)) {
            return true;
        }

        return $this->assignedSlotsForUser($activity, $user)
            ->contains(fn (ActivitySlot $slot): bool => $slot->is_host);
    }

    public function canPublishPartySnapshot(Activity $activity, User $user): bool
    {
        $this->loadRealtimeRelations($activity);

        if (! $this->canAccessRun($activity, $user)) {
            return false;
        }

        if ($activity->group->hasModeratorAccess($user->id)) {
            return true;
        }

        return $this->assignedSlotsForUser($activity, $user)
            ->contains(fn (ActivitySlot $slot): bool => $slot->is_host || $slot->is_raid_leader);
    }

    public function partyKeyExists(Activity $activity, string $partyKey): bool
    {
        $this->loadRealtimeRelations($activity);

        return $activity->slots
            ->pluck('group_key')
            ->contains($partyKey);
    }

    /**
     * @return array<string, mixed>
     */
    public function presenceUserInfo(Activity $activity, User $user): array
    {
        $this->loadRealtimeRelations($activity);

        return [
            'user' => $this->userPayload($user),
            'group' => [
                'id' => $activity->group->id,
                'slug' => $activity->group->slug,
                'name' => $activity->group->name,
            ],
            'run' => [
                'id' => $activity->id,
                'status' => $activity->status,
                'starts_at' => $activity->starts_at?->toIso8601String(),
            ],
            'slots' => $this->assignedSlotsForUser($activity, $user)
                ->map(fn (ActivitySlot $slot): array => $this->slotPayload($slot))
                ->values()
                ->all(),
            'can_issue_commands' => $this->canIssueCommand($activity, $user),
        ];
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    public function resolveTarget(Activity $activity, array $target): array
    {
        $this->loadRealtimeRelations($activity);

        $type = (string) ($target['type'] ?? 'party_leads');
        $slots = $this->assignedSlots($activity);

        $targetSlots = match ($type) {
            'all_assigned' => $slots,
            'party_leads' => $slots->filter(fn (ActivitySlot $slot): bool => $slot->is_raid_leader),
            'hosts' => $slots->filter(fn (ActivitySlot $slot): bool => $slot->is_host),
            'users' => $this->filterSlotsByUserIds($slots, $target['user_ids'] ?? []),
            'slots' => $this->filterSlotsBySlotIds($slots, $target['slot_ids'] ?? []),
            default => collect(),
        };

        $targetSlots = $targetSlots->values();

        return [
            'type' => $type,
            'user_ids' => $targetSlots
                ->map(fn (ActivitySlot $slot) => $slot->assignedCharacter?->user_id)
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'slot_ids' => $targetSlots->pluck('id')->values()->all(),
            'slots' => $targetSlots
                ->map(fn (ActivitySlot $slot): array => $this->slotPayload($slot))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function userSlotsPayload(Activity $activity, User $user): array
    {
        return $this->assignedSlotsForUser($activity, $user)
            ->map(fn (ActivitySlot $slot): array => $this->slotPayload($slot))
            ->values()
            ->all();
    }

    private function runCanUseRealtime(Activity $activity): bool
    {
        if ($activity->isArchived()) {
            return false;
        }

        if ($activity->status === Activity::STATUS_DRAFT) {
            return true;
        }

        if (! $activity->starts_at) {
            return false;
        }

        $durationMinutes = (int) round(($activity->duration_hours ?? Activity::DEFAULT_DURATION_HOURS) * 60);

        return $activity->starts_at->copy()->addMinutes($durationMinutes)->isFuture();
    }

    /**
     * @return Collection<int, ActivitySlot>
     */
    private function assignedSlots(Activity $activity): Collection
    {
        return $activity->slots
            ->filter(fn (ActivitySlot $slot): bool => $slot->assignedCharacter instanceof Character
                && $slot->assignedCharacter->user_id !== null)
            ->values();
    }

    /**
     * @return Collection<int, ActivitySlot>
     */
    private function assignedSlotsForUser(Activity $activity, User $user): Collection
    {
        return $this->assignedSlots($activity)
            ->filter(fn (ActivitySlot $slot): bool => (int) $slot->assignedCharacter?->user_id === (int) $user->id)
            ->values();
    }

    /**
     * @param  Collection<int, ActivitySlot>  $slots
     * @param  array<int, mixed>  $userIds
     * @return Collection<int, ActivitySlot>
     */
    private function filterSlotsByUserIds(Collection $slots, array $userIds): Collection
    {
        $allowedUserIds = collect($userIds)->map(fn ($id): int => (int) $id)->filter()->unique();

        if ($allowedUserIds->isEmpty()) {
            return collect();
        }

        return $slots->filter(fn (ActivitySlot $slot): bool => $allowedUserIds->contains((int) $slot->assignedCharacter?->user_id));
    }

    /**
     * @param  Collection<int, ActivitySlot>  $slots
     * @param  array<int, mixed>  $slotIds
     * @return Collection<int, ActivitySlot>
     */
    private function filterSlotsBySlotIds(Collection $slots, array $slotIds): Collection
    {
        $allowedSlotIds = collect($slotIds)->map(fn ($id): int => (int) $id)->filter()->unique();

        if ($allowedSlotIds->isEmpty()) {
            return collect();
        }

        return $slots->filter(fn (ActivitySlot $slot): bool => $allowedSlotIds->contains((int) $slot->id));
    }

    /**
     * @return array<string, mixed>
     */
    private function slotPayload(ActivitySlot $slot): array
    {
        return [
            'id' => $slot->id,
            'group_key' => $slot->group_key,
            'group_label' => $slot->group_label ?? [],
            'slot_key' => $slot->slot_key,
            'slot_label' => $slot->slot_label ?? [],
            'position_in_group' => $slot->position_in_group,
            'is_bench' => $this->slotBench->isBench($slot),
            'is_host' => $slot->is_host,
            'is_raid_leader' => $slot->is_raid_leader,
            'user' => $slot->assignedCharacter?->user
                ? $this->userPayload($slot->assignedCharacter->user)
                : null,
            'character' => $slot->assignedCharacter
                ? $this->characterPayload($slot->assignedCharacter)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'avatar_url' => $this->imageUrl($user->avatar_url),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function characterPayload(Character $character): array
    {
        return [
            'id' => $character->id,
            'name' => $character->name,
            'world' => $character->world,
            'datacenter' => $character->datacenter,
            'avatar_url' => $this->imageUrl($character->avatar_url),
            'user_id' => $character->user_id,
        ];
    }

    private function imageUrl(?string $path): ?string
    {
        if (! filled($path)) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return asset(ltrim($path, '/'));
    }
}
