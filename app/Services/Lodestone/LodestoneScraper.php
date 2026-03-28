<?php

namespace App\Services\Lodestone;

use App\DTOs\LodestoneCharacterData;
use App\DTOs\LodestoneUrls;
use App\Exceptions\LodestoneFetchException;
use App\Exceptions\LodestoneInvalidInputException;
use App\Exceptions\LodestoneParseException;
use App\Services\Lodestone\Parsers\LodestoneClassJobParser;
use App\Services\Lodestone\Parsers\LodestoneProfileParser;
use Illuminate\Support\Facades\Cache;

/**
 * Main orchestration service for scraping Lodestone character data.
 *
 * This service is COMPLETELY DECOUPLED from:
 * - Database / Eloquent models
 * - User authentication
 * - Character verification logic
 * - Business logic
 *
 * It only:
 * 1. Accepts input (Lodestone ID or URL)
 * 2. Fetches pages
 * 3. Parses HTML
 * 4. Returns immutable DTOs
 *
 * Usage:
 *   $scraper = app(LodestoneScraper::class);
 *   $data = $scraper->scrape('47431834');
 *   // OR
 *   $data = $scraper->scrape('https://na.finalfantasyxiv.com/lodestone/character/47431834/');
 *
 *   // Then YOU decide what to do with $data in your controller/service/job
 */
class LodestoneScraper
{
    public function __construct(
        private LodestoneInputNormalizer $normalizer,
        private LodestoneHttpClient $httpClient,
        private LodestoneProfileParser $profileParser,
        private LodestoneClassJobParser $classJobParser,
    ) {}

    /**
     * Scrape character data from Lodestone.
     *
     * @param string $lodestoneIdOrUrl Lodestone ID or full URL
     * @param bool $includeClassJobs Whether to scrape class/job data (can be disabled for faster scraping)
     * @return LodestoneCharacterData Immutable DTO with scraped data
     *
     * @throws LodestoneInvalidInputException
     * @throws LodestoneFetchException
     * @throws LodestoneParseException
     */
    public function scrape(string $lodestoneIdOrUrl, bool $includeClassJobs = true): LodestoneCharacterData
    {
        // 1. Normalize input to extract Lodestone ID and build URLs
        $urls = $this->normalizer->normalize($lodestoneIdOrUrl);

        // 2. Check cache (optional optimization)
        if ($cached = $this->getCached($urls->lodestoneId)) {
            return $cached;
        }

        // 3. Fetch profile page
        $profileHtml = $this->httpClient->fetch($urls->profileUrl);

        // 4. Parse profile page
        $profileData = $this->profileParser->parse($profileHtml);

        // 5. Optionally fetch and parse class/job page
        $classJobData = [];
        if ($includeClassJobs && config('lodestone.parse_class_jobs', true)) {
            try {
                $classJobHtml = $this->httpClient->fetch($urls->classJobUrl);
                $classJobData = $this->classJobParser->parse($classJobHtml);

                // Optional: Rate limiting delay
                $this->applyRateLimit();
            } catch (\Exception $e) {
                // Class/job parsing is optional - if it fails, continue without it
                // You could log this error if needed
                $classJobData = [];
            }
        }

        // 6. Build immutable DTO
        $characterData = LodestoneCharacterData::fromParsedData(
            $urls,
            $profileData,
            $classJobData
        );

        // 7. Cache result (optional optimization)
        $this->cache($characterData);

        return $characterData;
    }

    /**
     * Scrape only profile data (skip class/jobs for faster scraping).
     */
    public function scrapeProfile(string $lodestoneIdOrUrl): LodestoneCharacterData
    {
        return $this->scrape($lodestoneIdOrUrl, includeClassJobs: false);
    }

    /**
     * Get cached character data if available.
     */
    private function getCached(string $lodestoneId): ?LodestoneCharacterData
    {
        $cacheTtl = config('lodestone.cache_ttl');

        if (!$cacheTtl) {
            return null;
        }

        $cacheKey = "lodestone:character:{$lodestoneId}";

        $cached = Cache::get($cacheKey);

        if (!$cached) {
            return null;
        }

        // Reconstruct DTO from cached array
        return new LodestoneCharacterData(...$cached);
    }

    /**
     * Cache character data.
     */
    private function cache(LodestoneCharacterData $data): void
    {
        $cacheTtl = config('lodestone.cache_ttl');

        if (!$cacheTtl) {
            return;
        }

        $cacheKey = "lodestone:character:{$data->lodestoneId}";

        Cache::put($cacheKey, $data->toArray(), $cacheTtl);
    }

    /**
     * Apply rate limiting delay if configured.
     */
    private function applyRateLimit(): void
    {
        $delay = config('lodestone.rate_limit_delay');

        if ($delay && $delay > 0) {
            usleep($delay * 1000); // Convert milliseconds to microseconds
        }
    }

    /**
     * Clear cached data for a specific Lodestone ID.
     */
    public function clearCache(string $lodestoneId): void
    {
        $cacheKey = "lodestone:character:{$lodestoneId}";
        Cache::forget($cacheKey);
    }

    /**
     * Validate that a Lodestone ID exists (lightweight check).
     *
     * This just attempts to fetch the profile page without full parsing.
     * Returns true if character exists, false if 404.
     *
     * @throws LodestoneFetchException For non-404 errors
     * @throws LodestoneInvalidInputException
     */
    public function exists(string $lodestoneIdOrUrl): bool
    {
        try {
            $urls = $this->normalizer->normalize($lodestoneIdOrUrl);
            $this->httpClient->fetch($urls->profileUrl);
            return true;
        } catch (LodestoneFetchException $e) {
            if ($e->getCode() === 404) {
                return false;
            }
            throw $e;
        }
    }
}
