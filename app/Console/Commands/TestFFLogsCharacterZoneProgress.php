<?php

namespace App\Console\Commands;

use App\Services\FFLogs\ForkedTowerBloodProgressFetcher;
use Illuminate\Console\Command;
use RuntimeException;

class TestFFLogsCharacterZoneProgress extends Command
{
    protected $signature = 'fflogs:character-zone
                            {name : Character name}
                            {server : FF Logs server slug/world, e.g. Twintania}
                            {--region= : FF Logs server region, e.g. NA, EU, JP, OC}
                            {--datacenter= : Datacenter to resolve a region from, e.g. Light}
                            {--json : Output normalized progress as JSON}
                            {--debug : Show raw FF Logs zone rankings before normalized progress}';

    protected $description = 'Test direct FF Logs Forked Tower of Blood lookup by character name and server';

    public function handle(ForkedTowerBloodProgressFetcher $fetcher): int
    {
        try {
            $name = trim((string) $this->argument('name'));
            $server = trim((string) $this->argument('server'));
            $region = $this->resolveRegion(
                $this->option('region') ? (string) $this->option('region') : null,
                $this->option('datacenter') ? (string) $this->option('datacenter') : null,
            );
            $jsonOutput = (bool) $this->option('json');
            $debugOutput = (bool) $this->option('debug');

            if ($name === '' || $server === '') {
                throw new RuntimeException('Character name and server are required.');
            }

            $this->info("Fetching Forked Tower of Blood FF Logs data for {$name} ({$server}, {$region})");
            $this->newLine();

            $startTime = microtime(true);
            $debugPayload = $debugOutput
                ? $fetcher->fetchDebugPayloadForResolvedIdentity($name, $server, $region)
                : null;
            $progress = $debugPayload['progress'] ?? $fetcher->fetchForResolvedIdentity($name, $server, $region);
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($debugOutput) {
                $this->displayRawZoneRankings($debugPayload['zone_rankings'] ?? []);
                $this->newLine();
            }

            if ($jsonOutput) {
                $this->line(json_encode([
                    'identity' => [
                        'name' => $name,
                        'server' => $server,
                        'region' => $region,
                    ],
                    'progress' => $progress,
                ], JSON_PRETTY_PRINT));
            } else {
                $this->displayProgress($name, $server, $region, $progress, $duration);
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

    private function resolveRegion(?string $region, ?string $datacenter): string
    {
        if ($region !== null && trim($region) !== '') {
            return $this->normalizeRegion($region);
        }

        if ($datacenter === null || trim($datacenter) === '') {
            throw new RuntimeException('Pass either --region=EU or --datacenter=Light.');
        }

        $regions = collect(config('datacenters.regions', []))
            ->mapWithKeys(fn (string $value, string $key): array => [strtolower($key) => $value]);
        $resolvedRegion = $regions->get(strtolower(trim($datacenter)));

        if (! $resolvedRegion) {
            throw new RuntimeException("Unable to resolve FF Logs region for datacenter [{$datacenter}].");
        }

        return $this->normalizeRegion($resolvedRegion);
    }

    private function normalizeRegion(string $region): string
    {
        $normalized = strtoupper(trim($region));

        return $normalized === 'OCE' ? 'OC' : $normalized;
    }

    private function displayRawZoneRankings(array $zoneRankings): void
    {
        $this->line('<fg=yellow>═══ Debug: Raw Zone Rankings ═══</>');

        if (empty($zoneRankings)) {
            $this->warn('No zone rankings returned from FF Logs.');

            return;
        }

        $this->line(json_encode($zoneRankings, JSON_PRETTY_PRINT));
    }

    private function displayProgress(
        string $name,
        string $server,
        string $region,
        array $progress,
        float $duration,
    ): void {
        $this->info('✓ FF Logs fetch successful!');
        $this->info("⏱  Completed in {$duration}ms");
        $this->newLine();

        $this->table(
            ['Field', 'Value'],
            [
                ['Character', $name],
                ['Server', $server],
                ['Region', $region],
                ['Clear Count', $progress['clears'] ?? 0],
            ]
        );

        $this->newLine();
        $this->line('<fg=cyan>═══ Boss Progression ═══</>');

        $rows = collect($progress['bosses'] ?? [])
            ->map(fn (array $boss): array => [
                $boss['name'] ?? $boss['key'] ?? 'Unknown',
                $boss['kills'] ?? 0,
                ($boss['progress'] ?? 0).'%',
            ])
            ->all();

        $this->table(['Boss', 'Kills', 'Progress'], $rows);
    }
}
