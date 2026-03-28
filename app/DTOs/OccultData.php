<?php

namespace App\DTOs;

/**
 * Immutable DTO representing Occult Crescent progression data including Phantom Jobs.
 */
final readonly class OccultData
{
    /**
     * @param array<string, PhantomJobData> $phantomJobs Indexed by abbreviation
     */
    public function __construct(
        public ?int $knowledgeLevel = null,
        public ?string $expText = null,
        public ?int $sanguineCipher = null,
        public array $phantomJobs = [],
    ) {}

    /**
     * Get normalized flat key-value array for storage.
     *
     * @return array<string, mixed>
     */
    public function toNormalized(): array
    {
        $normalized = [];

        if ($this->knowledgeLevel !== null) {
            $normalized['progression.occult.knowledge_level'] = $this->knowledgeLevel;
        }

        if ($this->expText !== null) {
            $normalized['progression.occult.exp_text'] = $this->expText;
        }

        if ($this->sanguineCipher !== null) {
            $normalized['progression.occult.sanguine_cipher'] = $this->sanguineCipher;
        }

        // Add phantom job data
        foreach ($this->phantomJobs as $abbreviation => $phantomJobData) {
            $normalized["phantom.{$abbreviation}.level"] = $phantomJobData->level;
            $normalized["phantom.{$abbreviation}.mastered"] = $phantomJobData->mastered;

            if ($phantomJobData->iconUrl !== null) {
                $normalized["phantom.{$abbreviation}.icon_url"] = $phantomJobData->iconUrl;
            }
        }

        return $normalized;
    }

    /**
     * Get phantom job by abbreviation.
     */
    public function getPhantomJob(string $abbreviation): ?PhantomJobData
    {
        return $this->phantomJobs[$abbreviation] ?? null;
    }

    /**
     * Get as array.
     */
    public function toArray(): array
    {
        $phantomJobsArray = [];

        foreach ($this->phantomJobs as $abbreviation => $phantomJobData) {
            $phantomJobsArray[$abbreviation] = $phantomJobData->toArray();
        }

        return [
            'knowledge_level' => $this->knowledgeLevel,
            'exp_text' => $this->expText,
            'sanguine_cipher' => $this->sanguineCipher,
            'phantom_jobs' => $phantomJobsArray,
        ];
    }

    /**
     * Check if any occult data was parsed.
     */
    public function isEmpty(): bool
    {
        return $this->knowledgeLevel === null
            && $this->sanguineCipher === null
            && empty($this->phantomJobs);
    }

    /**
     * Count total phantom jobs.
     */
    public function phantomJobCount(): int
    {
        return count($this->phantomJobs);
    }
}
