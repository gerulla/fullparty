<?php

namespace App\Services\Search;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use App\Services\Runs\RunAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class GlobalSearchService
{
    public function __construct(private readonly RunAccessService $access) {}

    private const LIMIT_PER_SECTION = 6;

    private const CANDIDATE_LIMIT = 30;

    private const PAST_RUN_BUFFER_DAYS = 7;

    private const SUPPORTED_LOCALES = ['en', 'de', 'fr', 'ja'];

    /**
     * @return array{
     *     runs: array<int, array<string, mixed>>,
     *     past_runs: array<int, array<string, mixed>>,
     *     groups: array<int, array<string, mixed>>,
     *     activities: array<int, array<string, mixed>>
     * }
     */
    public function searchForUser(User $user, string $query): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [
                'runs' => [],
                'past_runs' => [],
                'groups' => [],
                'activities' => [],
            ];
        }

        $now = CarbonImmutable::now();

        return [
            'runs' => $this->searchRuns($user, $query, $now),
            'past_runs' => $this->searchRuns($user, $query, $now, past: true),
            'groups' => $this->searchGroups($user, $query),
            'activities' => $this->searchActivityTypes($query),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchRuns(User $user, string $query, CarbonImmutable $now, bool $past = false): array
    {
        $like = '%'.mb_strtolower($query).'%';

        return Activity::query()
            ->with([
                'activityType:id,slug,current_published_version_id',
                'activityType.currentPublishedVersion:id,activity_type_id,name,small_image_url,banner_image_url,difficulty',
                'activityType.tags' => fn ($relation) => $relation->select(['activity_tags.id', 'activity_tags.name']),
                'activityTypeVersion:id,activity_type_id,name,small_image_url,banner_image_url,difficulty',
                'group' => fn ($relation) => $relation->select([
                    'id',
                    'owner_id',
                    'name',
                    'slug',
                    'datacenter',
                    'is_visible',
                ]),
                'group.memberships' => fn ($relation) => $relation
                    ->select(['id', 'group_id', 'user_id', 'role'])
                    ->where('user_id', $user->id),
                'group.bans' => fn ($relation) => $relation
                    ->select(['id', 'group_id', 'user_id'])
                    ->where('user_id', $user->id),
            ])
            ->where(function (Builder $queryBuilder) use ($like): void {
                $queryBuilder
                    ->whereRaw('LOWER(COALESCE(title, \'\')) LIKE ?', [$like])
                    ->orWhereHas(
                        'activityType.tags',
                        fn (Builder $tagQuery) => $tagQuery->whereRaw('LOWER(activity_tags.name) LIKE ?', [$like])
                    );
            })
            ->tap(fn (Builder $queryBuilder) => $this->access->scopeVisibleTo($queryBuilder, $user->id))
            ->when($past,
                fn (Builder $queryBuilder) => $queryBuilder
                    ->where('starts_at', '>=', $now->subDays(self::PAST_RUN_BUFFER_DAYS))
                    ->where('starts_at', '<', $now),
                fn (Builder $queryBuilder) => $queryBuilder->where('starts_at', '>=', $now))
            ->orderBy('starts_at', $past ? 'desc' : 'asc')
            ->orderBy('title')
            ->orderBy('id')
            ->limit(self::CANDIDATE_LIMIT)
            ->get()
            ->take(self::LIMIT_PER_SECTION)
            ->map(fn (Activity $activity): array => $this->serializeRunResult($activity))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchGroups(User $user, string $query): array
    {
        $like = '%'.mb_strtolower($query).'%';

        return Group::query()
            ->withCount('memberships')
            ->with([
                'memberships' => fn ($relation) => $relation
                    ->select(['id', 'group_id', 'user_id', 'role'])
                    ->where('user_id', $user->id),
                'bans' => fn ($relation) => $relation
                    ->select(['id', 'group_id', 'user_id'])
                    ->where('user_id', $user->id),
            ])
            ->whereRaw('LOWER(groups.name) LIKE ?', [$like])
            ->where(function (Builder $queryBuilder) use ($user): void {
                $queryBuilder
                    ->where('groups.is_visible', true)
                    ->orWhereHas('memberships', fn (Builder $membershipQuery) => $membershipQuery->where('user_id', $user->id));
            })
            ->orderBy('groups.name')
            ->limit(self::CANDIDATE_LIMIT)
            ->get()
            ->filter(fn (Group $group): bool => ! $group->isBanned($user->id))
            ->take(self::LIMIT_PER_SECTION)
            ->map(fn (Group $group): array => $this->serializeGroupResult($group, $user))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchActivityTypes(string $query): array
    {
        $normalizedQuery = mb_strtolower($query);

        return ActivityType::query()
            ->with([
                'currentPublishedVersion:id,activity_type_id,name,small_image_url,banner_image_url,difficulty',
                'tags' => fn ($relation) => $relation->select(['activity_tags.id', 'activity_tags.name']),
            ])
            ->where('is_active', true)
            ->whereNotNull('current_published_version_id')
            ->orderBy('slug')
            ->get(['id', 'slug', 'current_published_version_id'])
            ->filter(function (ActivityType $activityType) use ($normalizedQuery): bool {
                $label = $this->activityTypeDisplayName($activityType);

                return str_contains(mb_strtolower($label), $normalizedQuery)
                    || str_contains(mb_strtolower($activityType->slug), $normalizedQuery)
                    || $activityType->tags->contains(
                        fn ($tag): bool => str_contains(mb_strtolower((string) $tag->name), $normalizedQuery)
                    );
            })
            ->take(self::LIMIT_PER_SECTION)
            ->map(fn (ActivityType $activityType): array => $this->serializeActivityTypeResult($activityType))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRunResult(Activity $activity): array
    {
        $group = $activity->group;
        $activityName = $this->activityDisplayName($activity);

        return [
            'type' => 'run',
            'id' => $activity->id,
            'title' => (string) $activity->title,
            'subtitle' => collect([$group?->name, $activityName])
                ->filter(fn (?string $value): bool => filled($value))
                ->implode(' · '),
            'meta' => $activity->starts_at?->toIso8601String(),
            'image_url' => $activity->activityTypeVersion?->small_image_url
                ?? $activity->activityTypeVersion?->banner_image_url
                ?? $activity->activityType?->currentPublishedVersion?->small_image_url
                ?? $activity->activityType?->currentPublishedVersion?->banner_image_url,
            'icon' => 'i-lucide-calendar-days',
            'url' => route('groups.activities.overview', [
                'group' => $group?->slug,
                'activity' => $activity->id,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeGroupResult(Group $group, User $user): array
    {
        $currentMembership = $group->memberships->first();
        $isMember = $currentMembership instanceof GroupMembership || $group->isOwnedBy($user->id);

        return [
            'type' => 'group',
            'id' => $group->id,
            'title' => $group->name,
            'subtitle' => $group->datacenter,
            'meta' => null,
            'image_url' => $group->profile_picture_url,
            'icon' => 'i-lucide-users',
            'url' => $isMember
                ? route('groups.dashboard', $group)
                : route('groups.index', ['group' => $group->slug]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeActivityTypeResult(ActivityType $activityType): array
    {
        $version = $activityType->currentPublishedVersion;

        return [
            'type' => 'activity',
            'id' => $activityType->id,
            'title' => $this->activityTypeDisplayName($activityType),
            'subtitle' => $version?->difficulty,
            'meta' => $activityType->slug,
            'image_url' => $version?->small_image_url ?? $version?->banner_image_url,
            'icon' => 'i-lucide-swords',
            'url' => route('dashboard.runs.index', ['activity_type' => $activityType->slug]),
        ];
    }

    private function activityDisplayName(Activity $activity): string
    {
        return $this->resolveLocalizedText($activity->activityTypeVersion?->name)
            ?? $this->resolveLocalizedText($activity->activityType?->currentPublishedVersion?->name)
            ?? 'Run';
    }

    private function activityTypeDisplayName(ActivityType $activityType): string
    {
        return $this->resolveLocalizedText($activityType->currentPublishedVersion?->name)
            ?? $activityType->slug;
    }

    private function resolveLocalizedText(mixed $value): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        $preferredLocales = Collection::make([
            app()->getLocale(),
            config('app.fallback_locale'),
            ...self::SUPPORTED_LOCALES,
        ])
            ->filter(fn (mixed $locale): bool => is_string($locale) && $locale !== '')
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
