<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use App\Services\Notifications\NotificationPreferenceSettingsService;
use App\Support\Groups\GroupDiscoveryBadgePalette;
use App\Support\Seo\ServerMeta;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class GroupDashboardController extends Controller
{
    public function __construct(
        private readonly GroupDiscoveryBadgePalette $groupDiscoveryBadgePalette,
        private readonly NotificationPreferenceSettingsService $notificationPreferenceSettingsService,
        private readonly ServerMeta $serverMeta,
    ) {}

    public function show(Group $group): Response
    {
        $group->load([
            'owner.primaryCharacter',
            'memberships.user',
            'activities' => fn ($query) => $query
                ->with([
                    'organizer',
                    'organizerCharacter',
                    'applications' => fn ($applicationQuery) => $applicationQuery
                        ->select(['id', 'activity_id', 'user_id', 'status'])
                        ->where('user_id', auth()->id())
                        ->where('status', '!=', ActivityApplication::STATUS_WITHDRAWN),
                    'activityType',
                    'activityTypeVersion.activityType',
                ])
                ->withCount([
                    'slots',
                    'applications' => fn ($query) => $query->whereIn('status', ActivityApplication::ACTIVE_STATUSES),
                ]),
        ]);

        $currentUserId = auth()->id();
        $isMember = $group->hasMember($currentUserId);

        if (! $isMember) {
            abort(403);
        }

        $currentMembership = $group->memberships->firstWhere('user_id', $currentUserId);
        $canManageActivities = $group->hasModeratorAccess($currentUserId);
        $now = now();
        $weekStart = $now->copy()->startOfDay();
        $weekEnd = $weekStart->copy()->addDays(6)->endOfDay();
        $activities = $group->activities
            ->when(
                ! $canManageActivities,
                fn ($activities) => $activities->reject(fn (Activity $activity) => Activity::isModeratorOnlyStatus($activity->status))
            )
            ->sortByDesc('updated_at')
            ->values();
        $upcomingActivities = $activities
            ->filter(fn (Activity $activity) => ! Activity::isArchivedStatus($activity->status)
                && $activity->starts_at !== null
                && $activity->starts_at->gte($now))
            ->sort(function (Activity $left, Activity $right) {
                $startsAtComparison = $left->starts_at->getTimestamp()
                    <=> $right->starts_at->getTimestamp();

                if ($startsAtComparison !== 0) {
                    return $startsAtComparison;
                }

                return $left->id <=> $right->id;
            })
            ->values();
        $historyActivities = $activities
            ->filter(fn (Activity $activity) => Activity::isArchivedStatus($activity->status))
            ->sortByDesc(fn (Activity $activity) => $this->activityHistoryTimestamp($activity))
            ->values();
        $currentWeekActivities = $activities
            ->filter(fn (Activity $activity) => $activity->starts_at !== null
                && $activity->starts_at->gte($weekStart)
                && $activity->starts_at->lte($weekEnd))
            ->sort(function (Activity $left, Activity $right) {
                $startsAtComparison = $left->starts_at->getTimestamp()
                    <=> $right->starts_at->getTimestamp();

                if ($startsAtComparison !== 0) {
                    return $startsAtComparison;
                }

                return $left->id <=> $right->id;
            })
            ->values();
        $statusCounts = collect(Activity::STATUSES)
            ->mapWithKeys(fn (string $status) => [$status => $activities->where('status', $status)->count()]);
        $memberRoleBreakdown = [
            GroupMembership::ROLE_OWNER => $group->memberships
                ->where('role', GroupMembership::ROLE_OWNER)
                ->count(),
            GroupMembership::ROLE_ADMIN => $group->memberships
                ->where('role', GroupMembership::ROLE_ADMIN)
                ->count(),
            GroupMembership::ROLE_MODERATOR => $group->memberships
                ->where('role', GroupMembership::ROLE_MODERATOR)
                ->count(),
            GroupMembership::ROLE_MEMBER => $group->memberships
                ->where('role', GroupMembership::ROLE_MEMBER)
                ->count(),
        ];
        $lastActivityAt = $activities->first()?->updated_at?->toIso8601String();
        $latestMemberJoinAt = $group->memberships
            ->sortByDesc('joined_at')
            ->first()?->joined_at?->toIso8601String();

        return Inertia::render('Dashboard/Groups/CommunityDashboard', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'description' => $group->description,
                'profile_picture_url' => $group->profile_picture_url,
                'banner_image_url' => $group->banner_image_url,
                'discord_invite_url' => $group->discord_invite_url,
                'datacenter' => $group->datacenter,
                'region' => $group->inferredRegion(),
                'is_visible' => $group->is_visible,
                'slug' => $group->slug,
                'group_type' => $group->group_type,
                'join_mode' => $group->join_mode,
                'primary_focuses' => $group->primary_focuses ?? [],
                'experience_expectation' => $group->experience_expectation,
                'voice_expectation' => $group->voice_expectation,
                'preferred_languages' => $group->preferred_languages ?? [],
                'tags' => $group->tags ?? [],
                'active_timezone' => $group->active_timezone,
                'active_days' => $group->active_days ?? [],
                'active_start_time' => $group->active_start_time,
                'active_end_time' => $group->active_end_time,
                'badge_meta' => $this->groupDiscoveryBadgePalette->badgeMetaForGroup($group),
                'owner' => $this->serializeGroupUserIdentity($group->owner),
                'current_user_role' => $group->memberships
                    ->firstWhere('user_id', $currentUserId)
                    ?->role,
                'notifications' => [
                    'enabled' => (bool) ($currentMembership?->notifications_enabled ?? true),
                    'preferences' => $currentMembership instanceof GroupMembership
                        ? $this->notificationPreferenceSettingsService->serializeGroupPreferences($currentMembership->user, $group->id)
                        : [],
                ],
                'permissions' => [
                    'can_manage_group' => $group->isOwnedBy($currentUserId),
                    'can_manage_members' => $canManageActivities,
                    'can_manage_discovery' => $group->hasAdminAccess($currentUserId),
                    'can_manage_activities' => $canManageActivities,
                    'can_view_members' => true,
                    'can_review_membership_applications' => $group->usesMembershipApplications() && $group->hasModeratorAccess($currentUserId),
                    'can_manage_membership_application_form' => $group->usesMembershipApplications() && $group->hasAdminAccess($currentUserId),
                    'can_leave' => $currentMembership instanceof GroupMembership
                        && ! $group->isOwnedBy($currentUserId),
                    'can_toggle_notifications' => $currentMembership instanceof GroupMembership,
                ],
                'stats' => [
                    'member_count' => $group->memberships->count(),
                    'moderator_count' => $isMember
                        ? $group->memberships
                            ->where('role', GroupMembership::ROLE_MODERATOR)
                            ->count()
                        : 0,
                    'activity_count' => $activities->count(),
                    'draft_count' => (int) $statusCounts->get(Activity::STATUS_DRAFT, 0),
                    'scheduled_count' => (int) $statusCounts->get(Activity::STATUS_SCHEDULED, 0),
                    'assigned_count' => (int) $statusCounts->get(Activity::STATUS_ASSIGNED, 0),
                    'upcoming_count' => (int) $statusCounts->get(Activity::STATUS_UPCOMING, 0),
                    'ongoing_count' => (int) $statusCounts->get(Activity::STATUS_ONGOING, 0),
                    'completed_count' => (int) $statusCounts->get(Activity::STATUS_COMPLETE, 0),
                    'cancelled_count' => (int) $statusCounts->get(Activity::STATUS_CANCELLED, 0),
                    'open_application_count' => $activities
                        ->filter(fn (Activity $activity) => $activity->acceptsApplications())
                        ->count(),
                    'guest_friendly_count' => $activities
                        ->where('allow_guest_applications', true)
                        ->count(),
                    'public_activity_count' => $activities
                        ->where('is_public', true)
                        ->count(),
                    'last_activity_at' => $lastActivityAt,
                    'latest_member_join_at' => $latestMemberJoinAt,
                ],
                'member_role_breakdown' => [
                    'owner' => $memberRoleBreakdown[GroupMembership::ROLE_OWNER],
                    'admin' => $memberRoleBreakdown[GroupMembership::ROLE_ADMIN],
                    'moderator' => $memberRoleBreakdown[GroupMembership::ROLE_MODERATOR],
                    'member' => $memberRoleBreakdown[GroupMembership::ROLE_MEMBER],
                ],
                'members_preview' => $group->memberships
                    ->sortBy(function (GroupMembership $membership) {
                        return array_search($membership->role, GroupMembership::ROLES, true);
                    })
                    ->take(6)
                    ->values()
                    ->map(fn (GroupMembership $membership) => [
                        'id' => $membership->user->id,
                        'name' => $membership->user->name,
                        'avatar_url' => $membership->user->avatar_url,
                        'role' => $membership->role,
                        'joined_at' => $membership->joined_at?->toIso8601String(),
                    ]),
                'activity_status_breakdown' => collect(Activity::STATUSES)
                    ->map(fn (string $status) => [
                        'status' => $status,
                        'count' => (int) $statusCounts->get($status, 0),
                    ])
                    ->values(),
                'content_summary' => $this->serializeDashboardContentSummary($activities),
                'content_items' => $this->serializeDashboardContentItems($activities),
                'current_week' => [
                    'start_date' => $weekStart->toDateString(),
                    'end_date' => $weekEnd->toDateString(),
                ],
                'current_week_activities' => $currentWeekActivities
                    ->map(fn (Activity $activity) => $this->serializeDashboardActivity($activity, $group, $canManageActivities)),
                'upcoming_activities' => $upcomingActivities
                    ->take(8)
                    ->map(fn (Activity $activity) => $this->serializeDashboardActivity($activity, $group, $canManageActivities)),
                'history_activities' => $historyActivities
                    ->take(6)
                    ->map(fn (Activity $activity) => $this->serializeDashboardActivity($activity, $group, $canManageActivities)),
            ],
        ])->withViewData('serverMeta', $this->serverMeta->group($group));
    }

    private function activityHistoryTimestamp(Activity $activity): int
    {
        return $activity->completed_at?->getTimestamp()
            ?? $activity->starts_at?->getTimestamp()
            ?? $activity->updated_at?->getTimestamp()
            ?? $activity->created_at?->getTimestamp()
            ?? 0;
    }

    /**
     * @return array{total_runs: int, status_breakdown: array<int, array{status: string, count: int}>}
     */
    private function serializeDashboardContentSummary(Collection $visibleActivities): array
    {
        return [
            'total_runs' => (int) $visibleActivities->count(),
            'status_breakdown' => [
                [
                    'status' => 'draft',
                    'count' => (int) $visibleActivities->where('status', Activity::STATUS_DRAFT)->count(),
                ],
                [
                    'status' => 'scheduled',
                    'count' => (int) $visibleActivities->where('status', Activity::STATUS_SCHEDULED)->count(),
                ],
                [
                    'status' => 'active',
                    'count' => (int) $visibleActivities->whereIn('status', [
                        Activity::STATUS_ASSIGNED,
                        Activity::STATUS_UPCOMING,
                        Activity::STATUS_ONGOING,
                    ])->count(),
                ],
                [
                    'status' => 'complete',
                    'count' => (int) $visibleActivities->where('status', Activity::STATUS_COMPLETE)->count(),
                ],
                [
                    'status' => 'cancelled',
                    'count' => (int) $visibleActivities->where('status', Activity::STATUS_CANCELLED)->count(),
                ],
            ],
        ];
    }

    /**
     * @return array<int, array{
     *     key: string,
     *     activity_name: string,
     *     activity_image_url: string|null,
     *     total_runs: int,
     *     completed_runs: int,
     *     active_runs: int,
     *     last_run_at: string|null,
     *     next_run_at: string|null
     * }>
     */
    private function serializeDashboardContentItems(Collection $visibleActivities): array
    {
        $now = now();

        return $visibleActivities
            ->groupBy(function (Activity $activity) {
                if ($activity->activity_type_version_id !== null) {
                    return 'version:'.$activity->activity_type_version_id;
                }

                return 'name:'.$this->resolveDashboardActivityDisplayName($activity);
            })
            ->map(function (Collection $runs, string $key) use ($now) {
                /** @var Activity $representativeRun */
                $representativeRun = $runs->first();

                return [
                    'key' => $key,
                    'activity_name' => $this->resolveDashboardActivityDisplayName($representativeRun),
                    'activity_image_url' => $representativeRun->activityTypeVersion?->small_image_url,
                    'total_runs' => (int) $runs->count(),
                    'completed_runs' => (int) $runs->where('status', Activity::STATUS_COMPLETE)->count(),
                    'active_runs' => (int) $runs->whereIn('status', [
                        Activity::STATUS_DRAFT,
                        Activity::STATUS_SCHEDULED,
                        Activity::STATUS_ASSIGNED,
                        Activity::STATUS_UPCOMING,
                        Activity::STATUS_ONGOING,
                    ])->count(),
                    'last_run_at' => $runs
                        ->filter(fn (Activity $run) => $run->starts_at !== null && $run->starts_at->lte($now))
                        ->pluck('starts_at')
                        ->filter()
                        ->sortDesc()
                        ->first()?->toIso8601String(),
                    'next_run_at' => $runs
                        ->filter(fn (Activity $run) => $run->starts_at !== null
                            && $run->starts_at->gt($now)
                            && in_array($run->status, [
                                Activity::STATUS_DRAFT,
                                Activity::STATUS_SCHEDULED,
                                Activity::STATUS_ASSIGNED,
                                Activity::STATUS_UPCOMING,
                                Activity::STATUS_ONGOING,
                            ], true))
                        ->pluck('starts_at')
                        ->filter()
                        ->sort()
                        ->first()?->toIso8601String(),
                ];
            })
            ->sortByDesc(fn (array $item) => $item['next_run_at'] ?? $item['last_run_at'] ?? '')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDashboardActivity(Activity $activity, Group $group, bool $canManageActivities): array
    {
        $canViewOverview = $this->canViewActivityOverview($activity, $group, $canManageActivities);
        $hasExistingApplication = $activity->applications->isNotEmpty();
        $viewLink = $canManageActivities
            ? route('groups.dashboard.activities.show', [
                'group' => $group,
                'activity' => $activity,
            ], false)
            : ($canViewOverview
                ? route('groups.activities.overview', $this->activityAttendeeRouteParameters($group, $activity), false)
                : route('groups.dashboard.activities.index', [
                    'group' => $group,
                ], false));
        $canApply = ! $hasExistingApplication
            && $activity->needs_application
            && $activity->acceptsApplications()
            && $canViewOverview;

        return [
            'id' => $activity->id,
            'activity_type' => [
                'id' => $activity->activityType?->id,
                'slug' => $activity->activityType?->slug,
                'draft_name' => $activity->activityType?->draft_name,
            ],
            'small_image_url' => $activity->activityTypeVersion?->small_image_url,
            'banner_image_url' => $activity->activityTypeVersion?->banner_image_url,
            'title' => $activity->title,
            'status' => $activity->status,
            'starts_at' => $activity->starts_at?->toIso8601String(),
            'duration_hours' => $activity->duration_hours,
            'target_prog_point_key' => $activity->target_prog_point_key,
            'target_prog_point_label' => $this->resolveTargetProgPointLabel($activity),
            'is_public' => $activity->is_public,
            'secret_key' => null,
            'can_view_overview' => $canViewOverview,
            'has_existing_application' => $hasExistingApplication,
            'can_apply' => $canApply,
            'needs_application' => $activity->needs_application,
            'allow_guest_applications' => $activity->allow_guest_applications,
            'organized_by' => $activity->organizer ? [
                'id' => $activity->organizer->id,
                'name' => $activity->organizer->name,
                'avatar_url' => $activity->organizer->avatar_url,
            ] : null,
            'organized_by_character' => $activity->organizerCharacter ? [
                'id' => $activity->organizerCharacter->id,
                'user_id' => $activity->organizerCharacter->user_id,
                'name' => $activity->organizerCharacter->name,
                'avatar_url' => $activity->organizerCharacter->avatar_url,
            ] : null,
            'slot_count' => (int) ($activity->slots_count ?? 0),
            'application_count' => (int) ($activity->applications_count ?? 0),
            'created_at' => $activity->created_at?->toIso8601String(),
            'updated_at' => $activity->updated_at?->toIso8601String(),
            'links' => [
                'view' => $viewLink,
                'apply' => ($canApply || $hasExistingApplication)
                    ? route('groups.activities.application', $this->activityAttendeeRouteParameters($group, $activity), false)
                    : null,
            ],
        ];
    }

    private function resolveTargetProgPointLabel(Activity $activity): ?array
    {
        if (blank($activity->target_prog_point_key)) {
            return null;
        }

        $progPoint = collect($activity->activityTypeVersion?->prog_points ?? [])
            ->firstWhere('key', $activity->target_prog_point_key);

        $label = is_array($progPoint) ? ($progPoint['label'] ?? null) : null;

        return is_array($label) ? $label : null;
    }

    /**
     * @return array{group: Group, activity: Activity}
     */
    private function activityAttendeeRouteParameters(Group $group, Activity $activity): array
    {
        return [
            'group' => $group,
            'activity' => $activity,
        ];
    }

    private function canViewActivityOverview(Activity $activity, Group $group, bool $canManageActivities): bool
    {
        if (Activity::isModeratorOnlyStatus($activity->status)) {
            return $canManageActivities;
        }

        return $group->is_visible || $group->hasMember(auth()->id()) || $canManageActivities;
    }

    private function resolveDashboardActivityDisplayName(Activity $activity): string
    {
        $activityTypeVersionName = $this->resolveDashboardLocalizedText($activity->activityTypeVersion?->name);

        if ($activityTypeVersionName !== null) {
            return $activityTypeVersionName;
        }

        if (filled($activity->title)) {
            return (string) $activity->title;
        }

        return 'Run';
    }

    private function resolveDashboardLocalizedText(mixed $value): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        foreach (['en', 'de', 'fr', 'ja'] as $locale) {
            $candidate = $value[$locale] ?? null;

            if (filled($candidate)) {
                return (string) $candidate;
            }
        }

        return null;
    }

    /**
     * @return array{id: int|null, name: string|null, avatar_url: string|null}
     */
    private function serializeGroupUserIdentity(?User $user): array
    {
        $primaryCharacter = $user?->primaryCharacter;

        return [
            'id' => $user?->id,
            'name' => $primaryCharacter?->name ?? $user?->name,
            'avatar_url' => $primaryCharacter?->avatar_url ?? $user?->avatar_url,
        ];
    }
}
