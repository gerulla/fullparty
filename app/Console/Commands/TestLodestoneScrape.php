<?php

namespace App\Console\Commands;

use App\Services\Lodestone\LodestoneScraper;
use Illuminate\Console\Command;

/**
 * Manual testing command for Lodestone scraper.
 *
 * Usage:
 *   php artisan lodestone:test 47431834
 *   php artisan lodestone:test https://na.finalfantasyxiv.com/lodestone/character/47431834/
 *   php artisan lodestone:test 47431834 --no-jobs
 *   php artisan lodestone:test 47431834 --json
 */
class TestLodestoneScrape extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lodestone:test
                            {input : Lodestone ID or URL}
                            {--no-jobs : Skip class/job data scraping}
                            {--json : Output as JSON instead of formatted text}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test Lodestone scraper by fetching character data (does not write to database)';

    /**
     * Execute the console command.
     */
    public function handle(LodestoneScraper $scraper): int
    {
        $input = $this->argument('input');
        $includeJobs = !$this->option('no-jobs');
        $jsonOutput = $this->option('json');

        $this->info("Scraping Lodestone data for: {$input}");
        $this->newLine();

        try {
            $startTime = microtime(true);

            // Scrape data
            $data = $scraper->scrape($input, $includeJobs);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($jsonOutput) {
                $this->line(json_encode($data->toArray(), JSON_PRETTY_PRINT));
            } else {
                $this->displayData($data, $duration);
            }

            return self::SUCCESS;

        } catch (\App\Exceptions\LodestoneInvalidInputException $e) {
            $this->error("Invalid input: {$e->getMessage()}");
            return self::FAILURE;

        } catch (\App\Exceptions\LodestoneFetchException $e) {
            $this->error("Fetch error: {$e->getMessage()}");
            return self::FAILURE;

        } catch (\App\Exceptions\LodestoneParseException $e) {
            $this->error("Parse error: {$e->getMessage()}");
            $this->newLine();
            $this->warn('This likely means Lodestone HTML structure has changed.');
            $this->warn('Check and update parser selectors in:');
            $this->warn('  - app/Services/Lodestone/Parsers/LodestoneProfileParser.php');
            $this->warn('  - app/Services/Lodestone/Parsers/LodestoneClassJobParser.php');
            return self::FAILURE;

        } catch (\Exception $e) {
            $this->error("Unexpected error: {$e->getMessage()}");
            if ($this->getOutput()->isVerbose()) {
                $this->error($e->getTraceAsString());
            }
            return self::FAILURE;
        }
    }

    /**
     * Display scraped data in a formatted way.
     */
    private function displayData(\App\DTOs\LodestoneCharacterData $data, float $duration): void
    {
        $this->info('✓ Scraping successful!');
        $this->info("⏱  Completed in {$duration}ms");
        $this->newLine();

        // Core data
        $this->line('<fg=cyan>═══ Character Data ═══</>');
        $this->table(
            ['Field', 'Value'],
            [
                ['Lodestone ID', $data->lodestoneId],
                ['Name', $data->name],
                ['World', $data->world],
                ['Data Center', $data->dataCenter],
                ['Avatar URL', $this->truncate($data->avatarUrl, 60)],
                ['Portrait URL', $data->portraitUrl ? $this->truncate($data->portraitUrl, 60) : 'N/A'],
            ]
        );

        $this->newLine();

        // URLs
        $this->line('<fg=cyan>═══ URLs ═══</>');
        $this->table(
            ['Type', 'URL'],
            [
                ['Profile', $data->profileUrl],
                ['Class/Job', $data->classJobUrl],
            ]
        );

        // Display parsed data by category
        if (!empty($data->extraData)) {
            $this->displayMainJobs($data->extraData);
            $this->displayEurekaData($data->extraData);
            $this->displayBozjaData($data->extraData);
            $this->displayOccultData($data->extraData);
        }

        $this->newLine();
        $this->line('<fg=green>NOTE: This data was NOT saved to the database.</>');
        $this->line('<fg=green>Use this DTO in your controllers/jobs/services to persist data.</>');
    }

    /**
     * Display main jobs (combat + DoH/DoL).
     */
    private function displayMainJobs(array $extraData): void
    {
        $jobs = [];
        foreach ($extraData as $key => $value) {
            if (str_starts_with($key, 'job.') && str_ends_with($key, '.level')) {
                $jobAbbr = str_replace(['job.', '.level'], '', $key);
                $jobs[$jobAbbr] = $value;
            }
        }

        if (empty($jobs)) {
            return;
        }

        $this->newLine();
        $this->line('<fg=cyan>═══ Jobs (Combat + DoH/DoL) ═══</>');

        // Group jobs by category
        $tanks = ['pld', 'war', 'drk', 'gnb'];
        $healers = ['whm', 'sch', 'ast', 'sge'];
        $melee = ['mnk', 'drg', 'nin', 'sam', 'rpr', 'vpr', 'pgl', 'lnc', 'rog'];
        $ranged = ['brd', 'mch', 'dnc', 'arc'];
        $casters = ['blm', 'smn', 'rdm', 'pct', 'blu', 'thm', 'acn'];
        $crafters = ['crp', 'bsm', 'arm', 'gsm', 'ltw', 'wvr', 'alc', 'cul'];
        $gatherers = ['min', 'btn', 'fsh'];

        $rows = [];

        foreach ($jobs as $abbr => $level) {
            $category = 'Other';
            if (in_array($abbr, $tanks)) $category = 'Tank';
            elseif (in_array($abbr, $healers)) $category = 'Healer';
            elseif (in_array($abbr, $melee)) $category = 'Melee DPS';
            elseif (in_array($abbr, $ranged)) $category = 'Ranged DPS';
            elseif (in_array($abbr, $casters)) $category = 'Caster DPS';
            elseif (in_array($abbr, $crafters)) $category = 'Crafter';
            elseif (in_array($abbr, $gatherers)) $category = 'Gatherer';

            $rows[] = [strtoupper($abbr), $level, $category];
        }

        // Sort by category then level descending
        usort($rows, function ($a, $b) {
            $catOrder = ['Tank', 'Healer', 'Melee DPS', 'Ranged DPS', 'Caster DPS', 'Crafter', 'Gatherer', 'Other'];
            $catA = array_search($a[2], $catOrder);
            $catB = array_search($b[2], $catOrder);

            if ($catA === $catB) {
                return $b[1] <=> $a[1]; // Level descending
            }
            return $catA <=> $catB;
        });

        $this->table(['Job', 'Level', 'Category'], $rows);
    }

    /**
     * Display Eureka progression data.
     */
    private function displayEurekaData(array $extraData): void
    {
        $eurekaLevel = $extraData['progression.eureka.elemental_level'] ?? null;
        $eurekaExp = $extraData['progression.eureka.exp_text'] ?? null;

        if ($eurekaLevel === null) {
            return;
        }

        $this->newLine();
        $this->line('<fg=cyan>═══ Eureka Progression ═══</>');

        $rows = [
            ['Elemental Level', $eurekaLevel],
        ];

        if ($eurekaExp) {
            $rows[] = ['EXP', $eurekaExp];
        }

        $this->table(['Stat', 'Value'], $rows);
    }

    /**
     * Display Bozja progression data.
     */
    private function displayBozjaData(array $extraData): void
    {
        $bozjaRank = $extraData['progression.bozja.resistance_rank'] ?? null;
        $mettleText = $extraData['progression.bozja.mettle_text'] ?? null;
        $currentMettle = $extraData['progression.bozja.current_mettle'] ?? null;
        $nextRankMettle = $extraData['progression.bozja.next_rank_mettle'] ?? null;

        if ($bozjaRank === null) {
            return;
        }

        $this->newLine();
        $this->line('<fg=cyan>═══ Bozja Progression ═══</>');

        $rows = [
            ['Resistance Rank', $bozjaRank],
        ];

        if ($mettleText) {
            $rows[] = ['Mettle', $mettleText];
        }

        if ($currentMettle !== null && $nextRankMettle !== null) {
            $percentage = round(($currentMettle / $nextRankMettle) * 100, 1);
            $rows[] = ['Progress', "{$percentage}%"];
        }

        $this->table(['Stat', 'Value'], $rows);
    }

    /**
     * Display Occult Crescent and Phantom Jobs data.
     */
    private function displayOccultData(array $extraData): void
    {
        $knowledgeLevel = $extraData['progression.occult.knowledge_level'] ?? null;
        $expText = $extraData['progression.occult.exp_text'] ?? null;
        $sanguineCipher = $extraData['progression.occult.sanguine_cipher'] ?? null;

        // Collect phantom jobs
        $phantomJobs = [];
        foreach ($extraData as $key => $value) {
            if (str_starts_with($key, 'phantom.') && str_ends_with($key, '.level')) {
                $jobAbbr = str_replace(['phantom.', '.level'], '', $key);
                $phantomJobs[$jobAbbr] = [
                    'level' => $value,
                    'mastered' => $extraData["phantom.{$jobAbbr}.mastered"] ?? false,
                ];
            }
        }

        // Only display if there's occult data
        if ($knowledgeLevel === null && $sanguineCipher === null && empty($phantomJobs)) {
            return;
        }

        $this->newLine();
        $this->line('<fg=cyan>═══ Occult Crescent ═══</>');

        $rows = [];

        if ($knowledgeLevel !== null) {
            $rows[] = ['Knowledge Level', $knowledgeLevel];
        }

        if ($expText) {
            $rows[] = ['EXP', $expText];
        }

        if ($sanguineCipher !== null) {
            $rows[] = ['Sanguine Cipher', $sanguineCipher];
        }

        if (!empty($rows)) {
            $this->table(['Stat', 'Value'], $rows);
        }

        // Display phantom jobs
        if (!empty($phantomJobs)) {
            $this->newLine();
            $this->line('<fg=yellow>─── Phantom Jobs ───</>');

            $phantomRows = [];
            foreach ($phantomJobs as $abbr => $data) {
                $name = ucwords(str_replace('_', ' ', $abbr));
                $mastered = $data['mastered'] ? '✓ Mastered' : '';
                $phantomRows[] = [$name, $data['level'], $mastered];
            }

            // Sort by level descending
            usort($phantomRows, function ($a, $b) {
                return $b[1] <=> $a[1];
            });

            $this->table(['Phantom Job', 'Level', 'Status'], $phantomRows);
        }
    }

    /**
     * Truncate long strings for display.
     */
    private function truncate(string $text, int $length): string
    {
        if (strlen($text) <= $length) {
            return $text;
        }

        return substr($text, 0, $length - 3) . '...';
    }

    /**
     * Format value for display.
     */
    private function formatValue(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_null($value)) {
            return 'null';
        }

        return (string) $value;
    }
}
