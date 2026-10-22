<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlotAssignment;
use App\Models\AuditLog;
use App\Models\GroupMembership;
use App\Models\User;
use App\Services\Characters\CharacterTransferHistory;
use App\Services\Runs\RunAccessService;
use App\Support\Audit\AuditScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class YourGroupsService
{
    public function __construct(
        private readonly RunAccessService $runAccess,
        private readonly CharacterTransferHistory $transferHistory,
    ) {}

    /**
     * Enrich existing membership cards without loading each group's run history.
     *
     * @param  Collection<int, array<string, mixed>>  $groups
     * @return Collection<int, array<string, mixed>>
     */
    public function forUser(User $user, Collection $groups): Collection
    {
        $groups = $groups->unique('id')->values();
        if ($groups->isEmpty()) {
            return $groups;
        }

        $now = CarbonImmutable::now();
        $ids = $groups->pluck('id')->all();
        $runs = Activity::query()->whereIn('activities.group_id', $ids);
        $this->runAccess->scopeVisibleTo($runs, $user->id);
        $runIds = (clone $runs)->select('activities.id');

        $runActivity = $this->latestByGroup(clone $runs, 'activities.group_id', [
            'activities.created_at', 'activities.updated_at', 'activities.completed_at',
        ], $now);
        $membershipActivity = $this->latestByGroup(
            GroupMembership::query()->whereIn('group_id', $ids), 'group_id', ['joined_at'], $now,
        );

        $interactions = [
            $this->latestByGroup(GroupMembership::query()->whereIn('group_id', $ids)
                ->where('user_id', $user->id), 'group_id', ['joined_at', 'created_at'], $now),
            $this->latestByGroup(AuditLog::query()->where('scope_type', AuditScope::GROUP)
                ->whereIn('scope_id', $ids)->where('actor_user_id', $user->id),
                'scope_id', ['created_at'], $now),
            $this->latestByGroup(ActivityApplication::query()
                ->join('activities', 'activities.id', '=', 'activity_applications.activity_id')
                ->whereIn('activities.id', clone $runIds)->where('activity_applications.user_id', $user->id),
                'activities.group_id', ['activity_applications.submitted_at', 'activity_applications.edited_at', 'activity_applications.created_at'], $now),
            $this->latestByGroup((clone $runs)->where('organized_by_user_id', $user->id),
                'activities.group_id', ['activities.created_at'], $now),
            $this->latestByGroup((clone $runs)->where('progress_recorded_by_user_id', $user->id),
                'activities.group_id', ['activities.progress_recorded_at'], $now),
        ];

        $characterIds = $user->characters()->pluck('id')->all();
        if ($characterIds !== []) {
            $assignments = ActivitySlotAssignment::query()
                ->join('activities', 'activities.id', '=', 'activity_slot_assignments.activity_id')
                ->whereIn('activities.id', clone $runIds)
                ->whereIn('activity_slot_assignments.character_id', $characterIds);
            $this->transferHistory->excludeUnaccepted($assignments, 'activity_slot_assignments.character_id', 'activity_slot_assignments.activity_id');

            $interactions[] = $this->latestByGroup(clone $assignments, 'activities.group_id', [
                'activity_slot_assignments.assigned_at', 'activity_slot_assignments.checked_in_at',
            ], $now);

            // A completed run counts as participation only when this character attended it.
            $interactions[] = $this->latestByGroup($assignments
                ->whereIn('activity_slot_assignments.attendance_status', [
                    ActivitySlotAssignment::STATUS_CHECKED_IN, ActivitySlotAssignment::STATUS_LATE,
                ])->where('activities.status', Activity::STATUS_COMPLETE),
                'activities.group_id', ['activities.completed_at'], $now);
        }

        return $groups->map(function (array $group) use ($user, $runActivity, $membershipActivity, $interactions, $now): array {
            if ((int) $group['owner']['id'] === (int) $user->id) {
                $group['current_user_role'] = GroupMembership::ROLE_OWNER;
            }
            $group['stats']['last_activity_at'] = $this->latest([
                $group['stats']['last_activity_at'],
                $runActivity->get($group['id']),
                $membershipActivity->get($group['id']),
            ], $now)?->toIso8601String();
            $group['last_interaction_at'] = $this->latest(array_map(
                fn (Collection $timestamps) => $timestamps->get($group['id']), $interactions,
            ), $now)?->toIso8601String();

            return $group;
        })->sort(function (array $left, array $right): int {
            return $this->roleRank($left['current_user_role']) <=> $this->roleRank($right['current_user_role'])
                ?: strcmp($right['last_interaction_at'] ?? '', $left['last_interaction_at'] ?? '')
                ?: strnatcasecmp($left['name'], $right['name'])
                ?: $left['id'] <=> $right['id'];
        })->values();
    }

    /** @param array<int, string> $columns */
    private function latestByGroup(Builder $query, string $groupColumn, array $columns, CarbonImmutable $now): Collection
    {
        $query->selectRaw("{$groupColumn} as group_id")->groupBy($groupColumn);
        foreach ($columns as $index => $column) {
            $query->selectRaw("MAX(CASE WHEN {$column} <= ? THEN {$column} END) as time_{$index}", [$now]);
        }

        return $query->get()->mapWithKeys(function ($row) use ($columns, $now): array {
            return [$row->group_id => $this->latest(array_map(
                fn (int $index) => $row->getAttribute("time_{$index}"), array_keys($columns),
            ), $now)];
        });
    }

    private function latest(array $timestamps, CarbonImmutable $now): ?CarbonImmutable
    {
        return collect($timestamps)->filter()
            ->map(fn ($timestamp) => CarbonImmutable::parse($timestamp))
            ->filter(fn (CarbonImmutable $timestamp) => $timestamp->lessThanOrEqualTo($now))
            ->sortByDesc(fn (CarbonImmutable $timestamp) => $timestamp->getTimestamp())
            ->first();
    }

    private function roleRank(?string $role): int
    {
        return match ($role) {
            GroupMembership::ROLE_OWNER => 0,
            GroupMembership::ROLE_ADMIN => 1,
            GroupMembership::ROLE_MODERATOR => 2,
            default => 3,
        };
    }
}
