<?php

namespace App\Services\Runs;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Services\Groups\ActivitySlotKind;
use Illuminate\Database\Eloquent\Builder;

final class RunBookmarkState
{
    /** Viewer-specific facts for withExists/loadExists on a collection of visible runs. */
    public static function existsRelations(?int $userId): array
    {
        if ($userId === null) {
            return [];
        }

        return [
            'savedByUsers as is_saved' => fn (Builder $users) => $users->whereKey($userId),
            'applications as has_existing_application' => fn (Builder $applications) => $applications
                ->where('user_id', $userId)->where('status', '!=', ActivityApplication::STATUS_WITHDRAWN),
            'slots as has_open_main_slot' => fn (Builder $slots) => ActivitySlotKind::mainRosterQuery($slots)
                ->whereNull('assigned_character_id'),
            'slots as has_viewer_main_slot' => fn (Builder $slots) => ActivitySlotKind::mainRosterQuery($slots)
                ->whereHas('assignedCharacter', fn (Builder $characters) => $characters->where('user_id', $userId)),
        ];
    }

    /** @return array{is_saved: bool, can_bookmark: bool} */
    public static function forVisibleRun(Activity $activity, ?int $userId): array
    {
        return [
            'is_saved' => $userId !== null && (bool) $activity->is_saved,
            'can_bookmark' => $userId !== null && RunAccessService::hasDiscoveryAction(
                $activity,
                (bool) $activity->has_existing_application,
                (bool) $activity->has_open_main_slot,
                (bool) $activity->has_viewer_main_slot,
            ),
        ];
    }
}
