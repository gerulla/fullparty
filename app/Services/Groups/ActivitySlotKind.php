<?php

namespace App\Services\Groups;

use App\Models\ActivitySlot;

class ActivitySlotKind
{
    public const FILL_IN_GROUP_KEY = 'fill-ins';

    public function isBench(ActivitySlot $slot): bool
    {
        return $slot->slot_kind === ActivitySlot::SLOT_KIND_BENCH
            || $slot->group_key === ActivitySlotBench::GROUP_KEY;
    }

    public function isFillIn(ActivitySlot $slot): bool
    {
        return $slot->slot_kind === ActivitySlot::SLOT_KIND_FILL_IN;
    }

    public function isMainRoster(ActivitySlot $slot): bool
    {
        return ! $this->isBench($slot) && ! $this->isFillIn($slot);
    }
}
