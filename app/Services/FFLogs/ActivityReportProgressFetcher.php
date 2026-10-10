<?php

namespace App\Services\FFLogs;

use App\Models\Activity;
use RuntimeException;

class ActivityReportProgressFetcher
{
    public function __construct(private readonly FFLogsClient $client) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(Activity $activity, string $reportInput): array
    {
        $reportCode = $this->extractReportCode($reportInput);

        if ($reportCode === null) {
            throw new RuntimeException(__('errors.the_provided_ff_logs_report_link_or_code_is_invalid'));
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
                    ->filter(fn (array $fight) => $encounterId > 0 && (int) ($fight['encounterID'] ?? 0) === $encounterId)
                    ->values();

                $fightProgress = $matchingFights->map(fn (array $fight) => $matcherType === 'phase'
                    ? $this->resolvePhaseProgress($fight, $phaseId, $report['phases'][$encounterId] ?? [])
                    : [
                        'completed' => (bool) ($fight['kill'] ?? false),
                        'reached' => true,
                        'progress' => $this->resolveProgressPercent($fight),
                    ]);

                return [
                    'id' => $index + 1,
                    'milestone_key' => (string) ($milestone['key'] ?? ''),
                    'milestone_label' => is_array($milestone['label'] ?? null)
                        ? $milestone['label']
                        : ['en' => (string) ($milestone['key'] ?? '')],
                    'matcher_type' => $matcherType,
                    'encounter_id' => $encounterId > 0 ? $encounterId : null,
                    'phase_id' => $matcherType === 'phase' ? $phaseId : null,
                    'kills' => $fightProgress->where('completed', true)->count(),
                    'best_progress_percent' => round((float) ($fightProgress->max('progress') ?? 0), 2),
                    'reached' => $fightProgress->contains('reached', true),
                ];
            })
            ->filter(fn (array $milestone) => $milestone['milestone_key'] !== '')
            ->values();

        $suggestedFurthestProgressKey = $milestones
            ->filter(fn (array $milestone) => $milestone['reached'])
            ->map(fn (array $milestone) => $milestone['milestone_key'])
            ->reverse()
            ->first(fn (string $key) => in_array($key, $progPointKeys, true));

        return [
            'report_code' => $reportCode,
            'report_title' => $report['title'],
            'progress_link_url' => $reportInput,
            'suggested_furthest_progress_key' => $suggestedFurthestProgressKey,
            'milestones' => $milestones->map(function (array $milestone) {
                unset($milestone['reached']);

                return $milestone;
            })->all(),
        ];
    }

    /**
     * @return array{title: ?string, fights: array<int, array<string, mixed>>, phases: array<int, array>}
     */
    private function queryReportFights(string $reportCode): array
    {
        $query = <<<'GRAPHQL'
query ActivityReportProgress($code: String!) {
  reportData {
    report(code: $code) {
      title
      phases {
        encounterID
        phases { id isIntermission }
      }
      fights(translate: true) {
        id
        encounterID
        name
        kill
        lastPhase
        lastPhaseAsAbsoluteIndex
        lastPhaseIsIntermission
        bossPercentage
        fightPercentage
        startTime
        endTime
      }
    }
  }
}
GRAPHQL;

        $response = $this->client->query([
            'query' => $query,
            'variables' => [
                'code' => $reportCode,
            ],
        ])
            ->throw()
            ->json();

        if (! empty($response['errors'])) {
            throw new RuntimeException('FF Logs GraphQL query failed: '.json_encode($response['errors']));
        }

        $report = data_get($response, 'data.reportData.report');

        if (! is_array($report)) {
            throw new RuntimeException("FF Logs report [{$reportCode}] could not be resolved.");
        }

        $fights = data_get($report, 'fights', []);

        return [
            'title' => data_get($report, 'title'),
            'fights' => is_array($fights) ? array_values(array_filter($fights, 'is_array')) : [],
            'phases' => collect($report['phases'] ?? [])->pluck('phases', 'encounterID')->all(),
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
     * @param  array<int, array<string, mixed>>  $phases
     * @return array{completed: bool, reached: bool, progress: float|null}
     */
    private function resolvePhaseProgress(array $fight, ?int $phaseId, array $phases): array
    {
        $unreached = ['completed' => false, 'reached' => false, 'progress' => 0.0];

        if ($phaseId === null || $phaseId < 1) {
            return $unreached;
        }

        if ((bool) ($fight['kill'] ?? false)) {
            return ['completed' => true, 'reached' => true, 'progress' => 100.0];
        }

        $lastPhase = (int) ($fight['lastPhase'] ?? 0);
        $completedPhases = max(0, $lastPhase - 1);
        $isIntermission = (bool) ($fight['lastPhaseIsIntermission'] ?? false);

        if ($isIntermission) {
            // FF Logs numbers intermissions separately from normal phases.
            $absoluteIndex = $fight['lastPhaseAsAbsoluteIndex'] ?? null;
            $orderedPhases = collect($phases)->sortBy('id')->values();

            if (! is_int($absoluteIndex) || $absoluteIndex < 0
                || ! data_get($orderedPhases->get($absoluteIndex), 'isIntermission')) {
                return $unreached;
            }

            $completedPhases = $orderedPhases->take($absoluteIndex)
                ->reject(fn (array $phase) => (bool) ($phase['isIntermission'] ?? false))
                ->count();
        }

        if ($phaseId <= $completedPhases) {
            return ['completed' => true, 'reached' => true, 'progress' => 100.0];
        }

        if ($isIntermission || $lastPhase !== $phaseId) {
            return $unreached;
        }

        // Whole-fight percentages cannot describe damage within the active phase.
        return [
            'completed' => false,
            'reached' => true,
            'progress' => $this->resolveProgressPercent($fight, phaseOnly: true),
        ];
    }

    /**
     * @param  array<string, mixed>  $fight
     */
    private function resolveProgressPercent(array $fight, bool $phaseOnly = false): ?float
    {
        if ((bool) ($fight['kill'] ?? false)) {
            return 100.0;
        }

        foreach ($phaseOnly ? ['bossPercentage'] : ['fightPercentage', 'bossPercentage'] as $key) {
            $value = $fight[$key] ?? null;

            if (is_numeric($value)) {
                return round(max(0, min(100, 100 - (float) $value)), 2);
            }
        }

        return null;
    }
}
