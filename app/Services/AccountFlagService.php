<?php

namespace App\Services;

use App\Models\CharacterTransfer;
use App\Models\User;

final class AccountFlagService
{
    /** Flags are derived from unresolved domain state; viewing them never dismisses them. */
    public function forUser(User $user): array
    {
        // Character onboarding and its resolution routes are currently local-only.
        if (! app()->environment('local')) {
            return [];
        }

        return CharacterTransfer::query()
            ->where('to_user_id', $user->id)
            ->whereNull('completed_at')
            ->whereHas('character', fn ($query) => $query->where('user_id', $user->id))
            ->with('character:id,name')
            ->latest('id')
            ->get(['id', 'character_id'])
            ->unique('character_id')
            ->map(fn (CharacterTransfer $transfer) => [
                'id' => 'character-transfer:'.$transfer->id,
                'severity' => 'warning',
                'title_key' => 'general.account_flags.transfer_title',
                'description_key' => 'general.account_flags.transfer_description',
                'parameters' => ['character' => $transfer->character->name],
                'url' => route('characters.claim', ['character' => $transfer->character_id], absolute: false),
            ])
            ->values()->all();
    }
}
