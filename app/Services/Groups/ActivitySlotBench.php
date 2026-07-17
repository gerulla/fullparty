<?php

namespace App\Services\Groups;

use App\Models\ActivitySlot;

class ActivitySlotBench
{
    public const GROUP_KEY = 'bench';

    public function __construct(
        private readonly ActivitySlotKind $slotKind,
    ) {}

    public function isBench(ActivitySlot $slot): bool
    {
        return $this->slotKind->isBench($slot);
    }
}
