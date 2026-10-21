<?php

namespace App\Services\Characters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class CharacterTransferHistory
{
    /** Keep the original roster record, but do not credit unaccepted history to its new owner. */
    public function excludeUnaccepted(Builder $query, string $characterColumn, string $activityColumn): void
    {
        $query->whereNotExists(function (QueryBuilder $items) use ($characterColumn, $activityColumn) {
            $items->selectRaw('1')->from('character_transfer_runs as transfer_history')
                ->whereColumn('transfer_history.character_id', $characterColumn)
                ->whereColumn('transfer_history.activity_id', $activityColumn)
                ->where('transfer_history.category', 'history')
                ->where(fn (QueryBuilder $choice) => $choice->whereNull('transfer_history.choice')
                    ->orWhere('transfer_history.choice', 'discard'));
        });
    }
}
