<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\Character;
use App\Services\FFLogs\CharacterZoneProgressFetcher;
use App\Support\FFLogsDifficulty;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AllianceProgressService
{
    public function __construct(
        private readonly CharacterActivityHistoryService $history,
        private readonly CharacterZoneProgressFetcher $fflogs,
    ) {}

    public function forCharacters(Activity $activity, Collection $characters): array
    {
        $activity->loadMissing('activityTypeVersion');
        $definitions = collect($activity->activityTypeVersion?->progress_schema['milestones'] ?? [])
            ->filter(fn (array $definition) => filled($definition['key'] ?? null))
            ->sortBy('order')->keyBy('key');
        $points = collect($activity->activityTypeVersion?->prog_points ?? [])->pluck('key')->filter()->values();
        if ($points->isEmpty()) {
            $points = $definitions->keys();
        }

        return $characters->mapWithKeys(fn (Character $character) => [
            $character->id => $this->forCharacter($activity, $character, $definitions, $points),
        ])->all();
    }

    private function forCharacter(Activity $activity, Character $character, Collection $definitions, Collection $points): array
    {
        $reached = [];
        $killed = [];
        foreach ($this->history->completedRuns($activity, $character->id) as $run) {
            $historicalDefinitions = collect($run->activityTypeVersion?->progress_schema['milestones'] ?? [])->keyBy('key');
            foreach ($definitions as $key => $definition) {
                $matches = $run->progressMilestones->filter(fn ($milestone) => $this->history->sameMilestone(
                    $definition, $historicalDefinitions->get($milestone->milestone_key, ['key' => $milestone->milestone_key]),
                ));
                if ($matches->contains(fn ($milestone) => $milestone->kills > 0)) {
                    $killed[$key] = true;
                    $reached[$key] = true;
                } elseif ($matches->contains(fn ($milestone) => (float) $milestone->best_progress_percent > 0)) {
                    $reached[$key] = true;
                }
            }

            // Some progression points (for example, bridges) have no boss milestone.
            $furthestKey = $run->furthest_progress_key;
            if ($furthestKey && $points->contains($furthestKey)) {
                $currentDefinition = $definitions->get($furthestKey);
                $oldDefinition = $historicalDefinitions->get($furthestKey);
                if (! $currentDefinition || ! $oldDefinition || $this->history->sameMilestone($currentDefinition, $oldDefinition)) {
                    $reached[$furthestKey] = true;
                }
            }
        }

        $fflogsUnavailable = false;
        $zoneId = (int) ($activity->activityTypeVersion?->fflogs_zone_id ?? 0);
        if ($zoneId > 0 && $definitions->contains(fn (array $definition) => (int) data_get($definition, 'fflogs_matcher.encounter_id') > 0)) {
            try {
                $encounters = collect($this->fflogs->fetchEncounterProgressForCharacter(
                    $character, $zoneId, FFLogsDifficulty::forActivityTypeVersion($activity->activityTypeVersion),
                )['encounters'] ?? [])->keyBy('encounter_id');
                foreach ($definitions as $key => $definition) {
                    $encounter = $encounters->get((int) data_get($definition, 'fflogs_matcher.encounter_id', 0));
                    if (! $encounter) {
                        continue;
                    }
                    if ((int) ($encounter['kills'] ?? 0) > 0) {
                        // A whole-fight kill also proves every phase of that fight was cleared.
                        $killed[$key] = true;
                        $reached[$key] = true;
                    } elseif (data_get($definition, 'fflogs_matcher.type', 'encounter') === 'encounter'
                        && (float) ($encounter['progress'] ?? 0) > 0) {
                        $reached[$key] = true;
                    } elseif ((float) ($encounter['progress'] ?? 0) > 0
                        && $definitions->first(fn ($candidate) => data_get($candidate, 'fflogs_matcher.encounter_id') === data_get($definition, 'fflogs_matcher.encounter_id'))['key'] === $key) {
                        // A pull proves the first phase was reached, but not any later phase.
                        $reached[$key] = true;
                    }
                }
            } catch (\Throwable $exception) {
                $fflogsUnavailable = true;
                Log::warning('Unable to load alliance FF Logs progress.', ['character_id' => $character->id, 'activity_id' => $activity->id, 'exception' => $exception->getMessage()]);
            }
        }

        $cleared = ($definitions->isNotEmpty() && $definitions->keys()->every(fn ($key) => isset($killed[$key])))
            || ($points->isNotEmpty() && isset($killed[$points->last()]));
        $furthest = false;
        foreach ($points as $index => $key) {
            if (isset($reached[$key])) {
                $furthest = $index;
            }
        }
        $target = $points->search($activity->target_prog_point_key);
        $status = match (true) {
            $cleared => 'cleared',
            $reached === [] => 'no_progress',
            $target === false || ($furthest !== false && $furthest >= $target) => 'at_target',
            default => 'below_target',
        };

        return ['status' => $status, 'fflogs_unavailable' => $fflogsUnavailable];
    }
}
