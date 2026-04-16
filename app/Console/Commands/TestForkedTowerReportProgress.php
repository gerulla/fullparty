<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class TestForkedTowerReportProgress extends Command
{
    private const TOKEN_CACHE_KEY = 'fflogs:client_credentials_token';

    private const TOKEN_CACHE_TTL_BUFFER = 60;

    private const BOSS_ORDER = [
        'Demon Tablet' => 'demon_tablet',
        'Dead Stars' => 'dead_stars',
        'Marble Dragon' => 'marble_dragon',
        'Magitaur' => 'magitaur',
    ];

    private const ENCOUNTER_ID_TO_BOSS = [
        2062 => 'Demon Tablet',
        2063 => 'Dead Stars',
        2065 => 'Marble Dragon',
        2066 => 'Magitaur',
    ];

    private const BOSS_ALIASES = [
        'demon_tablet' => ['demon tablet'],
        'dead_stars' => ['dead stars'],
        'marble_dragon' => ['marble dragon'],
        'magitaur' => ['magitaur'],
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fflogs:forked-tower-report
                            {report : FF Logs report code}
                            {--json : Output the normalized payload as JSON}
                            {--debug : Show raw fight payload before the normalized payload}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Extract Forked Tower progression from an FF Logs report';

    public function handle(): int
    {
        $reportCode = trim((string) $this->argument('report'));
        $jsonOutput = (bool) $this->option('json');
        $debugOutput = (bool) $this->option('debug');

        if ($reportCode === '') {
            $this->error('A report code is required.');

            return self::FAILURE;
        }

        $this->info("Fetching Forked Tower report progression for report [{$reportCode}]");
        $this->newLine();

        try {
            $startTime = microtime(true);
            $fightPayload = $this->queryReportFights($reportCode);
            $progress = $this->buildProgressPayload($fightPayload['fights']);
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($debugOutput) {
                $this->line('<fg=yellow>═══ Debug: Raw Report Payload ═══</>');
                $this->line(json_encode($fightPayload, JSON_PRETTY_PRINT));
                $this->newLine();
            }

            if ($jsonOutput) {
                $this->line(json_encode($progress, JSON_PRETTY_PRINT));
            } else {
                $this->displayPayload($reportCode, $progress, $duration);
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

    /**
     * @return array{title: ?string, fights: array<int, array<string, mixed>>}
     */
    private function queryReportFights(string $reportCode): array
    {
        $query = <<<'GRAPHQL'
query ForkedTowerReportProgress($code: String!) {
  reportData {
    report(code: $code) {
      title
      fights(translate: true) {
        id
        encounterID
        name
        kill
        lastPhase
        bossPercentage
        fightPercentage
        startTime
        endTime
      }
    }
  }
}
GRAPHQL;

        $response = Http::withToken($this->getAccessToken())
            ->acceptJson()
            ->post(config('services.ff_logs.graphql_url'), [
                'query' => $query,
                'variables' => [
                    'code' => $reportCode,
                ],
            ])
            ->throw()
            ->json();

        if (!empty($response['errors'])) {
            throw new RuntimeException('FF Logs GraphQL query failed: ' . json_encode($response['errors']));
        }

        $report = data_get($response, 'data.reportData.report');

        if (!is_array($report)) {
            throw new RuntimeException("FF Logs report [{$reportCode}] could not be resolved.");
        }

        $fights = data_get($report, 'fights', []);

        return [
            'title' => data_get($report, 'title'),
            'fights' => is_array($fights) ? array_values(array_filter($fights, 'is_array')) : [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $fights
     * @return array<string, mixed>
     */
    private function buildProgressPayload(array $fights): array
    {
        $bosses = collect(self::BOSS_ORDER)
            ->map(fn (string $key, string $name) => [
                'key' => $key,
                'name' => $name,
                'pulls' => 0,
                'kills' => 0,
                'best_remaining_percent' => null,
                'best_progress_percent' => 0,
                'best_pull_id' => null,
                'last_phase' => null,
            ])
            ->keyBy('name')
            ->all();

        foreach ($fights as $fight) {
            $bossName = $this->resolveBossName(
                $fight['encounterID'] ?? null,
                $fight['name'] ?? null,
            );

            if (!$bossName || !isset($bosses[$bossName])) {
                continue;
            }

            $boss = $bosses[$bossName];
            $kill = (bool) ($fight['kill'] ?? false);
            $remainingPercent = $this->resolveRemainingPercent($fight);
            $progressPercent = $kill
                ? 100.0
                : ($remainingPercent !== null ? round(100 - $remainingPercent, 2) : 0.0);

            $boss['pulls']++;
            $boss['kills'] += $kill ? 1 : 0;

            if (
                $boss['best_progress_percent'] < $progressPercent
                || $boss['best_pull_id'] === null
            ) {
                $boss['best_progress_percent'] = $progressPercent;
                $boss['best_remaining_percent'] = $kill ? 0.0 : $remainingPercent;
                $boss['best_pull_id'] = $fight['id'] ?? null;
                $boss['last_phase'] = $fight['lastPhase'] ?? null;
            }

            $bosses[$bossName] = $boss;
        }

        $orderedBosses = array_values($bosses);
        $furthestBoss = collect($orderedBosses)
            ->filter(fn (array $boss) => $boss['best_progress_percent'] > 0 || $boss['kills'] > 0)
            ->sortBy(fn (array $boss) => array_search($boss['key'], array_values(self::BOSS_ORDER), true))
            ->last();

        return [
            'clears' => (int) (collect($orderedBosses)->firstWhere('key', 'magitaur')['kills'] ?? 0),
            'furthest_boss_key' => $furthestBoss['key'] ?? null,
            'furthest_boss_name' => $furthestBoss['name'] ?? null,
            'furthest_remaining_percent' => $furthestBoss['best_remaining_percent'] ?? null,
            'furthest_progress_percent' => $furthestBoss['best_progress_percent'] ?? 0,
            'bosses' => $orderedBosses,
        ];
    }

    /**
     * @param  array<string, mixed>  $fight
     */
    private function resolveRemainingPercent(array $fight): ?float
    {
        foreach (['bossPercentage', 'fightPercentage'] as $key) {
            $value = $fight[$key] ?? null;

            if (is_numeric($value)) {
                return round(max(0, min(100, (float) $value)), 2);
            }
        }

        return null;
    }

    private function resolveBossName(mixed $encounterId, ?string $fightName): ?string
    {
        if (is_numeric($encounterId)) {
            $bossName = self::ENCOUNTER_ID_TO_BOSS[(int) $encounterId] ?? null;

            return $bossName;
        }

        if (!is_string($fightName) || trim($fightName) === '') {
            return null;
        }

        $normalizedFightName = $this->normalizeName($fightName);

        foreach (self::BOSS_ORDER as $bossName => $key) {
            $aliases = array_merge([$bossName], self::BOSS_ALIASES[$key]);

            foreach ($aliases as $alias) {
                if ($normalizedFightName === $this->normalizeName($alias)) {
                    return $bossName;
                }
            }
        }

        return null;
    }

    private function displayPayload(string $reportCode, array $progress, float $duration): void
    {
        $this->info('✓ FF Logs report fetch successful!');
        $this->info("⏱  Completed in {$duration}ms");
        $this->newLine();

        $this->table(
            ['Field', 'Value'],
            [
                ['Report', $reportCode],
                ['Clear Count', $progress['clears'] ?? 0],
                ['Furthest Boss', $progress['furthest_boss_name'] ?? 'None'],
                ['Best Remaining %', ($progress['furthest_remaining_percent'] ?? 'N/A') . (is_numeric($progress['furthest_remaining_percent'] ?? null) ? '%' : '')],
                ['Best Progress %', ($progress['furthest_progress_percent'] ?? 0) . '%'],
            ]
        );

        $this->newLine();
        $this->line('<fg=cyan>═══ Boss Progression ═══</>');

        $rows = collect($progress['bosses'] ?? [])
            ->map(fn (array $boss) => [
                $boss['name'] ?? $boss['key'] ?? 'Unknown',
                $boss['pulls'] ?? 0,
                $boss['kills'] ?? 0,
                is_numeric($boss['best_remaining_percent'] ?? null) ? $boss['best_remaining_percent'] . '%' : 'N/A',
                ($boss['best_progress_percent'] ?? 0) . '%',
                $boss['last_phase'] ?? '-',
                $boss['best_pull_id'] ?? '-',
            ])
            ->all();

        $this->table(
            ['Boss', 'Pulls', 'Kills', 'Best Remaining %', 'Best Progress %', 'Last Phase', 'Best Pull ID'],
            $rows
        );
    }

    private function getAccessToken(): string
    {
        $cachedToken = Cache::get(self::TOKEN_CACHE_KEY);

        if ($cachedToken) {
            return $cachedToken;
        }

        $clientId = config('services.ff_logs.client_id');
        $clientSecret = config('services.ff_logs.client_secret');

        if (!$clientId || !$clientSecret) {
            throw new RuntimeException('FF Logs credentials are not configured.');
        }

        $response = Http::asForm()
            ->withBasicAuth($clientId, $clientSecret)
            ->post(config('services.ff_logs.token_url'), [
                'grant_type' => 'client_credentials',
            ])
            ->throw()
            ->json();

        $token = $response['access_token'] ?? null;

        if (!$token) {
            throw new RuntimeException('FF Logs access token was not returned.');
        }

        $expiresIn = max(0, ((int) ($response['expires_in'] ?? 3600)) - self::TOKEN_CACHE_TTL_BUFFER);

        Cache::put(self::TOKEN_CACHE_KEY, $token, now()->addSeconds($expiresIn));

        return $token;
    }

    private function normalizeName(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->value();
    }
}
