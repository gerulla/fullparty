<?php

namespace App\Console\Commands;

use App\Models\Character;
use App\Services\FFLogs\ForkedTowerBloodProgressFetcher;
use Illuminate\Console\Command;

class TestForkedTowerBloodProgress extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fflogs:forked-tower-blood
                            {character : Character ID by default, or Lodestone ID with --lodestone-id}
                            {--lodestone-id : Treat the character argument as a Lodestone ID}
                            {--json : Output the normalized payload as JSON}
                            {--debug : Show raw zone ranking payload before the normalized payload}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the FF Logs Forked Tower of Blood progress fetcher for a character';

    public function handle(ForkedTowerBloodProgressFetcher $fetcher): int
    {
        $characterInput = (string) $this->argument('character');
        $lookupByLodestoneId = (bool) $this->option('lodestone-id');
        $jsonOutput = (bool) $this->option('json');
        $debugOutput = (bool) $this->option('debug');

        $character = $this->resolveCharacter($characterInput, $lookupByLodestoneId);

        if (!$character) {
            $this->error($lookupByLodestoneId
                ? "No character found with Lodestone ID [{$characterInput}]."
                : "No character found with ID [{$characterInput}].");

            return self::FAILURE;
        }

        $this->info("Fetching Forked Tower of Blood FF Logs data for {$character->name} ({$character->world})");
        $this->newLine();

        try {
            $startTime = microtime(true);
            $debugPayload = $debugOutput
                ? $fetcher->fetchDebugPayloadForCharacter($character)
                : null;
            $progress = $debugPayload['progress'] ?? $fetcher->fetchForCharacter($character);
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($debugOutput) {
                $this->displayDebugZoneRankings($debugPayload['zone_rankings'] ?? []);
                $this->newLine();
            }

            if ($jsonOutput) {
                $this->line(json_encode($progress, JSON_PRETTY_PRINT));
            } else {
                $this->displayPayload($character, $progress, $duration);
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error("FF Logs fetch failed: {$exception->getMessage()}");

            if ($this->getOutput()->isVerbose()) {
                $this->error($exception->getTraceAsString());
            }

            return self::FAILURE;
        }
    }

    private function resolveCharacter(string $characterInput, bool $lookupByLodestoneId): ?Character
    {
        $query = Character::query();

        return $lookupByLodestoneId
            ? $query->where('lodestone_id', $characterInput)->first()
            : $query->find($characterInput);
    }

    private function displayPayload(Character $character, array $progress, float $duration): void
    {
        $this->info('✓ FF Logs fetch successful!');
        $this->info("⏱  Completed in {$duration}ms");
        $this->newLine();

        $this->line('<fg=cyan>═══ Character ═══</>');
        $this->table(
            ['Field', 'Value'],
            [
                ['Character', $character->name],
                ['World', $character->world],
                ['Data Center', $character->datacenter],
                ['Lodestone ID', $character->lodestone_id],
                ['Clear Count', $progress['clears'] ?? 0],
            ]
        );

        $this->newLine();
        $this->line('<fg=cyan>═══ Boss Progression ═══</>');

        $rows = collect($progress['bosses'] ?? [])
            ->map(fn (array $boss) => [
                $boss['name'] ?? $boss['key'] ?? 'Unknown',
                $boss['kills'] ?? 0,
                ($boss['progress'] ?? 0) . '%',
            ])
            ->all();

        $this->table(['Boss', 'Kills', 'Progress'], $rows);
    }

    private function displayDebugZoneRankings(array $zoneRankings): void
    {
        $this->line('<fg=yellow>═══ Debug: Raw Zone Rankings ═══</>');

        if (empty($zoneRankings)) {
            $this->warn('No zone rankings returned from FF Logs.');
            return;
        }

        $this->line(json_encode($zoneRankings, JSON_PRETTY_PRINT));
    }
}
