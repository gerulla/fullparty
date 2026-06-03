<?php

namespace App\Services\Dashboard;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use App\Services\Notifications\NotificationInboxService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class HomeActivityOverviewDataService
{
    public function __construct(
        private readonly NotificationInboxService $notificationInboxService,
    ) {}

    /**
     * @return array{
     *     runs: array<int, array<string, mixed>>,
     *     applications: array<int, array<string, mixed>>,
     *     groups: array<int, array<string, mixed>>,
     *     notifications: array<int, array<string, mixed>>
     * }
     */
    public function forUser(User $user): array
    {
        return [
            'runs' => $this->serializeUserRuns($user),
            'applications' => $this->serializeRecentApplications($user),
            'groups' => $this->serializeRecentGroups($user),
            'notifications' => $this->notificationInboxService->latest($user, 20),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeUserRuns(User $user): array
    {
        $characterIds = $user->characters()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return collect([
            ...$this->applicationRuns($user),
            ...$this->assignmentRuns($characterIds),
            ...$this->currentSlotRuns($characterIds),
        ])
            ->sortByDesc(fn (array $run) => $this->timestampFor($run['starts_at']))
            ->unique('activity_id')
            ->values()
            ->take(20)
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function applicationRuns(User $user): array
    {
        return ActivityApplication::query()
            ->select('activity_applications.*')
            ->join('activities', 'activities.id', '=', 'activity_applications.activity_id')
            ->with([
                'activity.group',
                'activity.activityTypeVersion',
            ])
            ->where('activity_applications.user_id', $user->id)
            ->whereIn('activity_applications.status', [
                ActivityApplication::STATUS_APPROVED,
                ActivityApplication::STATUS_ON_BENCH,
            ])
            ->whereNotNull('activities.starts_at')
            ->orderByDesc('activities.starts_at')
            ->limit(40)
            ->get()
            ->map(fn (ActivityApplication $application) => $this->serializeUserActivity(
                $application->activity,
                $application->status === ActivityApplication::STATUS_ON_BENCH ? 'benched' : 'confirmed',
            ))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $characterIds
     * @return array<int, array<string, mixed>>
     */
    private function assignmentRuns(array $characterIds): array
    {
        if ($characterIds === []) {
            return [];
        }

        return ActivitySlotAssignment::query()
            ->select('activity_slot_assignments.*')
            ->join('activities', 'activities.id', '=', 'activity_slot_assignments.activity_id')
            ->with([
                'activity.group',
                'activity.activityTypeVersion',
            ])
            ->whereIn('activity_slot_assignments.character_id', $characterIds)
            ->whereNull('activity_slot_assignments.ended_at')
            ->whereIn('activity_slot_assignments.attendance_status', [
                ActivitySlotAssignment::STATUS_ASSIGNED,
                ActivitySlotAssignment::STATUS_CHECKED_IN,
                ActivitySlotAssignment::STATUS_LATE,
            ])
            ->whereNotNull('activities.starts_at')
            ->orderByDesc('activities.starts_at')
            ->limit(40)
            ->get()
            ->map(fn (ActivitySlotAssignment $assignment) => $this->serializeUserActivity(
                $assignment->activity,
                'confirmed',
            ))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $characterIds
     * @return array<int, array<string, mixed>>
     */
    private function currentSlotRuns(array $characterIds): array
    {
        if ($characterIds === []) {
            return [];
        }

        return Activity::query()
            ->with(['group', 'activityTypeVersion'])
            ->whereHas('slots', fn ($query) => $query->whereIn('assigned_character_id', $characterIds))
            ->whereNotNull('starts_at')
            ->orderByDesc('starts_at')
            ->limit(40)
            ->get()
            ->map(fn (Activity $activity) => $this->serializeUserActivity($activity, 'confirmed'))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeUserActivity(?Activity $activity, string $participationStatusKey): ?array
    {
        if (! $activity) {
            return null;
        }

        $status = $this->runDisplayStatus($activity, $participationStatusKey);

        return [
            'id' => sprintf('activity:%d:%s', $activity->id, $status['key']),
            'activity_id' => $activity->id,
            'title' => $activity->title,
            'activity_type_name' => $activity->activityTypeVersion?->name,
            'image_url' => $this->activityImageUrl($activity),
            'starts_at' => $activity->starts_at?->toIso8601String(),
            'status_key' => $status['key'],
            'status_color' => $status['color'],
            'group' => [
                'name' => $activity->group?->name,
                'slug' => $activity->group?->slug,
            ],
            'datacenter' => $activity->datacenter,
            'run_style' => $activity->run_style,
            'difficulty' => $activity->activityTypeVersion?->difficulty,
            'href' => $this->activityOverviewUrl($activity),
        ];
    }

    /**
     * @return array{key: string, color: string}
     */
    private function runDisplayStatus(Activity $activity, string $participationStatusKey): array
    {
        return match ($activity->status) {
            Activity::STATUS_COMPLETE => ['key' => 'completed', 'color' => 'success'],
            Activity::STATUS_CANCELLED => ['key' => 'cancelled', 'color' => 'neutral'],
            Activity::STATUS_ONGOING => ['key' => 'ongoing', 'color' => 'primary'],
            default => $participationStatusKey === 'benched'
                ? ['key' => 'benched', 'color' => 'warning']
                : ['key' => 'confirmed', 'color' => 'success'],
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeRecentApplications(User $user): array
    {
        return ActivityApplication::query()
            ->with([
                'activity.group',
                'activity.activityTypeVersion',
                'selectedCharacter',
            ])
            ->where('user_id', $user->id)
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (ActivityApplication $application) => $this->serializeApplication($application))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeApplication(ActivityApplication $application): array
    {
        $activity = $application->activity;
        $status = $this->applicationDisplayStatus($application);

        return [
            'id' => $application->id,
            'status' => $application->status,
            'status_key' => $status['key'],
            'status_color' => $status['color'],
            'submitted_at' => $application->submitted_at?->toIso8601String(),
            'title' => $activity?->title,
            'activity_type_name' => $activity?->activityTypeVersion?->name,
            'image_url' => $activity ? $this->activityImageUrl($activity) : null,
            'href' => $activity ? $this->applicationUrl($application, $activity) : null,
            'group' => [
                'name' => $activity?->group?->name,
                'slug' => $activity?->group?->slug,
            ],
            'activity' => [
                'id' => $activity?->id,
                'starts_at' => $activity?->starts_at?->toIso8601String(),
                'datacenter' => $activity?->datacenter,
                'run_style' => $activity?->run_style,
                'difficulty' => $activity?->activityTypeVersion?->difficulty,
            ],
        ];
    }

    /**
     * @return array{key: string, color: string}
     */
    private function applicationDisplayStatus(ActivityApplication $application): array
    {
        if ($this->applicationIsRostered($application)) {
            return ['key' => 'assigned', 'color' => 'info'];
        }

        return match ($application->status) {
            ActivityApplication::STATUS_PENDING => ['key' => 'pending', 'color' => 'warning'],
            ActivityApplication::STATUS_APPROVED => ['key' => 'accepted', 'color' => 'success'],
            ActivityApplication::STATUS_ON_BENCH => ['key' => 'waitlist', 'color' => 'primary'],
            ActivityApplication::STATUS_DECLINED => ['key' => 'declined', 'color' => 'error'],
            ActivityApplication::STATUS_CANCELLED => ['key' => 'cancelled', 'color' => 'neutral'],
            ActivityApplication::STATUS_WITHDRAWN => ['key' => 'withdrawn', 'color' => 'neutral'],
            default => ['key' => 'unknown', 'color' => 'neutral'],
        };
    }

    private function applicationIsRostered(ActivityApplication $application): bool
    {
        if (! $application->selected_character_id || ! $application->activity) {
            return false;
        }

        return $application->activity
            ->slots()
            ->where('assigned_character_id', $application->selected_character_id)
            ->exists();
    }

    private function applicationUrl(ActivityApplication $application, Activity $activity): string
    {
        if ($application->status === ActivityApplication::STATUS_PENDING && ! Activity::isArchivedStatus($activity->status)) {
            return route('groups.activities.application', $this->activityRouteParameters($activity), false);
        }

        return $this->activityOverviewUrl($activity);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeRecentGroups(User $user): array
    {
        $characterIds = $user->characters()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $this->userGroups($user)
            ->map(function (array $entry) use ($user, $characterIds) {
                /** @var Group $group */
                $group = $entry['group'];
                $activity = $this->latestGroupActivity($group, $user, $characterIds);

                if (! $activity['at'] instanceof CarbonImmutable) {
                    return null;
                }

                $moderatorAccess = in_array($entry['role'], [
                    GroupMembership::ROLE_OWNER,
                    GroupMembership::ROLE_ADMIN,
                    GroupMembership::ROLE_MODERATOR,
                ], true);

                return [
                    'id' => $group->id,
                    'name' => $group->name,
                    'slug' => $group->slug,
                    'role' => $this->displayGroupRole((string) $entry['role']),
                    'profile_picture_url' => $group->profile_picture_url,
                    'last_activity_at' => $activity['at']->toIso8601String(),
                    'last_activity_key' => $activity['key'],
                    'urls' => [
                        'group' => route('groups.dashboard', $group, false),
                        'runs' => route('groups.dashboard.activities.index', $group, false),
                        'settings' => $moderatorAccess
                            ? route('groups.dashboard.settings', $group, false)
                            : null,
                    ],
                ];
            })
            ->filter(fn ($group) => is_array($group))
            ->sortByDesc(fn (array $group) => $this->timestampFor($group['last_activity_at']))
            ->values()
            ->take(3)
            ->all();
    }

    /**
     * @return Collection<int, array{group: Group, role: string}>
     */
    private function userGroups(User $user): Collection
    {
        $ownedGroups = $user->ownedGroups()
            ->get(['id', 'name', 'slug', 'owner_id', 'profile_picture_url', 'updated_at', 'created_at'])
            ->map(fn (Group $group) => [
                'group' => $group,
                'role' => GroupMembership::ROLE_OWNER,
            ]);

        $memberGroups = GroupMembership::query()
            ->with('group:id,name,slug,owner_id,profile_picture_url,updated_at,created_at')
            ->where('user_id', $user->id)
            ->get()
            ->map(fn (GroupMembership $membership) => [
                'group' => $membership->group,
                'role' => $membership->role,
            ])
            ->filter(fn (array $entry) => $entry['group'] instanceof Group);

        return $ownedGroups
            ->concat($memberGroups)
            ->sortBy(fn (array $entry) => $this->groupRoleRank((string) $entry['role']))
            ->unique(fn (array $entry) => $entry['group']->id)
            ->values();
    }

    private function groupRoleRank(string $role): int
    {
        return match ($role) {
            GroupMembership::ROLE_OWNER => 0,
            GroupMembership::ROLE_ADMIN => 1,
            GroupMembership::ROLE_MODERATOR => 2,
            default => 3,
        };
    }

    private function displayGroupRole(string $role): string
    {
        return match ($role) {
            GroupMembership::ROLE_OWNER => 'owner',
            GroupMembership::ROLE_ADMIN, GroupMembership::ROLE_MODERATOR => 'moderator',
            default => 'member',
        };
    }

    /**
     * @param  array<int, int>  $characterIds
     * @return array{at: CarbonImmutable|null, key: string}
     */
    private function latestGroupActivity(Group $group, User $user, array $characterIds): array
    {
        $now = CarbonImmutable::now();
        $candidates = collect([
            ['at' => $this->latestCreatedRunTimestamp($group, $user, $now), 'key' => 'run_created'],
            ['at' => $this->latestCompletedRunTimestamp($group, $user, $now), 'key' => 'run_completed'],
            ['at' => $this->latestUserApplicationTimestamp($group, $user, $now), 'key' => 'application_updated'],
            ['at' => $this->latestUserRosterActionTimestamp($group, $user, $now), 'key' => 'assignment_updated'],
            ['at' => $this->latestJoinedRunTimestamp($group, $characterIds, $now), 'key' => 'run_joined'],
        ])
            ->filter(fn (array $candidate) => $candidate['at'] instanceof CarbonImmutable
                && $candidate['at']->lessThanOrEqualTo($now))
            ->sortByDesc(fn (array $candidate) => $candidate['at']->getTimestamp())
            ->values()
            ->first();

        return [
            'at' => $candidates['at'] ?? null,
            'key' => $candidates['key'] ?? 'group_updated',
        ];
    }

    private function latestCreatedRunTimestamp(Group $group, User $user, CarbonImmutable $now): ?CarbonImmutable
    {
        return $this->toImmutable(Activity::query()
            ->where('group_id', $group->id)
            ->where('organized_by_user_id', $user->id)
            ->where('created_at', '<=', $now)
            ->max('created_at'));
    }

    private function latestCompletedRunTimestamp(Group $group, User $user, CarbonImmutable $now): ?CarbonImmutable
    {
        $row = Activity::query()
            ->selectRaw('COALESCE(progress_recorded_at, completed_at, updated_at, created_at) as last_at')
            ->where('group_id', $group->id)
            ->where('progress_recorded_by_user_id', $user->id)
            ->whereRaw('COALESCE(progress_recorded_at, completed_at, updated_at, created_at) <= ?', [$now])
            ->orderByRaw('COALESCE(progress_recorded_at, completed_at, updated_at, created_at) DESC')
            ->first();

        return $this->toImmutable($row?->last_at);
    }

    private function latestUserApplicationTimestamp(Group $group, User $user, CarbonImmutable $now): ?CarbonImmutable
    {
        $ownApplication = ActivityApplication::query()
            ->selectRaw('COALESCE(activity_applications.reviewed_at, activity_applications.submitted_at, activity_applications.updated_at, activity_applications.created_at) as last_at')
            ->join('activities', 'activities.id', '=', 'activity_applications.activity_id')
            ->where('activities.group_id', $group->id)
            ->where('activity_applications.user_id', $user->id)
            ->whereRaw(
                'COALESCE(activity_applications.reviewed_at, activity_applications.submitted_at, activity_applications.updated_at, activity_applications.created_at) <= ?',
                [$now],
            )
            ->orderByRaw('COALESCE(activity_applications.reviewed_at, activity_applications.submitted_at, activity_applications.updated_at, activity_applications.created_at) DESC')
            ->first();

        $reviewedApplication = ActivityApplication::query()
            ->selectRaw('COALESCE(activity_applications.reviewed_at, activity_applications.updated_at, activity_applications.created_at) as last_at')
            ->join('activities', 'activities.id', '=', 'activity_applications.activity_id')
            ->where('activities.group_id', $group->id)
            ->where('activity_applications.reviewed_by_user_id', $user->id)
            ->whereRaw(
                'COALESCE(activity_applications.reviewed_at, activity_applications.updated_at, activity_applications.created_at) <= ?',
                [$now],
            )
            ->orderByRaw('COALESCE(activity_applications.reviewed_at, activity_applications.updated_at, activity_applications.created_at) DESC')
            ->first();

        return $this->latestTimestamp([
            $ownApplication?->last_at,
            $reviewedApplication?->last_at,
        ]);
    }

    private function latestUserRosterActionTimestamp(Group $group, User $user, CarbonImmutable $now): ?CarbonImmutable
    {
        $slotAssignment = ActivitySlot::query()
            ->select('activity_slots.updated_at')
            ->join('activities', 'activities.id', '=', 'activity_slots.activity_id')
            ->where('activities.group_id', $group->id)
            ->where('activity_slots.assigned_by_user_id', $user->id)
            ->where('activity_slots.updated_at', '<=', $now)
            ->orderByDesc('activity_slots.updated_at')
            ->first();

        return $this->latestTimestamp([
            ActivitySlotAssignment::query()
                ->where('group_id', $group->id)
                ->where('assigned_by_user_id', $user->id)
                ->where('assigned_at', '<=', $now)
                ->max('assigned_at'),
            ActivitySlotAssignment::query()
                ->where('group_id', $group->id)
                ->where('checked_in_by_user_id', $user->id)
                ->where('checked_in_at', '<=', $now)
                ->max('checked_in_at'),
            ActivitySlotAssignment::query()
                ->where('group_id', $group->id)
                ->where('marked_missing_by_user_id', $user->id)
                ->where('marked_missing_at', '<=', $now)
                ->max('marked_missing_at'),
            $slotAssignment?->updated_at,
        ]);
    }

    /**
     * @param  array<int, int>  $characterIds
     */
    private function latestJoinedRunTimestamp(Group $group, array $characterIds, CarbonImmutable $now): ?CarbonImmutable
    {
        if ($characterIds === []) {
            return null;
        }

        $assignment = ActivitySlotAssignment::query()
            ->selectRaw('COALESCE(checked_in_at, assigned_at, updated_at, created_at) as last_at')
            ->where('group_id', $group->id)
            ->whereIn('character_id', $characterIds)
            ->whereRaw('COALESCE(checked_in_at, assigned_at, updated_at, created_at) <= ?', [$now])
            ->orderByRaw('COALESCE(checked_in_at, assigned_at, updated_at, created_at) DESC')
            ->first();

        $slot = ActivitySlot::query()
            ->select('activity_slots.updated_at')
            ->join('activities', 'activities.id', '=', 'activity_slots.activity_id')
            ->where('activities.group_id', $group->id)
            ->whereIn('activity_slots.assigned_character_id', $characterIds)
            ->where('activity_slots.updated_at', '<=', $now)
            ->orderByDesc('activity_slots.updated_at')
            ->first();

        return $this->latestTimestamp([
            $assignment?->last_at,
            $slot?->updated_at,
        ]);
    }

    private function activityImageUrl(Activity $activity): ?string
    {
        return $activity->activityTypeVersion?->small_image_url
            ?: $activity->activityTypeVersion?->banner_image_url;
    }

    private function activityOverviewUrl(Activity $activity): string
    {
        return route('groups.activities.overview', $this->activityRouteParameters($activity), false);
    }

    /**
     * @return array<string, mixed>
     */
    private function activityRouteParameters(Activity $activity): array
    {
        return [
            'group' => $activity->group,
            'activity' => $activity,
        ];
    }

    private function timestampFor(?string $value): int
    {
        return $value ? CarbonImmutable::parse($value)->getTimestamp() : 0;
    }

    private function toImmutable(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value);
        }

        if (filled($value)) {
            return CarbonImmutable::parse($value);
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function latestTimestamp(array $values): ?CarbonImmutable
    {
        return collect($values)
            ->map(fn ($value) => $this->toImmutable($value))
            ->filter(fn (?CarbonImmutable $value) => $value instanceof CarbonImmutable)
            ->sortByDesc(fn (CarbonImmutable $value) => $value->getTimestamp())
            ->values()
            ->first();
    }
}
