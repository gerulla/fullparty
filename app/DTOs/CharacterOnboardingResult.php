<?php

namespace App\DTOs;

use App\Models\Character;

final readonly class CharacterOnboardingResult
{
    public function __construct(
        public Character $character,
        public string $outcome = 'added',
        public bool $transferred = false,
    ) {}
}
