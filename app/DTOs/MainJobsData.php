<?php

namespace App\DTOs;

/**
 * Immutable DTO representing all main jobs (combat + DoH/DoL) data.
 */
final readonly class MainJobsData
{
    /**
     * @param array<string, JobData> $jobs Indexed by abbreviation
     */
    public function __construct(
        public array $jobs,
    ) {}

    /**
     * Get normalized flat key-value array for storage.
     *
     * @return array<string, int>
     */
    public function toNormalized(): array
    {
        $normalized = [];

        foreach ($this->jobs as $abbreviation => $jobData) {
            $normalized["job.{$abbreviation}.level"] = $jobData->level;
        }

        return $normalized;
    }

    /**
     * Get job by abbreviation.
     */
    public function getJob(string $abbreviation): ?JobData
    {
        return $this->jobs[$abbreviation] ?? null;
    }

    /**
     * Get all jobs as array.
     */
    public function toArray(): array
    {
        $jobs = [];

        foreach ($this->jobs as $abbreviation => $jobData) {
            $jobs[$abbreviation] = $jobData->toArray();
        }

        return $jobs;
    }

    /**
     * Check if any jobs were parsed.
     */
    public function isEmpty(): bool
    {
        return empty($this->jobs);
    }

    /**
     * Count total jobs.
     */
    public function count(): int
    {
        return count($this->jobs);
    }
}
