<?php

namespace App\Services\Runs;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivityType;
use App\Models\User;
use App\Services\Groups\ActivitySlotKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class RunBrowseService
{
    private const ROW_LIMIT = 12;

    public function __construct(
        private readonly RunAccessService $access,
        private readonly RunCardPresenter $cards,
        private readonly RunSearchActivityRanking $activityRanking,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function sections(User $user): array
    {
        $preferredTypes = $this->preferredTypes($user);
        $base = $this->browseQuery($user);
        $recommended = clone $base;
        $typeIds = $preferredTypes->modelKeys();
        if ($typeIds !== []) {
            $placeholders = implode(',', array_fill(0, count($typeIds), '?'));
            $recommended->orderByRaw("CASE WHEN activity_type_id IN ($placeholders) THEN 0 ELSE 1 END", $typeIds);
        }

        $rows = [
            ['id' => 'recommended', 'title_key' => 'recommended', 'activities' => $recommended
                ->orderBy('starts_at')->orderBy('id')->limit(self::ROW_LIMIT)->get()],
            ['id' => 'recently-posted', 'title_key' => 'recently_posted', 'activities' => (clone $base)
                ->orderByDesc('created_at')->orderByDesc('id')->limit(self::ROW_LIMIT)->get()],
            ['id' => 'almost-full', 'title_key' => 'almost_full', 'activities' => (clone $base)
                ->whereIn('id', ActivitySlotKind::mainRosterQuery(ActivitySlot::query())->select('activity_id')
                    ->groupBy('activity_id')
                    ->havingRaw('SUM(CASE WHEN assigned_character_id IS NOT NULL THEN 1 ELSE 0 END) >= 0.75 * COUNT(*)'))
                ->orderBy('starts_at')->orderBy('id')->limit(self::ROW_LIMIT)->get()],
        ];

        foreach ($preferredTypes as $type) {
            $runs = (clone $base)->where('activity_type_id', $type->id)
                ->orderBy('starts_at')->orderBy('id')->limit(self::ROW_LIMIT)->get();
            if ($runs->isNotEmpty()) {
                $rows[] = ['id' => 'more-'.$type->slug, 'title_key' => 'more_activity',
                    'activity_name' => $type->currentPublishedVersion?->name, 'activities' => $runs];
            }
        }

        $rows[] = ['id' => 'latest-content', 'title_key' => 'latest_content', 'activities' => (clone $base)
            ->whereHas('activityType', fn (Builder $types) => $types->whereIn('slug', config('run_discovery.current_content.foray')))
            ->orderBy('starts_at')->orderBy('id')->limit(self::ROW_LIMIT)->get()];

        $activities = new Collection(collect($rows)->flatMap(fn (array $row) => $row['activities'])->unique('id')->values()->all());
        $cards = $this->cards->cards($activities, $user->id);

        return array_map(fn (array $row): array => [
            'id' => $row['id'],
            'title_key' => $row['title_key'],
            'activity_name' => $row['activity_name'] ?? null,
            'runs' => $row['activities']->map(fn (Activity $activity) => $cards[$activity->id])->all(),
        ], $rows);
    }

    /** @return array<int, array<string, mixed>> */
    public function trendingActivities(User $user): array
    {
        $upcomingCounts = $this->browseQuery($user)->where('starts_at', '<=', now()->addDays(30))
            ->selectRaw('activity_type_id, COUNT(*) AS run_count')
            ->groupBy('activity_type_id')->pluck('run_count', 'activity_type_id');
        $activities = ActivityType::query()->where('is_active', true)->whereNotNull('current_published_version_id')
            ->with('currentPublishedVersion:id,name,banner_image_url,small_image_url')
            ->get(['id', 'slug', 'current_published_version_id'])
            ->map(function (ActivityType $type) use ($upcomingCounts): array {
                $version = $type->currentPublishedVersion;

                return [
                    'id' => $type->id, 'value' => $type->slug, 'name' => $version->name,
                    'image_url' => $version->banner_image_url ?: $version->small_image_url,
                    'run_count' => (int) ($upcomingCounts[$type->id] ?? 0),
                ];
            });

        return $this->activityRanking->orderTrendingActivities($activities)->take(10)->all();
    }

    /** @return Builder<Activity> */
    private function browseQuery(User $user): Builder
    {
        return $this->access->scopeVisibleTo(Activity::query(), $user->id, includeDrafts: false)
            ->where('starts_at', '>', now())
            ->whereNotIn('status', Activity::ARCHIVED_STATUSES)
            ->whereHas('slots', fn (Builder $slots) => ActivitySlotKind::mainRosterQuery($slots)->whereNull('assigned_character_id'));
    }

    /** @return Collection<int, ActivityType> */
    private function preferredTypes(User $user): Collection
    {
        $ids = $this->access->scopeVisibleTo(Activity::query(), $user->id, includeDrafts: false)
            ->whereBetween('starts_at', [now()->subDays(90), now()->addDays(30)])
            ->where('status', '!=', Activity::STATUS_CANCELLED)
            ->where(fn (Builder $runs) => $runs
                ->whereHas('applications', fn (Builder $applications) => $applications->where('user_id', $user->id)
                    ->whereIn('status', ActivityApplication::ACTIVE_STATUSES))
                ->orWhereHas('slots.assignedCharacter', fn (Builder $characters) => $characters->where('user_id', $user->id)))
            ->selectRaw('activity_type_id, COUNT(*) AS participation_count, MAX(starts_at) AS latest_start')
            ->groupBy('activity_type_id')->orderByDesc('participation_count')->orderByDesc('latest_start')
            ->orderBy('activity_type_id')->limit(2)->pluck('activity_type_id');

        return ActivityType::query()->with('currentPublishedVersion')->whereIn('id', $ids)
            ->get()->sortBy(fn (ActivityType $type) => $ids->search($type->id))->values();
    }
}
