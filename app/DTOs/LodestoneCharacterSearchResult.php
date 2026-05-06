<?php

namespace App\DTOs;

final readonly class LodestoneCharacterSearchResult
{
    public function __construct(
        public string $lodestoneId,
        public string $name,
        public string $world,
        public ?string $dataCenter,
        public ?string $avatarUrl,
        public string $profileUrl,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'lodestone_id' => $this->lodestoneId,
            'name' => $this->name,
            'world' => $this->world,
            'datacenter' => $this->dataCenter,
            'avatar_url' => $this->avatarUrl,
            'profile_url' => $this->profileUrl,
        ];
    }
}
