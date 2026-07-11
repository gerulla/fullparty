<?php

namespace Database\Seeders;

use App\Models\GroupAvailabilitySetting;
use App\Models\GroupAvailabilityWindow;
use App\Models\GroupMembership;
use App\Services\Groups\GroupAvailabilityScheduleService;
use Illuminate\Database\Seeder;

class GroupAvailabilitySeeder extends Seeder
{
    public function run(GroupAvailabilityScheduleService $scheduleService): void
    {
        $seededSchedules = 0;

        GroupMembership::query()
            ->with(['group.features', 'user'])
            ->orderBy('id')
            ->chunkById(100, function ($memberships) use ($scheduleService, &$seededSchedules): void {
                foreach ($memberships as $membership) {
                    if (! $membership->group || ! $membership->user) {
                        continue;
                    }

                    $group = $membership->group;
                    $cycleWeeks = fake()->randomElement([1, 2, 4]);
                    $windows = [];

                    for ($cycleWeek = 0; $cycleWeek < $cycleWeeks; $cycleWeek++) {
                        $weekdays = fake()->randomElements(range(1, 7), 5);

                        foreach ($weekdays as $weekday) {
                            $startMinute = fake()->numberBetween(32, 42) * 30;
                            $maximumDurationSlots = intdiv(1440 - $startMinute, 30);
                            $endMinute = $startMinute + (fake()->numberBetween(6, $maximumDurationSlots) * 30);

                            $windows[] = [
                                'cycle_week' => $cycleWeek,
                                'weekday' => $weekday,
                                'status' => fake()->boolean(80)
                                    ? GroupAvailabilityWindow::STATUS_AVAILABLE
                                    : GroupAvailabilityWindow::STATUS_TENTATIVE,
                                'starts_at' => $this->formatMinute($startMinute),
                                'ends_at' => $endMinute === 1440 ? '00:00' : $this->formatMinute($endMinute),
                            ];
                        }
                    }

                    $group->features()->updateOrCreate([], [
                        'availability_scheduler_enabled' => true,
                    ]);
                    $group->availabilitySettings()->updateOrCreate([], [
                        'minimum_role' => GroupAvailabilitySetting::MINIMUM_ROLE_MEMBER,
                    ]);

                    $scheduleService->save($group, $membership->user, [
                        'cycle_weeks' => $cycleWeeks,
                        'repeats' => true,
                        'lock_weekends' => false,
                        'on_hiatus' => false,
                        'starts_on' => now()->startOfWeek()->toDateString(),
                        'timezone' => $group->active_timezone ?: 'UTC',
                        'windows' => $windows,
                        'exceptions' => [],
                    ]);

                    $seededSchedules++;
                }
            });

        $this->command?->info("Seeded {$seededSchedules} group availability schedules.");
    }

    private function formatMinute(int $minute): string
    {
        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }
}
