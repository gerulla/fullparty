<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\ActivitySlot;
use Closure;
use Illuminate\Support\Facades\DB;

final class ActivityRosterLock
{
    public function __construct(private readonly ActivitySlotStateTokenService $stateTokens) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $mutation
     * @return T
     */
    public function run(int $activityId, Closure $mutation): mixed
    {
        return DB::transaction(function () use ($activityId, $mutation) {
            // One parent lock also serializes conflicting writes to different slots.
            Activity::query()->select('id')->lockForUpdate()->findOrFail($activityId);

            return $mutation();
        });
    }

    public function refreshSlot(ActivitySlot $slot, string $expectedStateToken): void
    {
        $slot->setRelations([]);
        $slot->refresh()->load(['activity', 'fieldValues', 'assignments']);
        $this->stateTokens->assertMatches($slot, $expectedStateToken);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $mutation
     * @return T
     */
    public function forSlot(ActivitySlot $slot, Closure $mutation): mixed
    {
        $expectedState = $this->stateTokens->generate($slot);

        return $this->run((int) $slot->activity_id, function () use ($slot, $expectedState, $mutation) {
            $this->refreshSlot($slot, $expectedState);

            return $mutation();
        });
    }
}
