<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class MyRunsCommitmentService
{
    /** @return array<int, array{id: int, starts_at: string, duration_hours: float|null}> */
    public function forUser(User $user): array
    {
        return Activity::query()
            ->whereNotNull('starts_at')
            ->whereNotIn('status', [Activity::STATUS_COMPLETE, Activity::STATUS_CANCELLED])
            ->where(function (Builder $query) use ($user): void {
                $query->whereHas('applications', fn (Builder $applications) => $applications
                    ->where('user_id', $user->id)
                    ->where('status', '!=', ActivityApplication::STATUS_WITHDRAWN))
                    ->orWhereHas('slots.assignedCharacter', fn (Builder $characters) => $characters
                        ->where('user_id', $user->id));
            })
            ->get(['id', 'starts_at', 'duration_hours'])
            ->map(fn (Activity $activity): array => [
                'id' => $activity->id,
                'starts_at' => $activity->starts_at->toIso8601String(),
                'duration_hours' => $activity->duration_hours === null ? null : (float) $activity->duration_hours,
            ])
            ->all();
    }
}
