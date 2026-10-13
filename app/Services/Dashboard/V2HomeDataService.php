<?php

namespace App\Services\Dashboard;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use App\Models\Character;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class V2HomeDataService
{
    private const UPCOMING_STATUSES = [
        Activity::STATUS_SCHEDULED,
        Activity::STATUS_ASSIGNED,
        Activity::STATUS_UPCOMING,
    ];

    /** @return array<string, mixed> */
    public function user(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'avatar_url' => $user->avatar_url,
            'characters' => Character::query()
                ->where('user_id', $user->id)
                ->orderByDesc('is_primary')
                ->orderBy('name')
                ->orderBy('id')
                ->get()
                ->map(fn (Character $character): array => [
                    'id' => $character->id,
                    'name' => $character->name,
                    'world' => $character->world,
                    'datacenter' => $character->datacenter,
                    'lodestone_id' => $character->lodestone_id,
                    'avatar_url' => $character->avatar_url,
                    'is_primary' => (bool) $character->is_primary,
                    'verified_at' => $character->verified_at?->toIso8601String(),
                    'claimed_at' => $character->claimed_at?->toIso8601String(),
                ])->all(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function applications(User $user): array
    {
        return $this->characterApplications($user, history: false);
    }

    /** @return array<int, array<string, mixed>> */
    public function applicationHistory(User $user): array
    {
        return $this->characterApplications($user, history: true);
    }

    /** @return array<int, array<string, mixed>> */
    private function characterApplications(User $user, bool $history): array
    {
        $direction = $history ? 'desc' : 'asc';
        $characterIds = Character::where('user_id', $user->id)->pluck('id');
        $query = ActivityApplication::query()
            ->join('activities', 'activities.id', '=', 'activity_applications.activity_id')
            ->where('activity_applications.user_id', $user->id)
            ->whereIn('activity_applications.selected_character_id', $characterIds)
            ->where('activities.starts_at', $history ? '<' : '>=', now())
            ->when($history,
                fn (Builder $query) => $query->where('activities.status', '!=', Activity::STATUS_DRAFT),
                fn (Builder $query) => $query->whereIn('activities.status', self::UPCOMING_STATUSES)
                    ->where('activities.is_completed', false))
            ->whereHas('activity.group', fn (Builder $query) => $this->excludeBannedGroups($query, $user));

        // Limit each character in SQL, rather than letting one character consume the whole panel.
        $ranked = (clone $query)->select('activity_applications.id')->selectRaw(
            "ROW_NUMBER() OVER (PARTITION BY activity_applications.selected_character_id
                ORDER BY activities.starts_at {$direction}, activity_applications.id {$direction}) AS character_rank"
        );

        return $query->select('activity_applications.*')
            ->whereIn('activity_applications.id', DB::query()->fromSub($ranked, 'ranked_applications')
                ->select('id')->where('character_rank', '<=', 4))
            ->with([
                'activity.group',
                'activity.activityTypeVersion',
                'activity.slots' => fn ($query) => $query->whereIn('assigned_character_id', $characterIds)
                    ->select('id', 'activity_id', 'assigned_character_id', 'slot_kind'),
                'activity.slotAssignments' => fn ($query) => $query
                    ->whereIn('character_id', $characterIds)->orderByDesc('assigned_at')->orderByDesc('id')
                    ->select('id', 'activity_id', 'character_id', 'activity_slot_id', 'attendance_status', 'assigned_at', 'ended_at'),
                'activity.slotAssignments.slot:id,slot_kind',
            ])
            ->orderBy('activities.starts_at', $direction)
            ->orderBy('activity_applications.id', $direction)
            ->get()
            ->map(fn (ActivityApplication $application): array => [
                'id' => $application->id,
                'status' => $application->status,
                'display_status' => $this->applicationDisplayStatus($application, $history),
                'submitted_at' => $application->submitted_at?->toIso8601String(),
                'selected_character_id' => $application->selected_character_id,
                'character' => [
                    'name' => $application->applicant_character_name,
                    'world' => $application->applicant_world,
                    'avatar_url' => $application->applicant_avatar_url,
                ],
                'run' => $this->serializeRun($application->activity),
                'href' => route('groups.activities.application', [
                    'locale' => app()->getLocale(),
                    'group' => $application->activity->group->slug,
                    'activity' => $application->activity_id,
                ], false),
            ])->all();
    }

    private function applicationDisplayStatus(ActivityApplication $application, bool $history): string
    {
        $activity = $application->activity;
        if ($activity->status === Activity::STATUS_CANCELLED) {
            return ActivityApplication::STATUS_CANCELLED;
        }

        $assignment = $activity->slotAssignments->firstWhere('character_id', $application->selected_character_id);
        $slot = $activity->slots->firstWhere('assigned_character_id', $application->selected_character_id);

        // Missing players leave the roster, but their ended assignment retains the attendance record.
        if ($history && $assignment) {
            if (in_array($assignment->attendance_status, [ActivitySlotAssignment::STATUS_MISSING, ActivitySlotAssignment::STATUS_LATE], true)) {
                return $assignment->attendance_status;
            }
            if ($assignment->attendance_status === ActivitySlotAssignment::STATUS_CHECKED_IN) {
                return $assignment->slot?->slot_kind === ActivitySlot::SLOT_KIND_FILL_IN
                    ? 'filled_in'
                    : ActivitySlotAssignment::STATUS_CHECKED_IN;
            }
        }

        if (! in_array($application->status, ActivityApplication::ACTIVE_STATUSES, true)) {
            return $application->status;
        }
        if ($slot && (! $assignment || $assignment->ended_at === null)) {
            return match ($slot->slot_kind) {
                ActivitySlot::SLOT_KIND_BENCH => ActivityApplication::STATUS_ON_BENCH,
                ActivitySlot::SLOT_KIND_FILL_IN => 'filled_in',
                default => ActivitySlotAssignment::STATUS_ASSIGNED,
            };
        }

        return $application->status;
    }

    /** @return array<int, array<string, mixed>> */
    public function groups(User $user): array
    {
        $now = CarbonImmutable::now();

        return Group::query()
            ->where(fn (Builder $query) => $query
                ->where('owner_id', $user->id)
                ->orWhereHas('memberships', fn (Builder $membership) => $membership->where('user_id', $user->id)))
            ->where(fn (Builder $query) => $this->excludeBannedGroups($query, $user))
            ->with(['memberships' => fn ($query) => $query->where('user_id', $user->id)])
            ->withMax(['activities as last_run_update_at' => fn (Builder $query) => $query
                ->whereNotIn('status', Activity::MODERATOR_ONLY_STATUSES)
                ->where('updated_at', '<=', $now)], 'updated_at')
            ->withMax(['activities as last_run_completed_at' => fn (Builder $query) => $query
                ->where('status', Activity::STATUS_COMPLETE)
                ->where('completed_at', '<=', $now)], 'completed_at')
            ->get()
            ->map(function (Group $group) use ($user, $now): array {
                $role = (int) $group->owner_id === (int) $user->id
                    ? GroupMembership::ROLE_OWNER
                    : $group->memberships->first()?->role;
                $canManage = $group->hasModeratorAccess($user->id);
                $lastActivity = collect([
                    $group->updated_at,
                    $group->created_at,
                    $group->last_run_update_at,
                    $group->last_run_completed_at,
                ])->filter()
                    ->map(fn ($timestamp) => CarbonImmutable::parse($timestamp))
                    ->filter(fn (CarbonImmutable $timestamp) => $timestamp->lessThanOrEqualTo($now))
                    ->sortByDesc(fn (CarbonImmutable $timestamp) => $timestamp->getTimestamp())
                    ->first();
                $routeParameters = ['locale' => app()->getLocale(), 'group' => $group->slug];

                return [
                    ...$this->serializeGroup($group),
                    'rank' => match ($role) {
                        GroupMembership::ROLE_OWNER => 'owner',
                        GroupMembership::ROLE_ADMIN => 'admin',
                        GroupMembership::ROLE_MODERATOR => 'mod',
                        default => 'member',
                    },
                    'datacenter' => $group->datacenter,
                    'group_type' => $group->group_type,
                    'banner_image_url' => $group->banner_image_url,
                    'can_manage' => $canManage,
                    'last_activity_at' => $lastActivity?->toIso8601String(),
                    'href' => route('groups.dashboard', $routeParameters, false),
                    'runs_href' => route('groups.dashboard.activities.index', $routeParameters, false),
                    'settings_href' => $canManage ? route('groups.dashboard.settings', $routeParameters, false) : null,
                ];
            })
            ->sortBy([['can_manage', 'desc'], ['last_activity_at', 'desc'], ['id', 'asc']])
            ->take(3)
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function recommendedRuns(User $user): array
    {
        return $this->runCardQuery($user)
            ->where('starts_at', '>=', now())
            ->whereIn('status', self::UPCOMING_STATUSES)
            ->where('is_completed', false)
            ->where('is_public', true)
            ->whereHas('group', fn (Builder $query) => $this->excludeBannedGroups($query->visible(), $user))
            ->inRandomOrder()
            ->limit(10)
            ->get()
            ->map(fn (Activity $activity): array => $this->serializeRunCard($activity))
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function groupRuns(User $user): array
    {
        $now = CarbonImmutable::now();

        return $this->runCardQuery($user)
            ->whereBetween('starts_at', [$now, $now->addDays(7)])
            ->whereIn('status', self::UPCOMING_STATUSES)
            ->where('is_completed', false)
            ->whereHas('group', fn (Builder $query) => $this->excludeBannedGroups($query, $user)
                ->where(fn (Builder $query) => $query
                    ->where('owner_id', $user->id)
                    ->orWhereHas('memberships', fn (Builder $membership) => $membership->where('user_id', $user->id))))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->groupBy('group_id')
            ->map(function ($activities): array {
                $group = $activities->first()->group;

                return [
                    'group' => $this->serializeGroup($group),
                    'runs_href' => route('groups.dashboard.activities.index', [
                        'locale' => app()->getLocale(), 'group' => $group->slug,
                    ], false),
                    'runs' => $activities->map(fn (Activity $activity): array => $this->serializeRunCard($activity))->all(),
                ];
            })
            ->values()
            ->all();
    }

    private function runCardQuery(User $user): Builder
    {
        return Activity::query()
            ->with([
                'group',
                'activityTypeVersion',
                'applications' => fn ($query) => $query
                    ->select('id', 'activity_id', 'user_id', 'applicant_avatar_url')
                    ->whereIn('status', ActivityApplication::ACTIVE_STATUSES)
                    ->orderBy('created_at')->orderBy('id')->limit(3),
                'applications.user:id,public_profile,public_characters',
            ])
            ->withCount(['applications' => fn (Builder $query) => $query
                ->whereIn('status', ActivityApplication::ACTIVE_STATUSES)])
            ->withExists(['applications as has_existing_application' => fn (Builder $query) => $query
                ->where('user_id', $user->id)
                ->where('status', '!=', ActivityApplication::STATUS_WITHDRAWN)]);
    }

    /** @return array<string, mixed> */
    private function serializeRunCard(Activity $activity): array
    {
        $hasApplication = (bool) $activity->has_existing_application;
        $canApply = ! $hasApplication && $activity->needs_application && $activity->acceptsApplications();
        $progPoint = collect($activity->activityTypeVersion?->prog_points ?? [])
            ->firstWhere('key', $activity->target_prog_point_key);

        return [
            ...$this->serializeRun($activity),
            'application_count' => (int) $activity->applications_count,
            // Private profiles and guests use an anonymous avatar in the card preview.
            'applicant_avatars' => $activity->applications
                ->map(fn (ActivityApplication $application): ?string => $application->user?->public_profile
                    && $application->user?->public_characters ? $application->applicant_avatar_url : null)
                ->all(),
            'is_public' => (bool) $activity->is_public,
            'allow_guest_applications' => (bool) $activity->allow_guest_applications,
            'target_prog_point_key' => $activity->target_prog_point_key,
            'target_prog_point_label' => is_array($progPoint['label'] ?? null) ? $progPoint['label'] : null,
            'has_existing_application' => $hasApplication,
            'can_apply' => $canApply,
            'application_href' => ($canApply || $hasApplication)
                ? route('groups.activities.application', [
                    'locale' => app()->getLocale(),
                    'group' => $activity->group->slug,
                    'activity' => $activity->id,
                ], false)
                : null,
        ];
    }

    private function excludeBannedGroups(Builder $query, User $user): Builder
    {
        return $query->whereDoesntHave('bans', fn (Builder $ban) => $ban->where('user_id', $user->id));
    }

    /** @return array<string, mixed> */
    private function serializeGroup(Group $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'slug' => $group->slug,
            'profile_picture_url' => $group->profile_picture_url,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeRun(Activity $activity): array
    {
        $version = $activity->activityTypeVersion;

        return [
            'id' => $activity->id,
            'title' => $activity->title,
            'description' => $activity->description,
            'status' => $activity->status,
            'starts_at' => $activity->starts_at?->toIso8601String(),
            'duration_hours' => $activity->duration_hours,
            'datacenter' => $activity->datacenter,
            'run_style' => $activity->run_style,
            'intensity' => $activity->intensity,
            'min_item_level' => $activity->min_item_level,
            'beginner_friendly' => (bool) $activity->beginner_friendly,
            'needs_application' => (bool) $activity->needs_application,
            'group' => $this->serializeGroup($activity->group),
            'activity_type' => [
                'id' => $activity->activity_type_id,
                'version_id' => $activity->activity_type_version_id,
                'name' => $version?->name,
                'difficulty' => $version?->difficulty,
                'small_image_url' => $version?->small_image_url,
                'banner_image_url' => $version?->banner_image_url,
            ],
            'href' => route('groups.activities.overview', [
                'locale' => app()->getLocale(),
                'group' => $activity->group->slug,
                'activity' => $activity->id,
            ], false),
        ];
    }
}
