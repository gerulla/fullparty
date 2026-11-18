<?php

namespace App\Services\Runs;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotCompositionHint;
use App\Models\ActivityType;
use App\Models\CharacterClass;
use App\Models\Group;
use App\Models\User;
use App\Services\Groups\ActivitySlotKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

final class RunDiscoveryService
{
    private const DEFAULT_RESULTS_PER_PAGE = 10;

    public function __construct(
        private readonly GeneratedRunImageService $generatedRunImageService,
        private readonly ActivitySlotKind $slotKind,
        private readonly RunAccessService $access,
        private readonly RunCardPresenter $cards,
        private readonly RunSearchActivityRanking $activityRanking,
    ) {}

    private const SUPPORTED_LOCALES = ['en', 'de', 'fr', 'ja'];

    private const REGION_LABELS = [
        'NA' => 'North America',
        'EU' => 'Europe',
        'JP' => 'Japan',
        'OCE' => 'Oceania',
    ];

    /**
     * @return array<string, mixed>
     */
    public function buildLookups(?User $user = null): array
    {
        return [
            ...$this->searchOptions($user),
            'class_options' => $this->classOptions(),
            'regions' => $this->regionOptions(),
            'datacenters' => $this->datacenterOptions(),
            'languages' => $this->languageOptions(),
            'run_styles' => Activity::RUN_STYLES,
            'intensities' => Activity::INTENSITIES,
            'voice_expectations' => config('group_discovery.voice_expectations', []),
        ];
    }

    /** @return array{activity_types: array, groups: array} */
    public function searchOptions(?User $user): array
    {
        return [
            'activity_types' => $this->activityTypeOptions(),
            'groups' => $this->groupOptions($user?->id),
        ];
    }

    /** @return Builder<Group> */
    public function filterableGroupsForUser(?int $userId): Builder
    {
        return Group::query()
            ->where(function (Builder $groups) use ($userId): void {
                $groups->where('is_visible', true);
                if ($userId !== null) {
                    $groups->orWhere('owner_id', $userId)
                        ->orWhereHas('memberships', fn (Builder $memberships) => $memberships->where('user_id', $userId));
                }
            })
            ->when($userId !== null, fn (Builder $groups) => $groups
                ->whereDoesntHave('bans', fn (Builder $bans) => $bans->where('user_id', $userId)));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, int>
     */
    public function discoverRunIdsForUser(User $user, array $filters): array
    {
        return $this->discoverActivitiesForUser($user, $filters)
            ->pluck('id')
            ->values()
            ->all();
    }

    /** @param  array<string, mixed>  $filters */
    public function countMatchesForUser(User $user, array $filters): int
    {
        return $this->matchingActivitiesForUser($user, $filters)->count();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function discoverResultsForUser(User $user, array $filters): array
    {
        $activities = $this->discoverActivitiesForUser($user, $filters);
        $filterTimezone = $this->resolveFilterTimezone($filters);
        $paginator = $this->paginateActivities($activities, $filters);
        $pageActivities = new \Illuminate\Database\Eloquent\Collection($paginator->items());
        $pageActivities->loadMissing($this->discoveryRelationsForUser($user->id));

        return [
            'ids' => $pageActivities
                ->pluck('id')
                ->values()
                ->all(),
            'items' => $pageActivities
                ->map(fn (Activity $activity): array => $this->serializeDiscoveryResultItem($activity, $user, $filterTimezone))
                ->values()
                ->all(),
            'cards' => $this->cards->cards($pageActivities, $user->id)->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function canUserInteractWithDiscoveryActivity(Activity $activity, User $user): bool
    {
        $activity->loadMissing($this->discoveryRelationsForUser($user->id));

        return $this->canUserDiscoverActivity($activity, $user->id)
            && $this->canUserTakeDiscoveryAction($activity, $user);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function activityTypeOptions(): array
    {
        $options = ActivityType::query()
            ->with('currentPublishedVersion')
            ->where('is_active', true)
            ->whereNotNull('current_published_version_id')
            ->get()
            ->map(function (ActivityType $activityType): array {
                $version = $activityType->currentPublishedVersion;

                return [
                    'id' => $activityType->id,
                    'value' => $activityType->slug,
                    'label' => $this->resolveLocalizedText($version?->name) ?? $activityType->slug,
                    'small_image_url' => $version?->small_image_url,
                    'difficulty' => $version?->difficulty,
                    'prog_points' => collect($version?->prog_points ?? [])
                        ->map(fn (array $progPoint): array => [
                            'value' => (string) ($progPoint['key'] ?? ''),
                            'label' => $this->resolveLocalizedText($progPoint['label'] ?? null) ?? (string) ($progPoint['key'] ?? ''),
                        ])
                        ->filter(fn (array $progPoint): bool => $progPoint['value'] !== '')
                        ->values()
                        ->all(),
                ];
            });

        return $this->activityRanking->orderOptions($options)->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Activity>
     */
    private function discoverActivitiesForUser(User $user, array $filters): Collection
    {
        return $this->sortActivities($this->matchingActivitiesForUser($user, $filters)->collect(), $filters['sort'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LazyCollection<int, Activity>
     */
    private function matchingActivitiesForUser(User $user, array $filters): LazyCollection
    {
        // Only eager-load relations used to determine access or match this search.
        // Card details are loaded separately for the returned page.
        $relations = ['group', 'group.memberships', 'group.bans', 'applications', 'slots', 'slots.assignedCharacter'];
        if (filled($filters['query'] ?? null) || filled($filters['activity_category'] ?? null)) {
            array_push($relations, 'activityType', 'activityTypeVersion', 'activityType.currentPublishedVersion');
        }
        if (filled($filters['query'] ?? null)) {
            $relations[] = 'activityType.tags';
        }
        if (filled($filters['role_category'] ?? null) || ! empty($filters['class_keys'])) {
            array_push($relations, 'slots.compositionHints', 'slots.compositionHints.characterClass');
        }

        $query = Activity::query()
            ->with(Arr::only($this->discoveryRelationsForUser($user->id), $relations))
            ->tap(fn (Builder $query) => $this->access->scopeVisibleTo($query, $user->id))
            ->whereNotNull('starts_at')
            ->whereNotIn('status', Activity::ARCHIVED_STATUSES);
        if (filled($filters['activity_type'] ?? null)) {
            $query->whereHas('activityType', fn (Builder $types) => $types->where('slug', $filters['activity_type']));
        }
        if (filled($filters['group'] ?? null)) {
            $query->whereHas('group', fn (Builder $groups) => $groups->where('slug', $filters['group']));
        }
        if ($filters['saved_only'] ?? false) {
            $query->whereHas('savedByUsers', fn (Builder $users) => $users->whereKey($user->id));
        }
        if ($filters['beginner_friendly'] ?? false) {
            $query->where('beginner_friendly', true);
        }
        foreach (['run_style', 'intensity'] as $column) {
            if (filled($filters[$column] ?? null)) {
                $query->where($column, $filters[$column]);
            }
        }

        return $query->lazyById(200)
            ->filter(fn (Activity $activity) => $this->canUserDiscoverActivity($activity, $user->id))
            ->filter(fn (Activity $activity) => $this->matchesFilters($activity, $filters))
            ->filter(fn (Activity $activity) => $this->canUserTakeDiscoveryAction($activity, $user));
    }

    /**
     * @return array<string, mixed>
     */
    private function discoveryRelationsForUser(int $userId): array
    {
        return [
            'group' => fn ($query) => $query->select([
                'id',
                'owner_id',
                'name',
                'slug',
                'profile_picture_url',
                'discord_invite_url',
                'datacenter',
                'is_visible',
                'group_type',
                'voice_expectation',
                'preferred_languages',
            ]),
            'group.memberships' => fn ($query) => $query
                ->select(['id', 'group_id', 'user_id', 'role'])
                ->where('user_id', $userId),
            'group.bans' => fn ($query) => $query
                ->select(['id', 'group_id', 'user_id'])
                ->where('user_id', $userId),
            'applications' => fn ($query) => $query
                ->select(['id', 'activity_id', 'user_id', 'status'])
                ->where('user_id', $userId)
                ->where('status', '!=', ActivityApplication::STATUS_WITHDRAWN),
            'savedByUsers' => fn ($query) => $query
                ->select(['users.id'])
                ->where('users.id', $userId),
            'activityType' => fn ($query) => $query->select(['id', 'slug', 'current_published_version_id']),
            'activityType.currentPublishedVersion' => fn ($query) => $query->select([
                'id',
                'activity_type_id',
                'name',
                'small_image_url',
                'banner_image_url',
                'difficulty',
                'prog_points',
            ]),
            'activityType.tags' => fn ($query) => $query->select(['activity_tags.id', 'activity_tags.name']),
            'activityTypeVersion' => fn ($query) => $query->select([
                'id',
                'activity_type_id',
                'name',
                'small_image_url',
                'banner_image_url',
                'difficulty',
                'prog_points',
            ]),
            'organizerCharacter' => fn ($query) => $query->select([
                'id',
                'name',
                'world',
                'avatar_url',
            ]),
            'slots' => fn ($query) => $query->select([
                'id',
                'activity_id',
                'slot_kind',
                'group_key',
                'assigned_character_id',
            ]),
            'slots.compositionHints' => fn ($query) => $query->select([
                'id',
                'activity_slot_id',
                'hint_type',
                'hint_key',
                'role_key',
                'character_class_id',
            ]),
            'slots.compositionHints.characterClass' => fn ($query) => $query->select([
                'id',
                'shorthand',
                'role',
            ]),
            'slots.assignedCharacter' => fn ($query) => $query->select([
                'id',
                'user_id',
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Activity>
     */
    private function paginateActivities(Collection $activities, array $filters): LengthAwarePaginator
    {
        $total = $activities->count();
        $perPage = (int) ($filters['per_page'] ?? self::DEFAULT_RESULTS_PER_PAGE);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $requestedPage = (int) ($filters['page'] ?? 1);
        $currentPage = min(max($requestedPage, 1), $lastPage);
        $pageItems = $activities
            ->forPage($currentPage, $perPage)
            ->values();

        return new LengthAwarePaginator(
            $pageItems,
            $total,
            $perPage,
            $currentPage,
        );
    }

    /**
     * @param  Collection<int, Activity>  $activities
     * @return Collection<int, Activity>
     */
    private function sortActivities(Collection $activities, mixed $sort): Collection
    {
        $normalizedSort = is_string($sort) && $sort !== '' ? $sort : 'starting_soonest';

        if (in_array($normalizedSort, ['most_full', 'least_full'], true)) {
            return $activities->sortBy(function (Activity $activity) use ($normalizedSort): array {
                $slots = $this->mainSlots($activity);
                // A run with no main roster has no fill percentage and sorts last in either direction.
                $rank = INF;
                if ($slots->isNotEmpty()) {
                    $fill = $slots->whereNotNull('assigned_character_id')->count() / $slots->count();
                    $rank = $normalizedSort === 'most_full' ? -$fill : $fill;
                }

                return [$rank, $this->dateTimestamp($activity->starts_at, PHP_INT_MAX), $activity->id];
            })->values();
        }

        return $activities
            ->sort(function (Activity $left, Activity $right) use ($normalizedSort): int {
                return match ($normalizedSort) {
                    'newest_posted' => $this->compareDateDesc($left->created_at, $right->created_at)
                        ?: ($right->id <=> $left->id),
                    'recently_updated' => $this->compareDateDesc($left->updated_at, $right->updated_at)
                        ?: ($right->id <=> $left->id),
                    'open_slots' => ($this->openMainSlotCount($right) <=> $this->openMainSlotCount($left))
                        ?: $this->compareDateAsc($left->starts_at, $right->starts_at)
                        ?: ($left->id <=> $right->id),
                    default => $this->compareDateAsc($left->starts_at, $right->starts_at)
                        ?: ($left->id <=> $right->id),
                };
            })
            ->values();
    }

    private function openMainSlotCount(Activity $activity): int
    {
        return $activity->slots
            ->filter(fn (ActivitySlot $slot) => $this->slotKind->isMainRoster($slot) && $slot->assigned_character_id === null)
            ->count();
    }

    private function compareDateAsc(mixed $left, mixed $right): int
    {
        return $this->dateTimestamp($left, PHP_INT_MAX) <=> $this->dateTimestamp($right, PHP_INT_MAX);
    }

    private function compareDateDesc(mixed $left, mixed $right): int
    {
        return $this->dateTimestamp($right, PHP_INT_MIN) <=> $this->dateTimestamp($left, PHP_INT_MIN);
    }

    private function dateTimestamp(mixed $value, int $fallback): int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        return $fallback;
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function classOptions(): array
    {
        return CharacterClass::query()
            ->orderBy('role')
            ->orderBy('shorthand')
            ->get(['id', 'name', 'shorthand', 'icon_url', 'role'])
            ->map(fn (CharacterClass $class): array => [
                'key' => $class->shorthand,
                'label' => $class->name,
                'shorthand' => $class->shorthand,
                'group' => $this->classRoleGroup($class->role),
                'icon_url' => $class->icon_url,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function regionOptions(): array
    {
        return collect(config('datacenters.regions', []))
            ->values()
            ->filter(fn (mixed $value) => is_string($value) && $value !== '')
            ->unique()
            ->values()
            ->map(fn (string $region): array => [
                'label' => self::REGION_LABELS[$region] ?? $region,
                'value' => $region,
            ])
            ->all();
    }

    /**
     * @return array<int, array{label: string, value: string, region: string|null}>
     */
    private function datacenterOptions(): array
    {
        return collect(config('datacenters.values', []))
            ->map(fn (string $value): array => [
                'label' => $value,
                'value' => $value,
                'region' => Group::regionForDatacenter($value),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function languageOptions(): array
    {
        $labels = [
            'en' => 'English',
            'de' => 'Deutsch',
            'fr' => 'Français',
            'ja' => '日本語',
        ];

        return collect(config('group_discovery.preferred_languages', []))
            ->filter(fn (mixed $value) => is_string($value) && $value !== '')
            ->values()
            ->map(fn (string $value): array => [
                'label' => $labels[$value] ?? strtoupper($value),
                'value' => $value,
            ])
            ->all();
    }

    /**
     * @return array<int, array{label: string, value: string, group_type: string}>
     */
    private function groupOptions(?int $userId): array
    {
        return $this->filterableGroupsForUser($userId)
            ->orderBy('name')
            ->get(['name', 'slug', 'group_type'])
            ->map(fn (Group $group): array => [
                'label' => $group->name,
                'value' => $group->slug,
                'group_type' => $group->group_type,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function matchesFilters(Activity $activity, array $filters): bool
    {
        $filterTimezone = $this->resolveFilterTimezone($filters);

        if (! $this->matchesSearch($activity, (string) ($filters['query'] ?? ''))) {
            return false;
        }

        if (! $this->matchesActivityCategory($activity, $filters['activity_category'] ?? null)) {
            return false;
        }

        if (! $this->matchesProgPoint($activity, $filters['prog_point'] ?? null)) {
            return false;
        }

        if (! $this->matchesRegionAndDatacenter($activity, $filters['region'] ?? null, $filters['datacenter'] ?? null)) {
            return false;
        }

        if (! $this->matchesDateRange($activity, $filters['date_range'] ?? null, $filterTimezone, $filters['date_from'] ?? null, $filters['date_to'] ?? null, $filters)) {
            return false;
        }

        if (! $this->matchesTimeOfDay($activity, $filters['time_of_day'] ?? null, $filterTimezone)) {
            return false;
        }

        if (! $this->matchesLanguage($activity, $filters['language'] ?? null)) {
            return false;
        }

        if (! $this->matchesRoleFilter($activity, $filters['role_category'] ?? null, $filters['class_keys'] ?? [])) {
            return false;
        }

        if (! $this->matchesGroupType($activity, $filters['group_type'] ?? null)) {
            return false;
        }

        if (! $this->matchesApplicationStatus($activity, $filters['application_status'] ?? null)) {
            return false;
        }

        if (! $this->matchesVoiceExpectation($activity, $filters['voice_expectation'] ?? null)) {
            return false;
        }

        return true;
    }

    private function canUserDiscoverActivity(Activity $activity, int $userId): bool
    {
        return $activity->group !== null
            && $this->access->canView($activity->group, $activity, $userId);
    }

    private function canUserTakeDiscoveryAction(Activity $activity, User $user): bool
    {
        $mainSlots = $this->mainSlots($activity);

        return RunAccessService::hasDiscoveryAction(
            $activity,
            $this->userHasExistingApplication($activity),
            $mainSlots->contains(fn (ActivitySlot $slot) => $slot->assigned_character_id === null),
            $mainSlots->contains(fn (ActivitySlot $slot) => (int) ($slot->assignedCharacter?->user_id ?? 0) === $user->id),
        );
    }

    private function userHasExistingApplication(Activity $activity): bool
    {
        return $activity->applications->isNotEmpty();
    }

    private function userHasSavedActivity(Activity $activity): bool
    {
        return $activity->savedByUsers->isNotEmpty();
    }

    private function canUserApplyToActivity(Activity $activity, User $user): bool
    {
        if (! $this->access->canApply($activity->group, $activity, $user->id)) {
            return false;
        }

        if (! $this->hasOpenMainSlot($activity)) {
            return false;
        }

        return $activity->applications->isEmpty();
    }

    private function hasOpenMainSlot(Activity $activity): bool
    {
        return $this->mainSlots($activity)->contains(
            fn (ActivitySlot $slot) => $slot->assigned_character_id === null
        );
    }

    /**
     * @return Collection<int, ActivitySlot>
     */
    private function mainSlots(Activity $activity): Collection
    {
        return $activity->slots
            ->filter(fn (ActivitySlot $slot) => $this->slotKind->isMainRoster($slot));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDiscoveryResultItem(Activity $activity, User $user, string $timezone): array
    {
        $group = $activity->group;
        $openMainSlots = $activity->slots
            ->filter(fn (ActivitySlot $slot) => $this->slotKind->isMainRoster($slot) && $slot->assigned_character_id === null);
        $mainSlots = $activity->slots
            ->filter(fn (ActivitySlot $slot) => $this->slotKind->isMainRoster($slot));
        $filledMainSlots = $mainSlots
            ->filter(fn (ActivitySlot $slot) => $slot->assigned_character_id !== null);
        $canManage = $group?->hasModeratorAccess($user->id) ?? false;
        $hasExistingApplication = $this->userHasExistingApplication($activity);
        $canApply = $this->canUserApplyToActivity($activity, $user);
        $region = Group::regionForDatacenter($activity->datacenter ?: $group?->datacenter);

        $secondaryLocationLabel = $activity->organizerCharacter?->world
            ?? ($region !== null ? (self::REGION_LABELS[$region] ?? $region) : null);

        return [
            'id' => $activity->id,
            'image_url' => $activity->targetProgPointBannerUrl()
                ?? $activity->activityTypeVersion?->small_image_url
                ?? $activity->activityTypeVersion?->banner_image_url
                ?? $activity->activityType?->currentPublishedVersion?->small_image_url
                ?? $activity->activityType?->currentPublishedVersion?->banner_image_url
                ?? $this->generatedRunImageService->generateResultImage($activity, $this->activityDisplayName($activity)),
            'title' => filled($activity->title) ? (string) $activity->title : $this->activityDisplayName($activity),
            'activity_type_name' => $this->activityDisplayName($activity),
            'difficulty' => $activity->activityTypeVersion?->difficulty
                ?? $activity->activityType?->currentPublishedVersion?->difficulty,
            'target_prog_point_key' => $activity->target_prog_point_key,
            'target_prog_point_label' => $this->targetProgPointLabel($activity),
            'group_name' => $group?->name,
            'group_slug' => $group?->slug,
            'group_type' => $group?->group_type,
            'group_profile_picture_url' => $group?->profile_picture_url,
            'group_discord_invite_url' => $group?->discord_invite_url,
            'organizer' => $activity->organizerCharacter ? [
                'name' => $activity->organizerCharacter->name,
                'avatar_url' => $activity->organizerCharacter->avatar_url,
            ] : null,
            'description' => filled($activity->notes) ? (string) $activity->notes : null,
            'min_item_level' => $activity->min_item_level,
            'run_style' => $activity->run_style,
            'intensity' => $activity->intensity,
            'voice_expectation' => $group?->voice_expectation,
            'beginner_friendly' => (bool) $activity->beginner_friendly,
            'allow_guest_applications' => (bool) $activity->allow_guest_applications,
            'starts_at' => $activity->starts_at?->copy()->setTimezone($timezone)->toIso8601String(),
            'datacenter' => $activity->datacenter ?: $group?->datacenter,
            'world' => $secondaryLocationLabel,
            'role_slots' => [
                [
                    'key' => 'tank',
                    'count' => $openMainSlots->filter(fn (ActivitySlot $slot) => $this->slotMatchesRoleCategory($slot, 'tank'))->count(),
                ],
                [
                    'key' => 'healer',
                    'count' => $openMainSlots->filter(fn (ActivitySlot $slot) => $this->slotMatchesRoleCategory($slot, 'healer'))->count(),
                ],
                [
                    'key' => 'dps',
                    'count' => $openMainSlots->filter(fn (ActivitySlot $slot) => $this->slotMatchesRoleCategory($slot, 'dps'))->count(),
                ],
            ],
            'filled_slots' => $filledMainSlots->count(),
            'total_slots' => $mainSlots->count(),
            'is_saved' => $this->userHasSavedActivity($activity),
            'has_existing_application' => $hasExistingApplication,
            'can_apply' => $canApply,
            'links' => [
                'view' => $canManage
                    ? route('groups.dashboard.activities.show', [
                        'locale' => app()->getLocale(),
                        'group' => $group?->slug,
                        'activity' => $activity->id,
                    ])
                    : route('groups.activities.overview', [
                        'locale' => app()->getLocale(),
                        'group' => $group?->slug,
                        'activity' => $activity->id,
                    ]),
                'apply' => ($canApply || $hasExistingApplication)
                    ? route('groups.activities.application', [
                        'locale' => app()->getLocale(),
                        'group' => $group?->slug,
                        'activity' => $activity->id,
                    ])
                    : null,
            ],
        ];
    }

    private function matchesSearch(Activity $activity, string $query): bool
    {
        $query = trim(mb_strtolower($query));

        if ($query === '') {
            return true;
        }

        $haystacks = [
            $activity->title,
            $activity->notes,
            $activity->group?->name,
            $activity->group?->slug,
            $activity->activityType?->slug,
            $this->activityDisplayName($activity),
            ...$this->activityTagNames($activity),
        ];

        foreach ($haystacks as $value) {
            if (is_string($value) && str_contains(mb_strtolower($value), $query)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function activityTagNames(Activity $activity): array
    {
        $activityType = $activity->activityType;

        if (! $activityType instanceof ActivityType) {
            return [];
        }

        return $activityType->tags
            ->pluck('name')
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();
    }

    private function matchesActivityCategory(Activity $activity, mixed $activityCategory): bool
    {
        if (! is_string($activityCategory) || $activityCategory === '') {
            return true;
        }

        return $this->activityDifficulty($activity) === $activityCategory;
    }

    private function activityDifficulty(Activity $activity): ?string
    {
        return $activity->activityTypeVersion?->difficulty
            ?? $activity->activityType?->currentPublishedVersion?->difficulty;
    }

    private function matchesProgPoint(Activity $activity, mixed $progPoint): bool
    {
        if (! is_string($progPoint) || $progPoint === '') {
            return true;
        }

        return $activity->target_prog_point_key === $progPoint;
    }

    private function matchesRegionAndDatacenter(Activity $activity, mixed $region, mixed $datacenter): bool
    {
        $activityDatacenter = $activity->datacenter ?: $activity->group?->datacenter;
        $activityRegion = Group::regionForDatacenter($activityDatacenter);

        if (is_string($datacenter) && $datacenter !== '' && $activityDatacenter !== $datacenter) {
            return false;
        }

        if (is_string($region) && $region !== '' && $activityRegion !== $region) {
            return false;
        }

        return true;
    }

    private function matchesDateRange(Activity $activity, mixed $dateRange, string $timezone, mixed $dateFrom = null, mixed $dateTo = null, array $filters = []): bool
    {
        if (! $activity->starts_at) {
            return false;
        }

        $normalizedDateRange = is_string($dateRange) && $dateRange !== '' ? $dateRange : 'upcoming';
        $startsAt = $activity->starts_at->copy()->setTimezone($timezone);
        $now = now()->setTimezone($timezone);

        if ($normalizedDateRange === 'flexible') {
            $months = $filters['date_months'] ?? [];
            $days = $filters['flexible_days'] ?? 'any';

            return $startsAt->greaterThanOrEqualTo($now)
                && ($months === [] || in_array($startsAt->format('Y-m'), $months, true))
                && ($days !== 'weekend' || $startsAt->isWeekend())
                && ($days !== 'weekday' || $startsAt->isWeekday());
        }

        [$rangeStart, $rangeEnd] = match ($normalizedDateRange) {
            'next_7_days' => [$now->copy(), $now->copy()->addDays(7)],
            'next_30_days' => [$now->copy(), $now->copy()->addDays(30)],
            'this_week' => [$now->copy(), $now->copy()->endOfWeek()],
            'next_week' => [$now->copy()->addWeek()->startOfWeek(), $now->copy()->addWeek()->endOfWeek()],
            'custom_day' => is_string($dateFrom) && $dateFrom !== ''
                ? [
                    CarbonImmutable::parse($dateFrom, $timezone)->startOfDay(),
                    CarbonImmutable::parse($dateFrom, $timezone)->endOfDay(),
                ]
                : [$now->copy(), null],
            'custom_range' => is_string($dateFrom) && $dateFrom !== '' && is_string($dateTo) && $dateTo !== ''
                ? [
                    CarbonImmutable::parse($dateFrom, $timezone)->startOfDay(),
                    CarbonImmutable::parse($dateTo, $timezone)->endOfDay(),
                ]
                : [$now->copy(), null],
            default => [$now->copy(), null],
        };

        if (in_array($normalizedDateRange, ['custom_day', 'custom_range'], true) && array_key_exists('date_flex_days', $filters)) {
            $tolerance = (int) ($filters['date_flex_days'] ?? 0);
            $rangeStart = $rangeStart->subDays($tolerance)->max($now);
            $rangeEnd = $rangeEnd?->addDays($tolerance);
        }

        if ($rangeEnd === null) {
            return $startsAt->greaterThanOrEqualTo($rangeStart);
        }

        return $rangeEnd->greaterThanOrEqualTo($rangeStart) && $startsAt->betweenIncluded($rangeStart, $rangeEnd);
    }

    private function matchesTimeOfDay(Activity $activity, mixed $timeOfDay, string $timezone): bool
    {
        if (! is_string($timeOfDay) || $timeOfDay === '' || $timeOfDay === 'any' || ! $activity->starts_at) {
            return true;
        }

        $hour = (int) $activity->starts_at->copy()->setTimezone($timezone)->format('G');

        return match ($timeOfDay) {
            'morning' => $hour >= 5 && $hour < 12,
            'afternoon' => $hour >= 12 && $hour < 17,
            'evening' => $hour >= 17 && $hour < 23,
            'night' => $hour >= 23 || $hour < 5,
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function resolveFilterTimezone(array $filters): string
    {
        $timezone = $filters['timezone'] ?? null;

        if (is_string($timezone) && $timezone !== '') {
            return $timezone;
        }

        return config('app.timezone', 'UTC');
    }

    private function matchesLanguage(Activity $activity, mixed $language): bool
    {
        if (! is_string($language) || $language === '') {
            return true;
        }

        return in_array($language, $activity->group?->preferred_languages ?? [], true);
    }

    /**
     * @param  array<int, mixed>  $classKeys
     */
    private function matchesRoleFilter(Activity $activity, mixed $roleCategory, array $classKeys): bool
    {
        $normalizedClassKeys = collect($classKeys)
            ->filter(fn (mixed $value) => is_string($value) && $value !== '')
            ->values()
            ->all();

        $normalizedRole = is_string($roleCategory) && $roleCategory !== '' ? $roleCategory : null;

        if ($normalizedRole === null && $normalizedClassKeys === []) {
            return true;
        }

        $openSlots = $activity->slots
            ->filter(fn (ActivitySlot $slot) => $this->slotKind->isMainRoster($slot) && $slot->assigned_character_id === null);

        if ($openSlots->isEmpty()) {
            return false;
        }

        if ($normalizedClassKeys !== []) {
            $hasMatchingClassSlot = $openSlots->contains(
                fn (ActivitySlot $slot): bool => $this->slotMatchesSelectedClasses($slot, $normalizedClassKeys)
            );

            if (! $hasMatchingClassSlot) {
                return false;
            }
        }

        if ($normalizedRole === null || $normalizedRole === 'any') {
            return true;
        }

        return $openSlots->contains(fn (ActivitySlot $slot) => $this->slotMatchesRoleCategory($slot, $normalizedRole));
    }

    /**
     * @param  array<int, string>  $selectedClassKeys
     */
    private function slotMatchesSelectedClasses(ActivitySlot $slot, array $selectedClassKeys): bool
    {
        $selectedRoleCategories = $this->selectedClassRoleCategories($selectedClassKeys);

        return $slot->compositionHints->contains(function (ActivitySlotCompositionHint $hint) use ($selectedClassKeys, $selectedRoleCategories): bool {
            if ($hint->hint_type === ActivitySlotCompositionHint::TYPE_CLASS) {
                return in_array($hint->hint_key, $selectedClassKeys, true);
            }

            if ($hint->hint_type !== ActivitySlotCompositionHint::TYPE_ROLE) {
                return false;
            }

            $hintRoleCategory = $this->normalizeRoleCategory((string) ($hint->role_key ?: $hint->hint_key));

            return $hintRoleCategory !== null && in_array($hintRoleCategory, $selectedRoleCategories, true);
        });
    }

    /**
     * @param  array<int, string>  $selectedClassKeys
     * @return array<int, string>
     */
    private function selectedClassRoleCategories(array $selectedClassKeys): array
    {
        static $roleCategoriesByShorthand = null;

        if ($roleCategoriesByShorthand === null) {
            $roleCategoriesByShorthand = CharacterClass::query()
                ->get(['shorthand', 'role'])
                ->mapWithKeys(fn (CharacterClass $class): array => [
                    $class->shorthand => $this->normalizeRoleCategory($class->role),
                ])
                ->all();
        }

        return collect($selectedClassKeys)
            ->map(fn (string $classKey) => $roleCategoriesByShorthand[$classKey] ?? null)
            ->filter(fn (?string $roleCategory) => $roleCategory !== null)
            ->values()
            ->all();
    }

    private function matchesGroupType(Activity $activity, mixed $groupType): bool
    {
        if (! is_string($groupType) || $groupType === '') {
            return true;
        }

        return $activity->group?->group_type === $groupType;
    }

    private function matchesApplicationStatus(Activity $activity, mixed $applicationStatus): bool
    {
        if (! is_string($applicationStatus) || $applicationStatus === '') {
            return true;
        }

        return match ($applicationStatus) {
            'applications_open' => $activity->needs_application && $activity->acceptsApplications(),
            'direct_join' => ! $activity->needs_application,
            default => true,
        };
    }

    private function matchesVoiceExpectation(Activity $activity, mixed $voiceExpectation): bool
    {
        if (! is_string($voiceExpectation) || $voiceExpectation === '') {
            return true;
        }

        return $activity->group?->voice_expectation === $voiceExpectation;
    }

    private function slotMatchesRoleCategory(ActivitySlot $slot, string $roleCategory): bool
    {
        return $slot->compositionHints->contains(function (ActivitySlotCompositionHint $hint) use ($roleCategory): bool {
            $hintRoleCategory = $this->normalizeHintRoleCategory($hint);

            return $hintRoleCategory !== null && $hintRoleCategory === $roleCategory;
        });
    }

    private function normalizeHintRoleCategory(ActivitySlotCompositionHint $hint): ?string
    {
        if ($hint->hint_type === ActivitySlotCompositionHint::TYPE_ROLE) {
            return $this->normalizeRoleCategory((string) ($hint->role_key ?: $hint->hint_key));
        }

        if ($hint->hint_type === ActivitySlotCompositionHint::TYPE_CLASS) {
            $classRole = $hint->characterClass?->role;

            if (! is_string($classRole) || $classRole === '') {
                return null;
            }

            return $this->normalizeRoleCategory($classRole);
        }

        return null;
    }

    private function normalizeRoleCategory(string $value): ?string
    {
        $normalized = strtolower(trim($value));

        if ($normalized === 'tank') {
            return 'tank';
        }

        if ($normalized === 'healer') {
            return 'healer';
        }

        if ($normalized === 'dps') {
            return 'dps';
        }

        if (str_contains($normalized, 'melee')) {
            return 'dps';
        }

        if (str_contains($normalized, 'magic') || str_contains($normalized, 'caster')) {
            return 'dps';
        }

        if (str_contains($normalized, 'phys') || str_contains($normalized, 'range') || str_contains($normalized, 'ranged')) {
            return 'dps';
        }

        return null;
    }

    private function classRoleGroup(string $role): string
    {
        return match ($role) {
            'tank' => 'tank',
            'healer' => 'healer',
            'melee dps' => 'melee',
            'physical ranged dps' => 'phys',
            'magic ranged dps' => 'magic',
            default => 'melee',
        };
    }

    private function activityDisplayName(Activity $activity): string
    {
        return $this->resolveLocalizedText($activity->activityTypeVersion?->name)
            ?? $this->resolveLocalizedText($activity->activityType?->currentPublishedVersion?->name)
            ?? (filled($activity->title) ? (string) $activity->title : 'Run');
    }

    private function targetProgPointLabel(Activity $activity): ?string
    {
        if (blank($activity->target_prog_point_key)) {
            return null;
        }

        $label = $activity->targetProgPoint()['label'] ?? null;

        return $this->resolveLocalizedText($label) ?? $activity->target_prog_point_key;
    }

    private function resolveLocalizedText(mixed $value): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        $preferredLocales = collect([
            app()->getLocale(),
            config('app.fallback_locale'),
            ...self::SUPPORTED_LOCALES,
        ])
            ->filter(fn (mixed $locale) => is_string($locale) && $locale !== '')
            ->unique()
            ->values();

        foreach ($preferredLocales as $locale) {
            $candidate = $value[$locale] ?? null;

            if (filled($candidate)) {
                return (string) $candidate;
            }
        }

        return null;
    }
}
