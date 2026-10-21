<?php

namespace App\Services\Characters;

use App\Models\Character;

final readonly class XIVAuthCharacterSyncResult
{
    /**
     * @param  array<int, Character>  $characters
     * @param  array<int, array{name: string, lodestone_id: string}>  $conflicts
     */
    public function __construct(
        public array $characters = [],
        public array $conflicts = [],
        public int $createdCount = 0,
        public int $updatedCount = 0,
        public bool $transferred = false,
    ) {}

    public function hasConflicts(): bool
    {
        return $this->conflicts !== [];
    }

    public function firstCharacter(): ?Character
    {
        return $this->characters[0] ?? null;
    }
}
