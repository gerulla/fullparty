<?php

namespace App\DTOs;

/**
 * Immutable DTO representing normalized Lodestone URLs for a character.
 */
final readonly class LodestoneUrls
{
    public function __construct(
        public string $lodestoneId,
        public string $profileUrl,
        public string $classJobUrl,
    ) {}

    /**
     * Create from a Lodestone ID.
     */
    public static function fromLodestoneId(string $lodestoneId): self
    {
        $baseUrl = config('lodestone.base_url');

        return new self(
            lodestoneId: $lodestoneId,
            profileUrl: "{$baseUrl}/character/{$lodestoneId}/",
            classJobUrl: "{$baseUrl}/character/{$lodestoneId}/class_job/",
        );
    }

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        return [
            'lodestone_id' => $this->lodestoneId,
            'profile_url' => $this->profileUrl,
            'class_job_url' => $this->classJobUrl,
        ];
    }
}
