<?php

namespace App\Services\Groups;

use App\Models\Group;
use App\Models\GroupAvailabilityException;
use App\Models\GroupAvailabilitySchedule;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class GroupAvailabilityScheduleService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function save(Group $group, User $user, array $data): GroupAvailabilitySchedule
    {
        $schedule = DB::transaction(function () use ($group, $user, $data): GroupAvailabilitySchedule {
            $schedule = GroupAvailabilitySchedule::query()->updateOrCreate(
                [
                    'group_id' => $group->id,
                    'user_id' => $user->id,
                ],
                [
                    'cycle_weeks' => $data['cycle_weeks'],
                    'repeats' => $data['repeats'],
                    'lock_weekends' => $data['lock_weekends'],
                    'on_hiatus' => (bool) ($data['on_hiatus'] ?? false),
                    'starts_on' => $data['starts_on'],
                    'timezone' => $data['timezone'],
                ],
            );

            $schedule->windows()->delete();
            $schedule->windows()->createMany(collect($data['windows'])->map(fn (array $window) => [
                'cycle_week' => $window['cycle_week'],
                'weekday' => $window['weekday'],
                'status' => $window['status'],
                'starts_minute' => $this->minuteOfDay($window['starts_at']),
                'ends_minute' => $this->endMinute($window['starts_at'], $window['ends_at']),
            ])->all());

            $schedule->exceptions()->delete();
            $schedule->exceptions()->createMany(collect($data['exceptions'])->map(function (array $exception) {
                $hasTimeRange = filled($exception['starts_at'] ?? null) && filled($exception['ends_at'] ?? null);

                return [
                    'date' => $exception['date'],
                    'status' => GroupAvailabilityException::STATUS_UNAVAILABLE,
                    'starts_minute' => $hasTimeRange ? $this->minuteOfDay($exception['starts_at']) : null,
                    'ends_minute' => $hasTimeRange ? $this->endMinute($exception['starts_at'], $exception['ends_at']) : null,
                ];
            })->all());

            return $schedule->load(['windows', 'exceptions']);
        });

        $versionKey = "group_availability_version:{$group->id}";
        Cache::forever($versionKey, ((int) Cache::get($versionKey, 0)) + 1);

        return $schedule;
    }

    private function minuteOfDay(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return ($hours * 60) + $minutes;
    }

    private function endMinute(string $startsAt, string $endsAt): int
    {
        $start = $this->minuteOfDay($startsAt);
        $end = $this->minuteOfDay($endsAt);

        return $end <= $start ? $end + 1440 : $end;
    }
}
