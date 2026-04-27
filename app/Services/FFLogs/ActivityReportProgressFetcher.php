<?php

namespace App\Services\FFLogs;

use App\Models\Activity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class ActivityReportProgressFetcher
{
    private const TOKEN_CACHE_KEY = 'fflogs:client_credentials_token';

    private const TOKEN_CACHE_TTL_BUFFER = 60;

    /**
     * @return array<string, mixed>
     */
    public function preview(Activity $activity, string $reportInput): array
    {
        $reportCode = $this->extractReportCode($reportInput);

        if ($reportCode === null) {
            throw new RuntimeException('The provided FF Logs report link or code is invalid.');
        }

        $activity->loadMissing('activityTypeVersion');

        $report = $this->queryReportFights($reportCode);
        $progPointKeys = collect($activity->activityTypeVersion?->prog_points ?? [])
            ->pluck('key')
            ->filter()
            ->map(fn ($key) => (string) $key)
            ->all();

        $milestones = collect($activity->activityTypeVersion?->progress_schema['milestones'] ?? [])
            ->map(function (array $milestone, int $index) use ($report) {
                $matcher = is_array($milestone['fflogs_matcher'] ?? null) ? $milestone['fflogs_matcher'] : [];
                $encounterId = isset($matcher['encounter_id']) ? (int) $matcher['encounter_id'] : 0;
                $matcherType = (string) ($matcher['type'] ?? 'encounter');
                $phaseId = isset($matcher['phase_id']) ? (int) $matcher['phase_id'] : null;

                $matchingFights = collect($report['fights'])
                    ->filter(fn (array $fight) => (int) ($fight['encounterID'] ?? 0) === $encounterId)
                    ->values();

                $killCount = $matchingFights
                    ->filter(fn (array $fight) => (bool) ($fight['kill'] ?? false))
                    ->count();

                $bestEncounterProgress = $matchingFights
                    ->map(fn (array $fight) => $this->resolveProgressPercent($fight))
                    ->filter(fn (?float $progress) => $progress !== null)
                    ->max();

                $phaseReachedCount = $matchingFights
                    ->filter(function (array $fight) use ($phaseId) {
                        if ((bool) ($fight['kill'] ?? false)) {
                            return true;
                        }

                        if ($phaseId === null) {
                            return false;
                        }

                        return (int) ($fight['lastPhase'] ?? 0) >= $phaseId;
                    })
                    ->count();

                $bestProgressPercent = $matcherType === 'phase'
                    ? ($phaseReachedCount > 0 ? 100.0 : ($bestEncounterProgress ?? 0.0))
                    : ($bestEncounterProgress ?? 0.0);

                return [
                    'id' => $index + 1,
                    'milestone_key' => (string) ($milestone['key'] ?? ''),
                    'milestone_label' => is_array($milestone['label'] ?? null)
                        ? $milestone['label']
                        : ['en' => (string) ($milestone['key'] ?? '')],
                    'matcher_type' => $matcherType,
                    'encounter_id' => $encounterId > 0 ? $encounterId : null,
                    'phase_id' => $matcherType === 'phase' ? $phaseId : null,
                    'kills' => $matcherType === 'phase' ? $phaseReachedCount : $killCount,
                    'best_progress_percent' => round((float) $bestProgressPercent, 2),
                ];
            })
            ->filter(fn (array $milestone) => $milestone['milestone_key'] !== '')
            ->values();

        $suggestedFurthestProgressKey = $milestones
            ->filter(fn (array $milestone) => $milestone['best_progress_percent'] > 0 || $milestone['kills'] > 0)
            ->map(fn (array $milestone) => $milestone['milestone_key'])
            ->reverse()
            ->first(fn (string $key) => in_array($key, $progPointKeys, true));

        return [
            'report_code' => $reportCode,
            'report_title' => $report['title'],
            'progress_link_url' => $reportInput,
            'suggested_furthest_progress_key' => $suggestedFurthestProgressKey,
            'milestones' => $milestones->all(),
        ];
    }

    /**
     * @return array{title: ?string, fights: array<int, array<string, mixed>>}
     */
    private function queryReportFights(string $reportCode): array
    {
        $query = <<<'GRAPHQL'
query ActivityReportProgress($code: String!) {
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

    private function extractReportCode(string $input): ?string
    {
        $trimmed = trim($input);

        if ($trimmed === '') {
            return null;
        }

        if (preg_match('~reports/([A-Za-z0-9]+)~', $trimmed, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('~fight=([A-Za-z0-9]+)~', $trimmed, $matches) === 1) {
            return $matches[1];
        }

        return preg_match('/^[A-Za-z0-9]+$/', $trimmed) === 1 ? $trimmed : null;
    }

    /**
     * @param  array<string, mixed>  $fight
     */
    private function resolveProgressPercent(array $fight): ?float
    {
        if ((bool) ($fight['kill'] ?? false)) {
            return 100.0;
        }

        foreach (['bossPercentage', 'fightPercentage'] as $key) {
            $value = $fight[$key] ?? null;

            if (is_numeric($value)) {
                return round(max(0, min(100, 100 - (float) $value)), 2);
            }
        }

        return null;
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
