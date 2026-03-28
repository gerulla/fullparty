<?php

namespace App\DTOs;

/**
 * Immutable DTO representing a single Phantom Job data.
 */
final readonly class PhantomJobData
{
    public function __construct(
        public string $name,
        public string $abbreviation,
        public int $level,
        public bool $mastered,
        public ?string $iconUrl = null,
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'abbreviation' => $this->abbreviation,
            'level' => $this->level,
            'mastered' => $this->mastered,
            'icon_url' => $this->iconUrl,
        ];
    }
}
