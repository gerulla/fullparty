<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\ActivityProgressMilestone;
use App\Models\ActivityTypeVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivityCompletionService
{
    public const ENTRY_MODE_MANUAL = 'manual';
    public const ENTRY_MODE_FFLOGS = 'fflogs';

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function complete(Activity $activity, array $payload, int $recordedByUserId): array
    {
        $activity->loadMissing(['activityTypeVersion', 'progressMilestones']);

        $hasMilestones = $activity->progressMilestones->isNotEmpty();
        $supportsFflogs = $this->supportsFflogsCompletion($activity->activityTypeVersion);

        if (!$hasMilestones) {
            return DB::transaction(function () use ($activity, $payload, $recordedByUserId) {
                $previousStatus = $activity->status;
                $previousProgressNotes = $activity->progress_notes;

                $activity->update([
                    'status' => Activity::STATUS_COMPLETE,
                    'progress_entry_mode' => null,
                    'progress_link_url' => null,
                    'progress_notes' => blank($payload['progress_notes'] ?? null) ? null : $payload['progress_notes'],
                    'furthest_progress_key' => null,
                    'furthest_progress_percent' => null,
                    'is_completed' => true,
                    'completed_at' => now(),
                    'progress_recorded_by_user_id' => $recordedByUserId,
                    'progress_recorded_at' => now(),
                ]);

                return [
                    'status' => [
                        'old' => $previousStatus,
                        'new' => Activity::STATUS_COMPLETE,
                    ],
                    'progress_notes' => [
                        'old' => $previousProgressNotes,
                        'new' => blank($payload['progress_notes'] ?? null) ? null : $payload['progress_notes'],
                    ],
                ];
            });
        }

        $entryMode = (string) ($payload['progress_entry_mode'] ?? self::ENTRY_MODE_MANUAL);

        if (!in_array($entryMode, [self::ENTRY_MODE_MANUAL, self::ENTRY_MODE_FFLOGS], true)) {
            throw ValidationException::withMessages([
                'progress_entry_mode' => 'The selected progress entry mode is invalid.',
            ]);
        }

        if ($entryMode === self::ENTRY_MODE_FFLOGS && !$supportsFflogs) {
            throw ValidationException::withMessages([
                'progress_entry_mode' => 'FF Logs progress is not supported for this activity type.',
            ]);
        }

        if ($entryMode === self::ENTRY_MODE_FFLOGS && blank($payload['progress_link_url'] ?? null)) {
            throw ValidationException::withMessages([
                'progress_link_url' => 'An FF Logs link is required for FF Logs completion.',
            ]);
        }

        $normalizedMilestones = $this->normalizeMilestonePayload(
            $payload['milestones'] ?? [],
            $activity->progressMilestones
        );

        $furthestProgressKey = $this->normalizeFurthestProgressKey(
            $payload['furthest_progress_key'] ?? null,
            $activity->activityTypeVersion
        );

        return DB::transaction(function () use (
            $activity,
            $entryMode,
            $payload,
            $normalizedMilestones,
            $furthestProgressKey,
            $recordedByUserId,
        ) {
            $previousStatus = $activity->status;
            $previousProgressEntryMode = $activity->progress_entry_mode;
            $previousProgressLinkUrl = $activity->progress_link_url;
            $previousProgressNotes = $activity->progress_notes;
            $previousFurthestProgressKey = $activity->furthest_progress_key;

            foreach ($activity->progressMilestones as $milestone) {
                $values = $normalizedMilestones->get($milestone->milestone_key, [
                    'kills' => 0,
                    'best_progress_percent' => null,
                ]);

                $milestone->update([
                    'kills' => $values['kills'],
                    'best_progress_percent' => $values['best_progress_percent'],
                    'source' => $entryMode,
                    'notes' => null,
                ]);
            }

            $activity->update([
                'status' => Activity::STATUS_COMPLETE,
                'progress_entry_mode' => $entryMode,
                'progress_link_url' => blank($payload['progress_link_url'] ?? null) ? null : $payload['progress_link_url'],
                'progress_notes' => blank($payload['progress_notes'] ?? null) ? null : $payload['progress_notes'],
                'furthest_progress_key' => $furthestProgressKey,
                'furthest_progress_percent' => $this->resolveFurthestProgressPercent($activity->progressMilestones),
                'is_completed' => true,
                'completed_at' => now(),
                'progress_recorded_by_user_id' => $recordedByUserId,
                'progress_recorded_at' => now(),
            ]);

            return [
                'status' => [
                    'old' => $previousStatus,
                    'new' => Activity::STATUS_COMPLETE,
                ],
                'progress_entry_mode' => [
                    'old' => $previousProgressEntryMode,
                    'new' => $entryMode,
                ],
                'progress_link_url' => [
                    'old' => $previousProgressLinkUrl,
                    'new' => blank($payload['progress_link_url'] ?? null) ? null : $payload['progress_link_url'],
                ],
                'progress_notes' => [
                    'old' => $previousProgressNotes,
                    'new' => blank($payload['progress_notes'] ?? null) ? null : $payload['progress_notes'],
                ],
                'furthest_progress_key' => [
                    'old' => $previousFurthestProgressKey,
                    'new' => $furthestProgressKey,
                ],
                'progress_milestones' => $normalizedMilestones->values()->all(),
            ];
        });
    }

    public function supportsFflogsCompletion(?ActivityTypeVersion $activityTypeVersion): bool
    {
        if ((int) ($activityTypeVersion?->fflogs_zone_id ?? 0) <= 0) {
            return false;
        }

        return collect($activityTypeVersion?->progress_schema['milestones'] ?? [])
            ->contains(fn (array $milestone) => filled($milestone['fflogs_matcher']['encounter_id'] ?? null));
    }

    /**
     * @param  mixed  $milestones
     * @param  Collection<int, ActivityProgressMilestone>  $activityMilestones
     * @return Collection<string, array{milestone_key: string, kills: int, best_progress_percent: float|null}>
     */
    private function normalizeMilestonePayload(mixed $milestones, Collection $activityMilestones): Collection
    {
        if (!is_array($milestones)) {
            throw ValidationException::withMessages([
                'milestones' => 'Milestones must be provided as an array.',
            ]);
        }

        $knownKeys = $activityMilestones
            ->pluck('milestone_key')
            ->map(fn ($key) => (string) $key)
            ->all();

        return collect($milestones)
            ->mapWithKeys(function ($entry, $key) use ($knownKeys) {
                $milestoneKey = is_string($key)
                    ? $key
                    : (string) (($entry['milestone_key'] ?? ''));

                if (!in_array($milestoneKey, $knownKeys, true)) {
                    throw ValidationException::withMessages([
                        'milestones' => sprintf('Unknown milestone key [%s].', $milestoneKey),
                    ]);
                }

                $kills = max(0, (int) ($entry['kills'] ?? 0));
                $bestProgressPercent = blank($entry['best_progress_percent'] ?? null)
                    ? null
                    : round(min(100, max(0, (float) $entry['best_progress_percent'])), 2);

                return [
                    $milestoneKey => [
                        'milestone_key' => $milestoneKey,
                        'kills' => $kills,
                        'best_progress_percent' => $bestProgressPercent,
                    ],
                ];
            });
    }

    private function normalizeFurthestProgressKey(mixed $key, ?ActivityTypeVersion $activityTypeVersion): ?string
    {
        if (blank($key)) {
            return null;
        }

        $normalizedKey = (string) $key;
        $availableKeys = collect($activityTypeVersion?->prog_points ?? [])
            ->pluck('key')
            ->filter()
            ->map(fn ($value) => (string) $value)
            ->all();

        if ($availableKeys !== [] && !in_array($normalizedKey, $availableKeys, true)) {
            throw ValidationException::withMessages([
                'furthest_progress_key' => 'The selected furthest progress point is invalid for this activity type.',
            ]);
        }

        return $normalizedKey;
    }

    /**
     * @param  Collection<int, ActivityProgressMilestone>  $milestones
     */
    private function resolveFurthestProgressPercent(Collection $milestones): ?float
    {
        $bestProgress = $milestones
            ->pluck('best_progress_percent')
            ->filter(fn ($value) => $value !== null)
            ->map(fn ($value) => (float) $value);

        return $bestProgress->isEmpty()
            ? null
            : round((float) $bestProgress->max(), 2);
    }
}
