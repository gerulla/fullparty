<?php

namespace App\Services\Characters;

use App\Models\Character;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

final class CharacterClaimAccessService
{
    public function grant(User $user, Character $character): void
    {
        // A selection grants access to public claim UI only, never ownership or proof.
        Cache::put($this->key($user, $character), true, now()->addDays(2));
    }

    public function allows(User $user, Character $character): bool
    {
        return (int) $character->user_id === (int) $user->id
            || Cache::get($this->key($user, $character)) === true;
    }

    private function key(User $user, Character $character): string
    {
        return 'character-claim-access:'.$user->id.':'.$character->id;
    }
}
