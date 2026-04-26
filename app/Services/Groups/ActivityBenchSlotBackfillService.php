<?php

namespace App\Services\Groups;

use App\Models\Activity;

class ActivityBenchSlotBackfillService
{
    public function __construct(
        private readonly ActivitySlotBench $slotBench,
    ) {}

    public function ensureBenchSlots(Activity $activity): void
    {
        $benchSize = max(0, (int) ($activity->activityTypeVersion?->bench_size ?? 0));

        if ($benchSize === 0) {
            return;
        }

        $existingBenchSlots = $activity->slots
            ->filter(fn ($slot) => $this->slotBench->isBench($slot))
            ->sortBy('position_in_group')
            ->values();

        if ($existingBenchSlots->count() >= $benchSize) {
            return;
        }

        $sortOrder = ((int) $activity->slots->max('sort_order')) + 1;

        for ($position = $existingBenchSlots->count() + 1; $position <= $benchSize; $position++) {
            $activity->slots()->create([
                'group_key' => ActivitySlotBench::GROUP_KEY,
                'group_label' => ['en' => 'Bench'],
                'slot_key' => sprintf('%s-slot-%d', ActivitySlotBench::GROUP_KEY, $position),
                'slot_label' => ['en' => sprintf('Bench %d', $position)],
                'position_in_group' => $position,
                'sort_order' => $sortOrder,
            ]);

            $sortOrder++;
        }

        $activity->load('slots');
    }
}
