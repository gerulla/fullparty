<?php

namespace App\Services\Groups;

use App\Models\ActivitySlot;

class ActivitySlotBench
{
    public const GROUP_KEY = 'bench';

    public function isBench(ActivitySlot $slot): bool
    {
        return $slot->group_key === self::GROUP_KEY;
    }
}
