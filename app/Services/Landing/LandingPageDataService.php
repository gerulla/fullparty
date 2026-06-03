<?php

namespace App\Services\Landing;

use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\User;
use App\Services\Groups\ActivitySlotBench;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class LandingPageDataService
{
    /**
     * @return array{this_week: array<string, mixed>}
     */
    public function forHome(?User $user = null): array
    {
        return [
            'this_week' => $this->thisWeek($user),
        ];
    }

    /**
     * @return array{start: string, end: string, days: array<int, array<string, mixed>>}
     */
    private function thisWeek(?User $user): array
    {
        $start = CarbonImmutable::now()->startOfWeek();
        $end = $start->addWeek();
        $activities = $this->discoverableActivitiesBetween($start, $end);

        return [
            'start' => $start->toDateString(),
            'end' => $end->subDay()->toDateString(),
            'days' => collect(range(0, 6))
                ->map(function (int $offset) use ($start, $activities, $user): array {
                    $date = $start->addDays($offset);
                    $dayActivities = $activities
                        ->filter(fn (Activity $activity): bool => $activity->starts_at?->isSameDay($date) ?? false)
                        ->values();

                    return [
                        'key' => $this->dayKey($date),
                        'date' => $date->toDateString(),
                        'is_today' => $date->isSameDay(CarbonImmutable::now()),
                        'hidden_run_count' => max(0, $dayActivities->count() - 2),
                        'runs' => $dayActivities
                            ->take(2)
                            ->map(fn (Activity $activity): array => $this->serializeRun($activity, $user))
                            ->values()
                            ->all(),
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    /**
     * @return Collection<int, Activity>
     */
    private function discoverableActivitiesBetween(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return Activity::query()
            ->with([
                'group' => fn ($query) => $query->select(['id', 'name', 'slug', 'datacenter', 'is_visible']),
                'activityTypeVersion' => fn ($query) => $query->select(['id', 'name', 'difficulty']),
                'slots' => fn ($query) => $query
                    ->select(['id', 'activity_id', 'group_key', 'assigned_character_id', 'sort_order'])
                    ->where('group_key', '!=', ActivitySlotBench::GROUP_KEY)
                    ->orderBy('sort_order')
                    ->orderBy('id'),
                'slots.assignedCharacter' => fn ($query) => $query->select(['id', 'name', 'avatar_url']),
            ])
            ->whereHas('group', fn (Builder $query) => $query->where('is_visible', true))
            ->whereNotIn('status', [
                Activity::STATUS_CANCELLED,
                ...Activity::MODERATOR_ONLY_STATUSES,
            ])
            ->whereNotNull('starts_at')
            ->where('starts_at', '>=', $start)
            ->where('starts_at', '<', $end)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRun(Activity $activity, ?User $user): array
    {
        $mainSlots = $activity->slots;
        $assignedSlots = $mainSlots
            ->filter(fn (ActivitySlot $slot): bool => $slot->assignedCharacter !== null)
            ->values();
        $visibleAssignedSlots = $assignedSlots->take(4);

        return [
            'id' => $activity->id,
            'title' => filled($activity->title) ? $activity->title : null,
            'activity_type_name' => $activity->activityTypeVersion?->name,
            'difficulty' => $activity->activityTypeVersion?->difficulty,
            'datacenter' => $activity->datacenter ?: $activity->group?->datacenter,
            'starts_at' => $activity->starts_at?->toIso8601String(),
            'application_status_key' => $this->applicationStatusKey($activity),
            'allow_guest_applications' => (bool) $activity->allow_guest_applications,
            'filled_slots' => $assignedSlots->count(),
            'total_slots' => $mainSlots->count(),
            'overflow_count' => max(0, $assignedSlots->count() - $visibleAssignedSlots->count()),
            'assigned_members' => $visibleAssignedSlots
                ->map(fn (ActivitySlot $slot): array => $this->serializeAssignedMember($slot))
                ->values()
                ->all(),
            'href' => $this->runHref($activity, $user),
        ];
    }

    private function applicationStatusKey(Activity $activity): string
    {
        return $activity->needs_application && $activity->acceptsApplications()
            ? 'open'
            : 'closed';
    }

    private function runHref(Activity $activity, ?User $user): ?string
    {
        if (! $activity->group) {
            return null;
        }

        if ($this->isHistoricalRun($activity)) {
            return null;
        }

        if (! $activity->allow_guest_applications && ! $user) {
            return route('login', [
                'locale' => app()->getLocale(),
            ]);
        }

        return route('groups.activities.overview', [
            'locale' => app()->getLocale(),
            'group' => $activity->group->slug,
            'activity' => $activity->id,
        ]);
    }

    private function isHistoricalRun(Activity $activity): bool
    {
        return $activity->status === Activity::STATUS_COMPLETE
            || ($activity->starts_at?->isBefore(CarbonImmutable::now()->startOfDay()) ?? false);
    }

    /**
     * @return array{id: int, name: string, initials: string, avatar_url: string|null}
     */
    private function serializeAssignedMember(ActivitySlot $slot): array
    {
        $character = $slot->assignedCharacter;
        $name = $character?->name ?? 'Player';

        return [
            'id' => (int) $character?->id,
            'name' => $name,
            'initials' => $this->initials($name),
            'avatar_url' => $character?->avatar_url,
        ];
    }

    private function initials(string $name): string
    {
        $initials = Str::of($name)
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))
            ->implode('');

        return $initials !== '' ? $initials : 'P';
    }

    private function dayKey(CarbonImmutable $date): string
    {
        return ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'][$date->isoWeekday() - 1];
    }
}
