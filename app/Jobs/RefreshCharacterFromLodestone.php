<?php

namespace App\Jobs;

use App\Models\Character;
use App\Services\Lodestone\LodestoneScraper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Example queued job for refreshing character data from Lodestone.
 *
 * IMPORTANT: This is an EXAMPLE showing how to use the scraper in a job.
 * The business logic here (what to update, when to update) is YOUR decision.
 *
 * The scraper only returns DTOs. This job shows one possible way to use that data.
 *
 * Usage:
 *   RefreshCharacterFromLodestone::dispatch($character);
 */
class RefreshCharacterFromLodestone implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Character $character
    ) {}

    /**
     * Execute the job.
     */
    public function handle(LodestoneScraper $scraper): void
    {
        try {
            // 1. Scrape data from Lodestone
            $data = $scraper->scrape($this->character->lodestone_id);

            // 2. YOUR BUSINESS LOGIC: Decide what to update
            // This is just an example - customize based on your requirements

            $this->character->update([
                'name' => $data->name,
                'world' => $data->world,
                'datacenter' => $data->dataCenter,
                'avatar_url' => $data->avatarUrl,
            ]);

            // 3. OPTIONAL: Update dynamic field values
            // Example: Update job levels
            foreach ($data->extraData as $key => $value) {
                // Check if this is a job level field
                if (str_starts_with($key, 'job.') && str_ends_with($key, '.level')) {
                    // Extract job abbreviation (e.g., "job.pld.level" -> "pld_level")
                    $fieldSlug = str_replace('job.', '', $key);
                    $fieldSlug = str_replace('.level', '_level', $fieldSlug);

                    // Try to find matching field definition
                    // This assumes you have field definitions set up for job levels
                    try {
                        $this->character->setFieldValue($fieldSlug, $value);
                    } catch (\Exception $e) {
                        // Field definition doesn't exist yet - skip
                        continue;
                    }
                }
            }

            // 4. Log success
            Log::info("Character {$this->character->id} refreshed from Lodestone", [
                'lodestone_id' => $data->lodestoneId,
                'name' => $data->name,
            ]);

        } catch (\App\Exceptions\LodestoneInvalidInputException $e) {
            // Invalid input - don't retry
            Log::error("Invalid Lodestone ID for character {$this->character->id}: {$e->getMessage()}");
            $this->fail($e);

        } catch (\App\Exceptions\LodestoneFetchException $e) {
            // Fetch error - might be temporary, allow retry
            Log::warning("Failed to fetch Lodestone data for character {$this->character->id}: {$e->getMessage()}");
            throw $e; // Re-throw to trigger retry

        } catch (\App\Exceptions\LodestoneParseException $e) {
            // Parse error - likely structure change, don't retry
            Log::error("Failed to parse Lodestone data for character {$this->character->id}: {$e->getMessage()}");
            $this->fail($e);

        } catch (\Exception $e) {
            // Unexpected error
            Log::error("Unexpected error refreshing character {$this->character->id}: {$e->getMessage()}");
            throw $e; // Re-throw to trigger retry
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Job failed for character {$this->character->id}", [
            'exception' => $exception->getMessage(),
            'lodestone_id' => $this->character->lodestone_id,
        ]);

        // TODO: Optional - notify admin, mark character as needing manual review, etc.
    }
}
