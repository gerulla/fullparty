<?php

namespace App\Services\Groups;

use App\Models\ActivitySlot;
use Illuminate\Database\Eloquent\Builder;

class ActivitySlotKind
{
    public const FILL_IN_GROUP_KEY = 'fill-ins';

    public static function mainRosterQuery(Builder $query): Builder
    {
        return $query->where(fn (Builder $slots) => $slots->whereNull('slot_kind')
            ->orWhereNotIn('slot_kind', [ActivitySlot::SLOT_KIND_BENCH, ActivitySlot::SLOT_KIND_FILL_IN]))
            ->where(fn (Builder $slots) => $slots->whereNull('group_key')->orWhere('group_key', '!=', ActivitySlotBench::GROUP_KEY));
    }

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
