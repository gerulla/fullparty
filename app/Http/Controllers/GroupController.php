<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGroupRequest;
use App\Models\Activity;
use App\Models\ActivitySlotAssignment;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupMembershipApplication;
use App\Models\ScheduledRun;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Groups\FeaturedGroupService;
use App\Services\Groups\GeneratedGroupImageService;
use App\Services\Groups\MembershipApplicationFormSchemaService;
use App\Services\ManagedImageStorage;
use App\Services\Notifications\NotificationPreferenceSettingsService;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use App\Support\Groups\GroupDiscoveryBadgePalette;
use App\Support\Input\RequestTextInputSanitizer;
use App\Support\Seo\ServerMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GroupController extends Controller
{
    private const IMAGE_DIRECTORY = 'groups';

    private const DISCOVERY_PER_PAGE = 6;

    public function __construct(
        private readonly ManagedImageStorage $managedImageStorage,
        private readonly AuditLogger $auditLogger,
        private readonly RequestTextInputSanitizer $requestTextInputSanitizer,
        private readonly GroupDiscoveryBadgePalette $groupDiscoveryBadgePalette,
        private readonly GeneratedGroupImageService $generatedGroupImageService,
        private readonly MembershipApplicationFormSchemaService $membershipApplicationFormSchemaService,
        private readonly FeaturedGroupService $featuredGroupService,
        private readonly NotificationPreferenceSettingsService $notificationPreferenceSettingsService,
        private readonly ServerMeta $serverMeta,
    ) {}

    public function index(Request $request): Response
    {
        $user = auth()->user();

        $ownedGroups = $user->ownedGroups()
            ->with([
                'owner.primaryCharacter',
                'memberships.user.primaryCharacter',
                'scheduledRuns',
            ])
            ->get()
            ->sortBy('name')
            ->values()
            ->map(fn (Group $group) => $this->serializeGroupListItem($group, $user->id));

        $moderatedGroups = $user->moderatedGroups()
            ->with([
                'owner.primaryCharacter',
                'memberships.user.primaryCharacter',
                'scheduledRuns',
            ])
            ->get()
            ->sortBy('name')
            ->values()
            ->map(fn (Group $group) => $this->serializeGroupListItem($group, $user->id));

        $memberGroups = $user->memberGroups()
            ->with([
                'owner.primaryCharacter',
                'memberships.user.primaryCharacter',
                'scheduledRuns',
            ])
            ->get()
            ->sortBy('name')
            ->values()
            ->map(fn (Group $group) => $this->serializeGroupListItem($group, $user->id));

        $discoverGroups = $this->serializePaginatedGroups(
            $this->discoverGroupsQuery($user->id, 'created_at_desc')->paginate(
                perPage: self::DISCOVERY_PER_PAGE,
                pageName: 'discover_page',
                page: (int) $request->integer('discover_page', 1)
            ),
            $user->id
        );

        $metaGroupSlug = $request->query('group');
        $metaGroup = is_string($metaGroupSlug) && filled($metaGroupSlug)
            ? Group::query()
                ->visible()
                ->where('slug', $metaGroupSlug)
                ->first()
            : null;

        return Inertia::render('Dashboard/Groups/Index', [
            'ownedGroups' => $ownedGroups,
            'moderatedGroups' => $moderatedGroups,
            'memberGroups' => $memberGroups,
            'discoverGroups' => $discoverGroups,
        ])->withViewData('serverMeta', $metaGroup
            ? $this->serverMeta->group($metaGroup)
            : $this->serverMeta->groupDiscovery());
    }

    public function search(Request $request): JsonResponse
    {
        $this->requestTextInputSanitizer->sanitize($request, ['query', 'extra_tags']);

        $validated = $request->validate([
            'query' => ['nullable', 'string', 'max:255'],
            'group_type' => ['nullable', Rule::in(['all', ...Group::TYPES])],
            'experience_expectation' => ['nullable', Rule::in(config('group_discovery.experience_expectations', []))],
            'region' => ['nullable', 'string', Rule::in(array_values(array_unique(array_filter(config('datacenters.regions', [])))))],
            'size' => ['nullable', Rule::in(['1', '50', '100', '500'])],
            'sort_by' => ['nullable', Rule::in([
                'created_at_desc',
                'created_at_asc',
                'active_at_desc',
                'active_at_asc',
                'member_count_desc',
                'member_count_asc',
            ])],
            'join_mode' => ['nullable', Rule::in(Group::JOIN_MODES)],
            'primary_focuses' => ['nullable', 'array', 'max:'.count(config('group_discovery.primary_focuses', []))],
            'primary_focuses.*' => [Rule::in(config('group_discovery.primary_focuses', []))],
            'voice_expectation' => ['nullable', Rule::in(config('group_discovery.voice_expectations', []))],
            'preferred_languages' => ['nullable', 'array', 'max:'.count(config('group_discovery.preferred_languages', []))],
            'preferred_languages.*' => [Rule::in(config('group_discovery.preferred_languages', []))],
            'active_days' => ['nullable', 'array', 'max:'.count(config('group_discovery.active_days', []))],
            'active_days.*' => [Rule::in(config('group_discovery.active_days', []))],
            'extra_tags' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $user = $request->user();
        $paginator = $this->discoverGroupsQuery($user->id, (string) ($validated['sort_by'] ?? 'created_at_desc'));
        $this->applyDiscoverySearchFilters($paginator, $validated);

        $paginator = $paginator->paginate(
            perPage: self::DISCOVERY_PER_PAGE,
            page: (int) ($validated['page'] ?? 1)
        );

        return response()->json($this->serializePaginatedGroups($paginator, $user->id));
    }

    public function featured(Request $request): JsonResponse
    {
        $groups = $this->featuredGroupService->groups();

        return response()->json([
            'data' => $groups
                ->map(fn (Group $group) => $this->serializeFeaturedGroupItem($group))
                ->values()
                ->all(),
        ]);
    }

    public function details(Request $request, Group $group): JsonResponse
    {
        abort_unless($group->is_visible, 404);

        $group->loadMissing(['owner.primaryCharacter', 'memberships.user.primaryCharacter', 'scheduledRuns']);

        return response()->json([
            'data' => $this->serializeGroupDiscoveryDetail($group, (int) $request->user()->id),
        ]);
    }

    public function store(StoreGroupRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $uploadedProfilePictureUrl = $this->managedImageStorage->uploadImageIfPresent(
            $request->file('profile_picture'),
            self::IMAGE_DIRECTORY,
            true
        );
        $uploadedBannerImageUrl = $this->managedImageStorage->uploadImageIfPresent(
            $request->file('banner_image'),
            self::IMAGE_DIRECTORY,
        );

        $group = DB::transaction(function () use ($validated, $uploadedProfilePictureUrl, $uploadedBannerImageUrl) {
            $group = Group::create([
                'owner_id' => auth()->id(),
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'profile_picture_url' => $uploadedProfilePictureUrl,
                'banner_image_url' => $uploadedBannerImageUrl,
                'discord_invite_url' => $validated['discord_invite_url'] ?? null,
                'datacenter' => $validated['datacenter'],
                'is_visible' => $validated['is_visible'],
                'slug' => $validated['slug'],
                'group_type' => $validated['group_type'],
                'join_mode' => $validated['join_mode'],
                'primary_focuses' => $validated['primary_focuses'] ?? [],
                'experience_expectation' => $validated['experience_expectation'] ?? null,
                'voice_expectation' => $validated['voice_expectation'] ?? null,
                'preferred_languages' => $validated['preferred_languages'] ?? [],
                'tags' => $validated['tags'] ?? [],
                'active_timezone' => $validated['active_timezone'] ?? null,
                'active_days' => $validated['active_days'] ?? [],
                'active_start_time' => $validated['active_start_time'] ?? null,
                'active_end_time' => $validated['active_end_time'] ?? null,
            ]);

            if (blank($group->profile_picture_url)) {
                $group->profile_picture_url = $this->generatedGroupImageService->generateProfileImage(
                    $group->slug,
                    $group->name,
                    $group->datacenter,
                );
            }

            if (blank($group->banner_image_url)) {
                $group->banner_image_url = $this->generatedGroupImageService->generateBannerImage(
                    $group->slug,
                    $group->name,
                    $group->datacenter,
                );
            }

            $group->save();
            $this->membershipApplicationFormSchemaService->ensureDefaultForm($group);

            $group->memberships()->create([
                'user_id' => auth()->id(),
                'role' => GroupMembership::ROLE_OWNER,
                'joined_at' => now(),
            ]);

            if ($group->hasPermanentInvite()) {
                $group->ensureSystemInvite();
            }

            return $group;
        });

        $this->auditLogger->log(
            action: 'group.created',
            severity: AuditSeverity::MODERATION_CHANGE,
            scopeType: AuditScope::GROUP,
            scopeId: $group->id,
            message: 'audit_log.events.group.created',
            actor: auth()->user(),
            subject: $group,
            metadata: $this->groupAuditSnapshot($group),
        );

        return redirect()->route('groups.dashboard', $group)->with('success', 'group_created');
    }

    public function destroy(Group $group): RedirectResponse
    {
        if (! $group->isOwnedBy(auth()->id())) {
            abort(403);
        }

        $groupSnapshot = [
            'id' => $group->id,
            'name' => $group->name,
            'slug' => $group->slug,
        ];

        $this->managedImageStorage->deleteManagedImage($group->profile_picture_url, self::IMAGE_DIRECTORY);
        $this->managedImageStorage->deleteManagedImage($group->banner_image_url, self::IMAGE_DIRECTORY);
        $group->delete();

        $this->auditLogger->log(
            action: 'group.deleted',
            severity: AuditSeverity::SEVERE_CHANGE,
            scopeType: AuditScope::GROUP,
            scopeId: $groupSnapshot['id'],
            message: 'audit_log.events.group.deleted',
            actor: auth()->user(),
            subject: [
                'subject_type' => Group::class,
                'subject_id' => $groupSnapshot['id'],
            ],
            metadata: $groupSnapshot,
        );

        return redirect()->route('groups.index')->with('success', 'group_deleted');
    }

    private function serializeGroupListItem(Group $group, int $currentUserId): array
    {
        return [
            ...$this->serializeGroupInteractionState($group, $currentUserId),
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
            'owner' => $this->serializeDiscoveryUserIdentity($group->owner),
            'stats' => [
                'member_count' => $group->memberships->count(),
                'upcoming_run_count' => $group->scheduledRuns
                    ->whereIn('status', [
                        ScheduledRun::STATUS_SCHEDULED,
                        ScheduledRun::STATUS_UPCOMING,
                        ScheduledRun::STATUS_ONGOING,
                    ])
                    ->count(),
                'run_count' => $group->scheduledRuns->count(),
                'completed_run_count' => $group->scheduledRuns
                    ->where('status', ScheduledRun::STATUS_COMPLETE)
                    ->count(),
                'latest_member_join_at' => $group->memberships->max('joined_at'),
                'last_activity_at' => $this->resolveLastActivityAt($group),
            ],
        ];
    }

    private function serializeGroupDiscoveryDetail(Group $group, int $currentUserId): array
    {
        $discoverableRunsQuery = $group->activities();
        $allDiscoverableRuns = (clone $discoverableRunsQuery)
            ->with('activityTypeVersion.activityType')
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get();

        $recentRuns = (clone $discoverableRunsQuery)
            ->with(['activityTypeVersion.activityType', 'progressMilestones'])
            ->withCount([
                'slotAssignments as checked_in_assignment_count' => fn (Builder $query) => $query->whereIn('attendance_status', [
                    ActivitySlotAssignment::STATUS_CHECKED_IN,
                    ActivitySlotAssignment::STATUS_LATE,
                ]),
                'slots as assigned_slot_count' => fn (Builder $query) => $query->whereNotNull('assigned_character_id'),
            ])
            ->whereIn('status', [
                Activity::STATUS_COMPLETE,
                Activity::STATUS_CANCELLED,
            ])
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit(6)
            ->get();

        return array_merge($this->serializeGroupListItem($group, $currentUserId), [
            ...$this->serializeGroupInteractionState($group, $currentUserId, true),
            'activity_summary' => $this->serializeGroupActivitySummary($discoverableRunsQuery, $recentRuns),
            'recent_runs' => $recentRuns
                ->map(fn (Activity $activity) => $this->serializeGroupRecentRun($activity))
                ->values()
                ->all(),
            'content_summary' => $this->serializeGroupContentSummary($allDiscoverableRuns),
            'content_items' => $this->serializeGroupContentItems($allDiscoverableRuns),
            'team_members' => $this->serializeGroupTeamMembers($group),
        ]);
    }

    private function resolveLastActivityAt(Group $group)
    {
        $runActivity = $group->scheduledRuns->max('updated_at');

        if (! $runActivity) {
            return $group->updated_at;
        }

        return $group->updated_at && $group->updated_at->gt($runActivity)
            ? $group->updated_at
            : $runActivity;
    }

    private function serializeGroupActivitySummary(Builder|HasMany $publicRunsQuery, $recentRuns): array
    {
        $completedRuns = (clone $publicRunsQuery)
            ->where('status', Activity::STATUS_COMPLETE)
            ->count();
        $totalRuns = (clone $publicRunsQuery)->count();
        $recentWindowRuns = (clone $publicRunsQuery)
            ->where('starts_at', '>=', now()->subDays(28))
            ->whereNotIn('status', [
                Activity::STATUS_DRAFT,
                Activity::STATUS_CANCELLED,
            ])
            ->count();

        $averageTurnout = $recentRuns
            ->where('status', Activity::STATUS_COMPLETE)
            ->map(fn (Activity $activity) => $this->resolveRunTurnoutCount($activity))
            ->filter(fn (int $count) => $count > 0)
            ->avg();

        return [
            'completed_runs' => $completedRuns,
            'total_runs' => $totalRuns,
            'runs_per_week' => round($recentWindowRuns / 4, 1),
            'average_turnout' => $averageTurnout !== null ? round((float) $averageTurnout, 1) : 0,
        ];
    }

    private function serializeGroupRecentRun(Activity $activity): array
    {
        return [
            'id' => $activity->id,
            'status' => $activity->status,
            'starts_at' => $activity->starts_at?->toIso8601String(),
            'activity_name' => $this->resolveActivityDisplayName($activity),
            'activity_image_url' => $activity->activityTypeVersion?->small_image_url,
            'run_title' => filled($activity->title) && $activity->title !== $this->resolveActivityDisplayName($activity)
                ? $activity->title
                : null,
            'turnout_count' => $this->resolveRunTurnoutCount($activity),
            'progress_summary' => $this->resolveRunProgressSummary($activity),
        ];
    }

    private function serializeGroupContentSummary($publicRuns): array
    {
        $statusBreakdown = [
            [
                'status' => 'draft',
                'count' => (int) $publicRuns->where('status', Activity::STATUS_DRAFT)->count(),
            ],
            [
                'status' => 'scheduled',
                'count' => (int) $publicRuns->where('status', Activity::STATUS_SCHEDULED)->count(),
            ],
            [
                'status' => 'active',
                'count' => (int) $publicRuns->whereIn('status', [
                    Activity::STATUS_ASSIGNED,
                    Activity::STATUS_UPCOMING,
                    Activity::STATUS_ONGOING,
                ])->count(),
            ],
            [
                'status' => 'complete',
                'count' => (int) $publicRuns->where('status', Activity::STATUS_COMPLETE)->count(),
            ],
            [
                'status' => 'cancelled',
                'count' => (int) $publicRuns->where('status', Activity::STATUS_CANCELLED)->count(),
            ],
        ];

        return [
            'total_runs' => (int) $publicRuns->count(),
            'status_breakdown' => $statusBreakdown,
        ];
    }

    private function serializeGroupContentItems($publicRuns): array
    {
        $now = now();

        return $publicRuns
            ->groupBy(function (Activity $activity) {
                if ($activity->activity_type_version_id !== null) {
                    return 'version:'.$activity->activity_type_version_id;
                }

                return 'name:'.$this->resolveActivityDisplayName($activity);
            })
            ->map(function ($runs, string $key) use ($now) {
                /** @var Activity $representativeRun */
                $representativeRun = $runs->first();

                return [
                    'key' => $key,
                    'activity_name' => $this->resolveActivityDisplayName($representativeRun),
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

    private function serializeGroupTeamMembers(Group $group): array
    {
        return $group->memberships
            ->whereIn('role', [
                GroupMembership::ROLE_OWNER,
                GroupMembership::ROLE_ADMIN,
                GroupMembership::ROLE_MODERATOR,
            ])
            ->sortBy(fn (GroupMembership $membership) => match ($membership->role) {
                GroupMembership::ROLE_OWNER => 0,
                GroupMembership::ROLE_ADMIN => 1,
                GroupMembership::ROLE_MODERATOR => 2,
                default => 3,
            })
            ->values()
            ->map(fn (GroupMembership $membership) => [
                ...$this->serializeDiscoveryUserIdentity($membership->user),
                'role' => $membership->role,
                'joined_at' => $membership->joined_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array{id: int|null, name: string|null, avatar_url: string|null}
     */
    private function serializeDiscoveryUserIdentity(?User $user): array
    {
        $primaryCharacter = $user?->primaryCharacter;

        return [
            'id' => $user?->id,
            'name' => $primaryCharacter?->name ?? $user?->name,
            'avatar_url' => $primaryCharacter?->avatar_url ?? $user?->avatar_url,
        ];
    }

    private function resolveRunTurnoutCount(Activity $activity): int
    {
        $checkedInCount = (int) ($activity->checked_in_assignment_count ?? 0);

        if ($checkedInCount > 0) {
            return $checkedInCount;
        }

        return (int) ($activity->assigned_slot_count ?? 0);
    }

    private function resolveRunProgressSummary(Activity $activity): ?string
    {
        if (filled($activity->furthest_progress_key)) {
            $furthestLabel = $activity->progressMilestones
                ->firstWhere('milestone_key', $activity->furthest_progress_key)
                ?->milestone_label;

            $furthestText = $this->resolveLocalizedText($furthestLabel);

            if ($furthestText !== null && $activity->furthest_progress_percent !== null) {
                return sprintf('%s - %s%%', $furthestText, rtrim(rtrim((string) $activity->furthest_progress_percent, '0'), '.'));
            }

            if ($furthestText !== null) {
                return $furthestText;
            }
        }

        $milestone = $activity->progressMilestones
            ->filter(fn ($item) => $item->kills > 0 || $item->best_progress_percent !== null)
            ->sortBy('sort_order')
            ->last();

        if (! $milestone) {
            return null;
        }

        $milestoneLabel = $this->resolveLocalizedText($milestone->milestone_label);

        if ($milestoneLabel === null) {
            return null;
        }

        if ($milestone->kills > 0) {
            return sprintf('Cleared %s', $milestoneLabel);
        }

        if ($milestone->best_progress_percent !== null) {
            return sprintf(
                '%s - %s%%',
                $milestoneLabel,
                rtrim(rtrim((string) $milestone->best_progress_percent, '0'), '.')
            );
        }

        return null;
    }

    private function resolveActivityDisplayName(Activity $activity): string
    {
        $activityTypeVersionName = $this->resolveLocalizedText($activity->activityTypeVersion?->name);

        if ($activityTypeVersionName !== null) {
            return $activityTypeVersionName;
        }

        if (filled($activity->title)) {
            return (string) $activity->title;
        }

        return 'Run';
    }

    private function resolveLocalizedText($value): ?string
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

    private function discoverGroupsQuery(int $currentUserId, string $sortBy = 'created_at_desc'): Builder
    {
        $latestRunActivity = ScheduledRun::query()
            ->selectRaw('group_id, MAX(updated_at) as latest_run_activity_at')
            ->groupBy('group_id');

        $query = Group::query()
            ->select('groups.*')
            ->leftJoinSub($latestRunActivity, 'latest_run_activity', function ($join) {
                $join->on('latest_run_activity.group_id', '=', 'groups.id');
            })
            ->with([
                'owner.primaryCharacter',
                'memberships.user.primaryCharacter',
                'scheduledRuns',
            ])
            ->withCount('memberships')
            ->visible();

        $this->applyDiscoverySorting($query, $sortBy);

        return $query;
    }

    private function serializePaginatedGroups(LengthAwarePaginator $paginator, int $currentUserId): array
    {
        return [
            'data' => $paginator->getCollection()
                ->map(fn (Group $group) => $this->serializeGroupListItem($group, $currentUserId))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    private function serializeFeaturedGroupItem(Group $group): array
    {
        return [
            'id' => $group->id,
            'slug' => $group->slug,
            'name' => $group->name,
            'banner_image_url' => $group->banner_image_url,
            'experience_expectation' => $group->experience_expectation,
            'experience_badge' => $group->experience_expectation !== null
                ? [
                    'value' => $group->experience_expectation,
                    'color' => $this->groupDiscoveryBadgePalette->colorFor('experience_expectations', $group->experience_expectation),
                ]
                : null,
            'preferred_languages' => $group->preferred_languages ?? [],
            'tags' => $group->tags ?? [],
            'tag_badges' => $this->groupDiscoveryBadgePalette->tagBadges($group->tags ?? []),
            'stats' => [
                'member_count' => (int) ($group->memberships_count ?? 0),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyDiscoverySearchFilters(Builder $query, array $filters): void
    {
        $searchQuery = trim((string) ($filters['query'] ?? ''));
        $groupType = (string) ($filters['group_type'] ?? 'all');
        $experienceExpectation = $filters['experience_expectation'] ?? null;
        $region = $filters['region'] ?? null;
        $size = $filters['size'] ?? null;
        $joinMode = $filters['join_mode'] ?? null;
        $primaryFocuses = array_values(array_filter($filters['primary_focuses'] ?? [], fn ($value) => is_string($value) && $value !== ''));
        $voiceExpectation = $filters['voice_expectation'] ?? null;
        $preferredLanguages = array_values(array_filter($filters['preferred_languages'] ?? [], fn ($value) => is_string($value) && $value !== ''));
        $activeDays = array_values(array_filter($filters['active_days'] ?? [], fn ($value) => is_string($value) && $value !== ''));
        $extraTags = trim((string) ($filters['extra_tags'] ?? ''));

        if ($groupType !== '' && $groupType !== 'all') {
            $query->where('groups.group_type', $groupType);
        }

        if ($searchQuery !== '') {
            $like = '%'.mb_strtolower($searchQuery).'%';

            $query->where(function (Builder $queryBuilder) use ($like) {
                $queryBuilder
                    ->whereRaw('LOWER(groups.name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(groups.description, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(groups.slug) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(CAST(groups.tags AS TEXT)) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(CAST(groups.primary_focuses AS TEXT)) LIKE ?', [$like]);
            });
        }

        if (is_string($experienceExpectation) && $experienceExpectation !== '') {
            $query->where('groups.experience_expectation', $experienceExpectation);
        }

        if (is_string($region) && $region !== '') {
            $datacenters = collect(config('datacenters.regions', []))
                ->filter(fn (?string $mappedRegion) => $mappedRegion === $region)
                ->keys()
                ->values()
                ->all();

            if ($datacenters !== []) {
                $query->whereIn('groups.datacenter', $datacenters);
            }
        }

        if (is_string($size) && $size !== '') {
            $query->has('memberships', '>=', (int) $size);
        }

        if (is_string($joinMode) && $joinMode !== '') {
            $query->where('groups.join_mode', $joinMode);
        }

        $this->applyJsonArrayAnyMatchFilter($query, 'groups.primary_focuses', $primaryFocuses);

        if (is_string($voiceExpectation) && $voiceExpectation !== '') {
            $query->where('groups.voice_expectation', $voiceExpectation);
        }

        $this->applyJsonArrayAnyMatchFilter($query, 'groups.preferred_languages', $preferredLanguages);
        $this->applyJsonArrayAnyMatchFilter($query, 'groups.active_days', $activeDays);

        if ($extraTags !== '') {
            $query->whereRaw('LOWER(CAST(groups.tags AS TEXT)) LIKE ?', ['%'.mb_strtolower($extraTags).'%']);
        }
    }

    /**
     * @param  array<int, string>  $values
     */
    private function applyJsonArrayAnyMatchFilter(Builder $query, string $column, array $values): void
    {
        if ($values === []) {
            return;
        }

        $query->where(function (Builder $queryBuilder) use ($column, $values) {
            foreach ($values as $value) {
                $queryBuilder->orWhereJsonContains($column, $value);
            }
        });
    }

    private function applyDiscoverySorting(Builder $query, string $sortBy): void
    {
        $activityExpression = 'CASE
            WHEN latest_run_activity.latest_run_activity_at IS NOT NULL
                AND latest_run_activity.latest_run_activity_at > groups.updated_at
                THEN latest_run_activity.latest_run_activity_at
            ELSE groups.updated_at
        END';

        match ($sortBy) {
            'created_at_asc' => $query
                ->orderBy('groups.created_at')
                ->orderBy('groups.name'),
            'active_at_desc' => $query
                ->orderByRaw($activityExpression.' DESC')
                ->orderBy('groups.name'),
            'active_at_asc' => $query
                ->orderByRaw($activityExpression.' ASC')
                ->orderBy('groups.name'),
            'member_count_desc' => $query
                ->orderByDesc('memberships_count')
                ->orderBy('groups.name'),
            'member_count_asc' => $query
                ->orderBy('memberships_count')
                ->orderBy('groups.name'),
            default => $query
                ->orderByDesc('groups.created_at')
                ->orderBy('groups.name'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function groupAuditSnapshot(Group $group): array
    {
        return [
            'name' => $group->name,
            'slug' => $group->slug,
            'group_type' => $group->group_type,
            'join_mode' => $group->join_mode,
            'membership_application_schema' => $group->membership_application_schema ?? [],
            'profile_picture_url' => $group->profile_picture_url,
            'banner_image_url' => $group->banner_image_url,
            'datacenter' => $group->datacenter,
            'region' => $group->inferredRegion(),
            'is_visible' => $group->is_visible,
            'primary_focuses' => $group->primary_focuses ?? [],
            'experience_expectation' => $group->experience_expectation,
            'voice_expectation' => $group->voice_expectation,
            'preferred_languages' => $group->preferred_languages ?? [],
            'tags' => $group->tags ?? [],
            'active_timezone' => $group->active_timezone,
            'active_days' => $group->active_days ?? [],
            'active_start_time' => $group->active_start_time,
            'active_end_time' => $group->active_end_time,
        ];
    }

    /**
     * @return array{
     *     links: array{dashboard: string|null},
     *     current_user_role: string|null,
     *     notifications: array{enabled: bool},
     *     membership_application: array{pending: bool},
     *     permissions: array{can_join: bool, can_apply: bool, can_leave: bool, can_toggle_notifications: bool}
     * }
     */
    private function serializeGroupInteractionState(Group $group, int $currentUserId, bool $includeBanCheck = false): array
    {
        $currentMembership = $group->memberships->firstWhere('user_id', $currentUserId);
        $isMember = $currentMembership instanceof GroupMembership;
        $isBanned = $includeBanCheck ? $group->isBanned($currentUserId) : false;
        $hasPendingMembershipApplication = $group->membershipApplications()
            ->where('user_id', $currentUserId)
            ->where('status', GroupMembershipApplication::STATUS_PENDING)
            ->exists();

        return [
            'links' => [
                'dashboard' => $isMember
                    ? route('groups.dashboard', $group, false)
                    : null,
            ],
            'current_user_role' => $currentMembership?->role,
            'notifications' => [
                'enabled' => (bool) ($currentMembership?->notifications_enabled ?? true),
                'preferences' => $currentMembership instanceof GroupMembership
                    ? $this->notificationPreferenceSettingsService->serializeGroupPreferences($currentMembership->user, $group->id)
                    : [],
            ],
            'membership_application' => [
                'pending' => $hasPendingMembershipApplication,
            ],
            'permissions' => [
                'can_join' => ! $isBanned
                    && ! $isMember
                    && $group->allowsOpenJoin(),
                'can_apply' => ! $isBanned
                    && ! $isMember
                    && ! $hasPendingMembershipApplication
                    && $group->usesMembershipApplications()
                    && $group->is_visible,
                'can_leave' => $isMember && ! $group->isOwnedBy($currentUserId),
                'can_toggle_notifications' => $isMember,
            ],
        ];
    }
}
