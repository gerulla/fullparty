<?php

namespace App\DTOs;

/**
 * Immutable DTO representing a single job/class data.
 */
final readonly class JobData
{
    public function __construct(
        public string $name,
        public string $abbreviation,
        public int $level,
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'abbreviation' => $this->abbreviation,
            'level' => $this->level,
        ];
    }
}
