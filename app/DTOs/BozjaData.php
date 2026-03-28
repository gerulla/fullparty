<?php

namespace App\DTOs;

/**
 * Immutable DTO representing Bozja/Resistance progression data.
 */
final readonly class BozjaData
{
    public function __construct(
        public int $resistanceRank,
        public ?string $mettleText = null,
        public ?int $currentMettle = null,
        public ?int $nextRankMettle = null,
    ) {}

    /**
     * Get normalized flat key-value array for storage.
     *
     * @return array<string, mixed>
     */
    public function toNormalized(): array
    {
        $normalized = [
            'progression.bozja.resistance_rank' => $this->resistanceRank,
        ];

        if ($this->mettleText !== null) {
            $normalized['progression.bozja.mettle_text'] = $this->mettleText;
        }

        if ($this->currentMettle !== null) {
            $normalized['progression.bozja.current_mettle'] = $this->currentMettle;
        }

        if ($this->nextRankMettle !== null) {
            $normalized['progression.bozja.next_rank_mettle'] = $this->nextRankMettle;
        }

        return $normalized;
    }

    /**
     * Get as array.
     */
    public function toArray(): array
    {
        return [
            'resistance_rank' => $this->resistanceRank,
            'mettle_text' => $this->mettleText,
            'current_mettle' => $this->currentMettle,
            'next_rank_mettle' => $this->nextRankMettle,
        ];
    }
}
