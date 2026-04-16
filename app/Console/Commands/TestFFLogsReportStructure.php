<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TestFFLogsReportStructure extends Command
{
    private const TOKEN_CACHE_KEY = 'fflogs:client_credentials_token';

    private const TOKEN_CACHE_TTL_BUFFER = 60;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fflogs:report-structure
                            {report : FF Logs report code}
                            {--json : Output the normalized payload as JSON}
                            {--debug : Show raw fight payload before the normalized payload}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Extract encounter IDs and observed phase IDs from an FF Logs report';

    public function handle(): int
    {
        $reportCode = trim((string) $this->argument('report'));
        $jsonOutput = (bool) $this->option('json');
        $debugOutput = (bool) $this->option('debug');

        if ($reportCode === '') {
            $this->error('A report code is required.');

            return self::FAILURE;
        }

        $this->info("Fetching FF Logs report structure for report [{$reportCode}]");
        $this->newLine();

        try {
            $startTime = microtime(true);
            $fightPayload = $this->queryReportFights($reportCode);
            $structure = $this->buildStructurePayload($fightPayload);
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($debugOutput) {
                $this->line('<fg=yellow>═══ Debug: Raw Report Payload ═══</>');
                $this->line(json_encode($fightPayload, JSON_PRETTY_PRINT));
                $this->newLine();
            }

            if ($jsonOutput) {
                $this->line(json_encode($structure, JSON_PRETTY_PRINT));
            } else {
                $this->displayPayload($reportCode, $structure, $duration);
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
query ReportStructure($code: String!) {
  reportData {
    report(code: $code) {
      title
      fights(translate: true) {
        id
        encounterID
        originalEncounterID
        name
        kill
        lastPhase
        lastPhaseAsAbsoluteIndex
        lastPhaseIsIntermission
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
     * @param  array{title: ?string, fights: array<int, array<string, mixed>>}  $payload
     * @return array<string, mixed>
     */
    private function buildStructurePayload(array $payload): array
    {
        $encounters = [];

        foreach ($payload['fights'] as $fight) {
            $encounterId = (int) ($fight['encounterID'] ?? 0);

            if ($encounterId <= 0) {
                continue;
            }

            $encounterKey = (string) $encounterId;

            if (!isset($encounters[$encounterKey])) {
                $encounters[$encounterKey] = [
                    'encounter_id' => $encounterId,
                    'original_encounter_id' => is_numeric($fight['originalEncounterID'] ?? null)
                        ? (int) $fight['originalEncounterID']
                        : null,
                    'name' => (string) ($fight['name'] ?? 'Unknown'),
                    'pulls' => 0,
                    'kills' => 0,
                    'observed_phase_ids' => [],
                    'highest_phase_id' => null,
                    'sample_fights' => [],
                ];
            }

            $phaseId = $this->resolvePhaseId($fight);

            $encounters[$encounterKey]['pulls']++;
            $encounters[$encounterKey]['kills'] += (bool) ($fight['kill'] ?? false) ? 1 : 0;

            if ($phaseId !== null) {
                $encounters[$encounterKey]['observed_phase_ids'][$phaseId] = $phaseId;
                $encounters[$encounterKey]['highest_phase_id'] = max(
                    (int) ($encounters[$encounterKey]['highest_phase_id'] ?? 0),
                    $phaseId,
                );
            }

            $encounters[$encounterKey]['sample_fights'][] = [
                'fight_id' => (int) ($fight['id'] ?? 0),
                'kill' => (bool) ($fight['kill'] ?? false),
                'phase_id' => $phaseId,
                'is_intermission' => (bool) ($fight['lastPhaseIsIntermission'] ?? false),
            ];
        }

        $normalizedEncounters = array_values(array_map(function (array $encounter): array {
            $encounter['observed_phase_ids'] = array_values($encounter['observed_phase_ids']);
            sort($encounter['observed_phase_ids']);

            return $encounter;
        }, $encounters));

        usort($normalizedEncounters, fn (array $left, array $right) => $left['encounter_id'] <=> $right['encounter_id']);

        return [
            'title' => $payload['title'],
            'encounters' => $normalizedEncounters,
        ];
    }

    /**
     * @param  array<string, mixed>  $fight
     */
    private function resolvePhaseId(array $fight): ?int
    {
        $absoluteIndex = $fight['lastPhaseAsAbsoluteIndex'] ?? null;

        if (is_numeric($absoluteIndex) && (int) $absoluteIndex >= 0) {
            return ((int) $absoluteIndex) + 1;
        }

        $lastPhase = $fight['lastPhase'] ?? null;

        if (is_numeric($lastPhase) && (int) $lastPhase > 0) {
            return (int) $lastPhase;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $structure
     */
    private function displayPayload(string $reportCode, array $structure, float $duration): void
    {
        $this->info('✓ FF Logs report fetch successful!');
        $this->info("⏱  Completed in {$duration}ms");
        $this->newLine();

        $this->table(
            ['Field', 'Value'],
            [
                ['Report', $reportCode],
                ['Title', $structure['title'] ?? 'Unknown'],
                ['Encounters Found', count($structure['encounters'] ?? [])],
            ]
        );

        $this->newLine();
        $this->line('<fg=cyan>═══ Encounter Structure ═══</>');

        $rows = collect($structure['encounters'] ?? [])
            ->map(fn (array $encounter) => [
                $encounter['encounter_id'],
                $encounter['name'],
                $encounter['pulls'],
                $encounter['kills'],
                $encounter['highest_phase_id'] ?? '-',
                empty($encounter['observed_phase_ids']) ? '-' : implode(', ', $encounter['observed_phase_ids']),
            ])
            ->all();

        $this->table(
            ['Encounter ID', 'Name', 'Pulls', 'Kills', 'Highest Phase ID', 'Observed Phase IDs'],
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
}
