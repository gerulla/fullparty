<?php

namespace App\Services\Groups;

use App\Models\Group;
use App\Models\GroupAvailabilitySchedule;
use App\Models\GroupAvailabilityWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class GroupAvailabilityOverviewService
{
    private const OVERVIEW_HOURS = 168;

    /**
     * @return array{starts_at: string, ends_at: string, member_count: int, buckets: array<int, array{starts_at: string, available_count: int, tentative_count: int}>}
     */
    public function build(Group $group, CarbonImmutable $now): array
    {
        $startsAt = $now->utc()->startOfHour();
        $schedules = $group->availabilitySchedules()
            ->with(['windows', 'exceptions'])
            ->get();

        $buckets = collect(range(0, self::OVERVIEW_HOURS - 1))
            ->map(fn (int $offset) => [
                'starts_at' => $startsAt->addHours($offset)->toIso8601String(),
                'available_count' => 0,
                'tentative_count' => 0,
            ]);

        foreach ($schedules as $schedule) {
            $this->addSchedule($buckets, $schedule);
        }

        return [
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $startsAt->addHours(self::OVERVIEW_HOURS)->toIso8601String(),
            'member_count' => $schedules->count(),
            'buckets' => $buckets->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, array{starts_at: string, available_count: int, tentative_count: int}>  $buckets
     */
    private function addSchedule(Collection $buckets, GroupAvailabilitySchedule $schedule): void
    {
        foreach ($buckets as $index => $bucket) {
            $localTime = CarbonImmutable::parse($bucket['starts_at'])
                ->addMinutes(30)
                ->setTimezone($schedule->timezone);
            $status = $this->statusAt($schedule, $localTime);

            if ($status === GroupAvailabilityWindow::STATUS_AVAILABLE) {
                $buckets[$index] = [...$bucket, 'available_count' => $bucket['available_count'] + 1];
            } elseif ($status === GroupAvailabilityWindow::STATUS_TENTATIVE) {
                $buckets[$index] = [...$bucket, 'tentative_count' => $bucket['tentative_count'] + 1];
            }
        }
    }

    public function statusAt(GroupAvailabilitySchedule $schedule, CarbonImmutable $localTime): ?string
    {
        if ($schedule->on_hiatus) {
            return null;
        }

        if ($this->isUnavailableByException($schedule, $localTime)) {
            return null;
        }

        $minute = ($localTime->hour * 60) + $localTime->minute;
        $statuses = collect([
            ...$this->matchingWindowStatuses($schedule, $localTime, $minute),
            ...$this->matchingWindowStatuses($schedule, $localTime->subDay(), $minute + 1440),
        ]);

        if ($statuses->contains(GroupAvailabilityWindow::STATUS_AVAILABLE)) {
            return GroupAvailabilityWindow::STATUS_AVAILABLE;
        }

        return $statuses->contains(GroupAvailabilityWindow::STATUS_TENTATIVE)
            ? GroupAvailabilityWindow::STATUS_TENTATIVE
            : null;
    }

    /** @return array<int, string> */
    private function matchingWindowStatuses(
        GroupAvailabilitySchedule $schedule,
        CarbonImmutable $windowDate,
        int $minute,
    ): array {
        $cycleWeek = $this->cycleWeekForDate($schedule, $windowDate);

        if ($cycleWeek === null) {
            return [];
        }

        return $schedule->windows
            ->where('cycle_week', $cycleWeek)
            ->where('weekday', $windowDate->isoWeekday())
            ->filter(fn ($window) => $minute >= $window->starts_minute && $minute < $window->ends_minute)
            ->pluck('status')
            ->all();
    }

    private function cycleWeekForDate(GroupAvailabilitySchedule $schedule, CarbonImmutable $date): ?int
    {
        $startsOn = CarbonImmutable::parse($schedule->starts_on->toDateString(), $schedule->timezone)
            ->startOfDay();
        $daysSinceStart = (int) $startsOn->diffInDays($date->startOfDay(), false);

        if ($daysSinceStart < 0) {
            return null;
        }

        $week = intdiv($daysSinceStart, 7);

        if (! $schedule->repeats && $week >= $schedule->cycle_weeks) {
            return null;
        }

        return $week % $schedule->cycle_weeks;
    }

    private function isUnavailableByException(
        GroupAvailabilitySchedule $schedule,
        CarbonImmutable $localTime,
    ): bool {
        $minute = ($localTime->hour * 60) + $localTime->minute;

        foreach ([
            [$localTime, $minute],
            [$localTime->subDay(), $minute + 1440],
        ] as [$exceptionDate, $exceptionMinute]) {
            $exception = $schedule->exceptions
                ->first(fn ($candidate) => $candidate->date?->toDateString() === $exceptionDate->toDateString());

            if (! $exception) {
                continue;
            }

            if ($exception->starts_minute === null || $exception->ends_minute === null) {
                return $exceptionDate->isSameDay($localTime);
            }

            if ($exceptionMinute >= $exception->starts_minute && $exceptionMinute < $exception->ends_minute) {
                return true;
            }
        }

        return false;
    }
}
