<?php

namespace App\DTOs;

/**
 * Immutable DTO representing scraped Lodestone character data.
 *
 * This DTO contains only parsed data from Lodestone.
 * It has NO knowledge of database models, users, or verification logic.
 */
final readonly class LodestoneCharacterData
{
    /**
     * @param array<string, mixed> $extraData Key-value pairs of additional scraped data (e.g., job levels, progression stats)
     */
    public function __construct(
        public string $lodestoneId,
        public string $profileUrl,
        public string $classJobUrl,
        public string $name,
        public string $world,
        public string $dataCenter,
        public string $avatarUrl,
        public ?string $portraitUrl = null,
        public string $bio = '',
        public array $extraData = [],
    ) {}

    /**
     * Create from profile parser results and optional class/job data.
     *
     * @param array<string, mixed> $profileData
     * @param array<string, mixed> $classJobData
     */
    public static function fromParsedData(
        LodestoneUrls $urls,
        array $profileData,
        array $classJobData = []
    ): self {
        return new self(
            lodestoneId: $urls->lodestoneId,
            profileUrl: $urls->profileUrl,
            classJobUrl: $urls->classJobUrl,
            name: $profileData['name'] ?? '',
            world: $profileData['world'] ?? '',
            dataCenter: $profileData['data_center'] ?? '',
            avatarUrl: $profileData['avatar_url'] ?? '',
			bio: $profileData['bio'] ?? '',
            portraitUrl: $profileData['portrait_url'] ?? null,
            extraData: array_merge($profileData['extra_data'] ?? [], $classJobData),
        );
    }

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        return [
            'lodestoneId' => $this->lodestoneId,
            'profileUrl' => $this->profileUrl,
            'classJobUrl' => $this->classJobUrl,
            'name' => $this->name,
            'world' => $this->world,
            'dataCenter' => $this->dataCenter,
            'avatarUrl' => $this->avatarUrl,
			'bio' => $this->bio,
            'portraitUrl' => $this->portraitUrl,
            'extraData' => $this->extraData,
        ];
    }

    /**
     * Get a specific extra data value by key.
     */
    public function getExtraData(string $key, mixed $default = null): mixed
    {
        return $this->extraData[$key] ?? $default;
    }

    /**
     * Check if extra data key exists.
     */
    public function hasExtraData(string $key): bool
    {
        return array_key_exists($key, $this->extraData);
    }
}
