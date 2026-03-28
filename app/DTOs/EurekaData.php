<?php

namespace App\DTOs;

/**
 * Immutable DTO representing Eureka progression data.
 */
final readonly class EurekaData
{
    public function __construct(
        public int $elementalLevel,
        public ?string $expText = null,
    ) {}

    /**
     * Get normalized flat key-value array for storage.
     *
     * @return array<string, mixed>
     */
    public function toNormalized(): array
    {
        $normalized = [
            'progression.eureka.elemental_level' => $this->elementalLevel,
        ];

        if ($this->expText !== null) {
            $normalized['progression.eureka.exp_text'] = $this->expText;
        }

        return $normalized;
    }

    /**
     * Get as array.
     */
    public function toArray(): array
    {
        return [
            'elemental_level' => $this->elementalLevel,
            'exp_text' => $this->expText,
        ];
    }
}
