<?php

namespace App\Services\Groups;

use App\Models\Character;
use Illuminate\Validation\ValidationException;

final class GuestActivityCharacterService
{
    public function ensureEligible(array $applicant): void
    {
        if (Character::query()->where('lodestone_id', $applicant['lodestone_id'])
            ->where(fn ($query) => $query->whereNotNull('verified_at')->orWhereNotNull('claimed_at'))->exists()) {
            $this->rejectOwnedCharacter();
        }
    }

    /** Resolve inside the caller's transaction, without changing account ownership. */
    public function resolve(array $applicant): Character
    {
        Character::query()->firstOrCreate(['lodestone_id' => $applicant['lodestone_id']], [
            'user_id' => null,
            'is_primary' => false,
            'name' => $applicant['name'],
            'world' => $applicant['world'],
            'datacenter' => $applicant['datacenter'],
            'avatar_url' => $applicant['avatar_url'] ?? null,
            'add_method' => 'guest_application',
        ]);

        $character = Character::query()->where('lodestone_id', $applicant['lodestone_id'])->lockForUpdate()->firstOrFail();
        if ($character->verified_at !== null || $character->claimed_at !== null) {
            $this->rejectOwnedCharacter();
        }

        $character->fill([
            'name' => $applicant['name'],
            'world' => $applicant['world'],
            'datacenter' => $applicant['datacenter'],
            'avatar_url' => $applicant['avatar_url'] ?? null,
        ]);
        if ($character->isDirty()) {
            $character->save();
        }

        return $character;
    }

    private function rejectOwnedCharacter(): never
    {
        throw ValidationException::withMessages([
            'guest_applicant.lodestone_id' => __('errors.this_character_is_already_claimed_by_a_verified_fullparty_account'),
        ]);
    }
}
