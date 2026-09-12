<?php

namespace App\Services\Groups\ApplicantQueue;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Services\FFLogs\CharacterZoneProgressFetcher;
use App\Services\Groups\CharacterActivityHistoryService;
use App\Support\FFLogsDifficulty;
use Illuminate\Support\Facades\Log;

class ApplicantRecordService
{
    public function __construct(private readonly CharacterZoneProgressFetcher $fetcher, private readonly CharacterActivityHistoryService $history) {}

    public function forApplication(Activity $activity, ActivityApplication $application): array
    {
        $activity->loadMissing('activityTypeVersion');
        $application->loadMissing('selectedCharacter');
        $definitions = collect($activity->activityTypeVersion?->progress_schema['milestones'] ?? [])
            ->filter(fn (array $milestone) => filled($milestone['key'] ?? null))
            ->sortBy('order')->values();
        $characterId = $application->selectedCharacter?->id;
        $history = $characterId ? $this->history->completedRuns($activity, $characterId) : collect();
        [$fflogsStatus, $encounters] = $this->fflogsProgress($activity, $application);
        $usedEncounters = [];

        $rows = $definitions->map(function (array $definition) use ($history, $characterId, $encounters, $fflogsStatus, &$usedEncounters) {
            $encounterId = (int) data_get($definition, 'fflogs_matcher.encounter_id', 0);
            $isEncounter = data_get($definition, 'fflogs_matcher.type', 'encounter') === 'encounter';
            $fflogs = null;
            if ($isEncounter && $encounterId > 0 && $fflogsStatus === 'available') {
                $encounter = $encounters->firstWhere('encounter_id', $encounterId);
                $fflogs = $this->result((int) ($encounter['kills'] ?? 0), $encounter['progress'] ?? 0);
                $usedEncounters[] = $encounterId;
            }

            $kills = 0;
            $progress = 0;
            foreach ($history as $run) {
                $historicalDefinitions = collect($run->activityTypeVersion?->progress_schema['milestones'] ?? [])->keyBy('key');
                $matches = $run->progressMilestones->filter(fn ($milestone) => $this->history->sameMilestone(
                    $definition,
                    $historicalDefinitions->get($milestone->milestone_key, ['key' => $milestone->milestone_key]),
                ));
                // One run can contain multiple schema entries for the same encounter.
                $kills += (int) ($matches->max('kills') ?? 0);
                $progress = max($progress, (float) ($matches->max('best_progress_percent') ?? 0));
            }

            return [
                'key' => (string) $definition['key'],
                'label' => $definition['label'] ?? ['en' => $definition['key']],
                'onsite' => $characterId ? $this->result($kills, $progress) : null,
                'fflogs' => $fflogs,
            ];
        });

        foreach ($encounters as $index => $encounter) {
            if (in_array((int) ($encounter['encounter_id'] ?? 0), $usedEncounters, true)) {
                continue;
            }
            $rows->push([
                'key' => 'fflogs-'.($encounter['encounter_id'] ?? $index),
                'label' => ['en' => $encounter['name']],
                'onsite' => null,
                'fflogs' => $this->result((int) $encounter['kills'], $encounter['progress']),
            ]);
        }

        return ['milestones' => $rows->all(), 'fflogs_status' => $fflogsStatus, 'onsite_available' => $characterId !== null];
    }

    private function result(int $kills, int|float|string|null $progress): array
    {
        return ['kills' => max(0, $kills), 'progress_percent' => $kills > 0 ? 100 : round(max(0, min(100, (float) $progress)), 2)];
    }

    private function fflogsProgress(Activity $activity, ActivityApplication $application): array
    {
        $zoneId = (int) ($activity->activityTypeVersion?->fflogs_zone_id ?? 0);
        if ($zoneId <= 0) {
            return ['not_configured', collect()];
        }
        $difficulty = FFLogsDifficulty::forActivityTypeVersion($activity->activityTypeVersion);
        try {
            if ($application->user_id !== null && $application->selectedCharacter) {
                $progress = $this->fetcher->fetchEncounterProgressForCharacter($application->selectedCharacter, $zoneId, $difficulty);
            } elseif (filled($application->applicant_character_name) && filled($application->applicant_world) && filled($application->applicant_datacenter)) {
                $progress = $this->fetcher->fetchEncounterProgressForIdentity(
                    $application->applicant_character_name, $application->applicant_world, $application->applicant_datacenter,
                    $application->applicant_lodestone_id, $zoneId, $difficulty,
                );
            } else {
                return ['identity_unavailable', collect()];
            }

            return ['available', collect($progress['encounters'] ?? [])];
        } catch (\Throwable $exception) {
            Log::warning('Unable to load applicant record FF Logs data.', ['application_id' => $application->id, 'exception' => $exception->getMessage()]);

            return ['error', collect()];
        }
    }
}
