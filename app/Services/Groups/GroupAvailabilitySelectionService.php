<?php

namespace App\Services\Groups;

use App\Models\Group;
use App\Models\GroupAvailabilityWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class GroupAvailabilitySelectionService
{
    public function __construct(
        private readonly GroupAvailabilityOverviewService $availabilityResolver,
    ) {}

    /** @return array<string, mixed> */
    public function build(Group $group, CarbonImmutable $startsAt, CarbonImmutable $endsAt): array
    {
        $version = Cache::get("group_availability_version:{$group->id}", 0);
        $cacheKey = implode(':', [
            'group_availability_selection',
            $group->id,
            $version,
            $startsAt->utc()->timestamp,
            $endsAt->utc()->timestamp,
        ]);

        return Cache::remember($cacheKey, now()->addMinutes(5), fn () => $this->buildUncached(
            $group,
            $startsAt->utc(),
            $endsAt->utc(),
        ));
    }

    /** @return array<string, mixed> */
    private function buildUncached(Group $group, CarbonImmutable $startsAt, CarbonImmutable $endsAt): array
    {
        $participantUserIds = $group->availabilityParticipantUserIds();
        $memberships = $group->memberships()
            ->whereIn('user_id', $participantUserIds)
            ->with('user')
            ->get();
        $schedules = $group->availabilitySchedules()
            ->whereIn('user_id', $participantUserIds)
            ->with(['windows', 'exceptions'])
            ->get()
            ->keyBy('user_id');
        $slotCount = max(1, (int) ceil($startsAt->diffInMinutes($endsAt) / 30));
        $slots = collect(range(0, $slotCount - 1))->map(function (int $offset) use ($startsAt, $endsAt) {
            $slotStart = $startsAt->addMinutes($offset * 30);
            $slotEnd = $slotStart->addMinutes(30);

            return [
                'starts_at' => $slotStart->toIso8601String(),
                'ends_at' => ($slotEnd->greaterThan($endsAt) ? $endsAt : $slotEnd)->toIso8601String(),
                'available_count' => 0,
                'tentative_count' => 0,
            ];
        });
        $members = [];

        foreach ($memberships as $membership) {
            $schedule = $schedules->get($membership->user_id);
            $statuses = $slots->map(function (array $slot) use ($schedule) {
                if (! $schedule) {
                    return null;
                }

                $slotStart = CarbonImmutable::parse($slot['starts_at']);
                $midpoint = $slotStart->addSeconds((int) ($slotStart->diffInSeconds(
                    CarbonImmutable::parse($slot['ends_at']),
                ) / 2));

                return $this->availabilityResolver->statusAt(
                    $schedule,
                    $midpoint->setTimezone($schedule->timezone),
                );
            })->all();

            foreach ($statuses as $index => $status) {
                if ($status === GroupAvailabilityWindow::STATUS_AVAILABLE) {
                    $slots[$index] = [...$slots[$index], 'available_count' => $slots[$index]['available_count'] + 1];
                } elseif ($status === GroupAvailabilityWindow::STATUS_TENTATIVE) {
                    $slots[$index] = [...$slots[$index], 'tentative_count' => $slots[$index]['tentative_count'] + 1];
                }
            }

            $status = $this->overallStatus($statuses);
            $user = $membership->user;

            $members[] = [
                'id' => $user->id,
                'name' => $user->name,
                'avatar_url' => $user->avatar_url,
                'status' => $status,
                'slots' => $statuses,
            ];
        }

        $members = collect($members);
        $availableCount = $members->where('status', GroupAvailabilityWindow::STATUS_AVAILABLE)->count();
        $tentativeCount = $members->where('status', GroupAvailabilityWindow::STATUS_TENTATIVE)->count();
        $unavailableCount = $memberships->count() - $availableCount - $tentativeCount;
        $overlaps = $slots
            ->map(fn (array $slot) => [
                ...$slot,
                'unavailable_count' => $memberships->count()
                    - $slot['available_count']
                    - $slot['tentative_count'],
            ])
            ->sortByDesc(fn (array $slot) => $slot['available_count'] + $slot['tentative_count'])
            ->values();

        return [
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $endsAt->toIso8601String(),
            'total_members' => $memberships->count(),
            'available_count' => $availableCount,
            'tentative_count' => $tentativeCount,
            'unavailable_count' => $unavailableCount,
            'highest_overlap' => $overlaps->first()
                ? $overlaps->first()['available_count'] + $overlaps->first()['tentative_count']
                : 0,
            'best_time' => $overlaps->first(),
            'potential_overlaps' => $overlaps->take(5)->all(),
            'slots' => $slots->values()->all(),
            'members' => $members
                ->whereIn('status', [
                    GroupAvailabilityWindow::STATUS_AVAILABLE,
                    GroupAvailabilityWindow::STATUS_TENTATIVE,
                ])
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all(),
        ];
    }

    /** @param  array<int, string|null>  $statuses */
    private function overallStatus(array $statuses): string
    {
        if (collect($statuses)->contains(GroupAvailabilityWindow::STATUS_AVAILABLE)) {
            return GroupAvailabilityWindow::STATUS_AVAILABLE;
        }

        return collect($statuses)->contains(GroupAvailabilityWindow::STATUS_TENTATIVE)
            ? GroupAvailabilityWindow::STATUS_TENTATIVE
            : 'unavailable';
    }
}
