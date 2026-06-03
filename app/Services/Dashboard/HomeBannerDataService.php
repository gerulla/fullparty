<?php

namespace App\Services\Dashboard;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlotAssignment;
use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\PhantomJob;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class HomeBannerDataService
{
    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        return [
            ...$this->baseForUser($user),
            ...$this->detailsForUser($user),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function baseForUser(User $user): array
    {
        $user->loadMissing([
            'homeProfile.displayCharacterClass',
            'primaryCharacter.classes',
            'primaryCharacter.preferredClasses',
        ]);

        $displayClass = $this->resolveDisplayClass($user);

        return [
            'character' => $this->serializeCharacter($user, $displayClass),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detailsForUser(User $user): array
    {
        return [
            'last_run' => $this->serializeLastRun($user),
            'next_run' => $this->serializeNextRun($user),
            'weekly_participation' => $this->serializeWeeklyParticipation($user),
        ];
    }

    private function resolveDisplayClass(User $user): ?CharacterClass
    {
        $character = $user->primaryCharacter;

        if (! $character) {
            return null;
        }

        if ($user->homeProfile?->displayCharacterClass) {
            return $user->homeProfile->displayCharacterClass;
        }

        $character->loadMissing(['preferredClasses', 'classes']);

        return $character->preferredClasses->first()
            ?? $character->classes->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCharacter(User $user, ?CharacterClass $displayClass): array
    {
        $character = $user->primaryCharacter;

        return [
            'id' => $character?->id,
            'name' => $character?->name ?? $user->name,
            'world' => $character?->world,
            'datacenter' => $character?->datacenter,
            'avatar_url' => $character?->avatar_url ?? $user->avatar_url,
            'display_job' => $displayClass ? $this->serializeCharacterClass($displayClass) : null,
            'display_job_level' => $this->resolveDisplayClassLevel($character, $displayClass),
        ];
    }

    private function resolveDisplayClassLevel(?Character $character, ?CharacterClass $displayClass): ?int
    {
        if (! $character || ! $displayClass) {
            return null;
        }

        $character->loadMissing('classes');

        $progress = $character->classes->firstWhere('id', $displayClass->id);
        $level = $progress?->pivot?->level;

        return $level === null ? null : (int) $level;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCharacterClass(CharacterClass $characterClass): array
    {
        return [
            'id' => $characterClass->id,
            'name' => $characterClass->name,
            'shorthand' => $characterClass->shorthand,
            'role' => $characterClass->role,
            'icon_url' => $characterClass->icon_url,
            'flaticon_url' => $characterClass->flaticon_url,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeLastRun(User $user): ?array
    {
        $characterIds = $user->characters()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $application = $this->latestCompletedApplication($user);
        $assignment = $this->latestCompletedAssignment($characterIds);
        $applicationAssignment = $application
            ? $this->latestApplicationAssignment($application)
            : null;
        $candidate = collect([
            $application?->activity ? [
                'activity' => $application->activity,
                'assignment' => $applicationAssignment,
            ] : null,
            $assignment?->activity ? [
                'activity' => $assignment->activity,
                'assignment' => $assignment,
            ] : null,
        ])
            ->filter()
            ->sortByDesc(fn (array $candidate) => $this->lastRunTimestamp($candidate['activity']))
            ->first();

        if (! $candidate) {
            return null;
        }

        $activity = $candidate['activity'];
        $loadout = $this->serializeAssignmentLoadout($candidate['assignment']);

        return [
            'activity_title' => $activity->title,
            'activity_type_name' => $activity->activityTypeVersion?->name,
            'activity_icon_url' => $activity->activityTypeVersion?->small_image_url
                ?: $activity->activityTypeVersion?->banner_image_url,
            'activity_icon' => 'i-lucide-swords',
            'progress' => $this->resolveProgressPercent($activity),
            'progress_label' => $this->resolveProgressLabel($activity),
            'class_name' => $loadout['class_name'],
            'class_icon_url' => $loadout['class_icon_url'],
            'phantom_job_name' => $loadout['phantom_job_name'],
            'phantom_job_icon_url' => $loadout['phantom_job_icon_url'],
            'completed_at' => ($activity->completed_at ?? $activity->starts_at)?->toIso8601String(),
        ];
    }

    private function lastRunTimestamp(Activity $activity): int
    {
        return ($activity->completed_at ?? $activity->starts_at)?->getTimestamp() ?? 0;
    }

    private function latestCompletedApplication(User $user): ?ActivityApplication
    {
        return ActivityApplication::query()
            ->select('activity_applications.*')
            ->join('activities', 'activities.id', '=', 'activity_applications.activity_id')
            ->with([
                'activity.activityTypeVersion',
                'activity.progressMilestones',
                'selectedCharacter',
            ])
            ->where('activity_applications.user_id', $user->id)
            ->whereIn('activity_applications.status', [
                ActivityApplication::STATUS_APPROVED,
                ActivityApplication::STATUS_ON_BENCH,
            ])
            ->where('activities.status', Activity::STATUS_COMPLETE)
            ->orderByDesc('activities.completed_at')
            ->orderByDesc('activities.starts_at')
            ->orderByDesc('activity_applications.submitted_at')
            ->orderByDesc('activity_applications.id')
            ->first();
    }

    private function latestApplicationAssignment(ActivityApplication $application): ?ActivitySlotAssignment
    {
        return ActivitySlotAssignment::query()
            ->where('application_id', $application->id)
            ->latest('assigned_at')
            ->latest('id')
            ->first([
                'id',
                'application_id',
                'field_values_snapshot',
                'assigned_at',
            ]);
    }

    /**
     * @param  array<int, int>  $characterIds
     */
    private function latestCompletedAssignment(array $characterIds): ?ActivitySlotAssignment
    {
        if ($characterIds === []) {
            return null;
        }

        return ActivitySlotAssignment::query()
            ->select('activity_slot_assignments.*')
            ->join('activities', 'activities.id', '=', 'activity_slot_assignments.activity_id')
            ->with([
                'activity.activityTypeVersion',
                'activity.progressMilestones',
            ])
            ->whereIn('activity_slot_assignments.character_id', $characterIds)
            ->where('activities.status', Activity::STATUS_COMPLETE)
            ->whereIn('activity_slot_assignments.attendance_status', [
                ActivitySlotAssignment::STATUS_ASSIGNED,
                ActivitySlotAssignment::STATUS_CHECKED_IN,
                ActivitySlotAssignment::STATUS_LATE,
            ])
            ->orderByDesc('activities.completed_at')
            ->orderByDesc('activities.starts_at')
            ->orderByDesc('activity_slot_assignments.assigned_at')
            ->orderByDesc('activity_slot_assignments.id')
            ->first();
    }

    /**
     * @return array{class_name: string|null, class_icon_url: string|null, phantom_job_name: string|null, phantom_job_icon_url: string|null}
     */
    private function serializeAssignmentLoadout(?ActivitySlotAssignment $assignment): array
    {
        $snapshot = is_array($assignment?->field_values_snapshot)
            ? $assignment->field_values_snapshot
            : [];
        $class = $this->enrichClassSnapshotItem($this->firstSnapshotItem($snapshot, 'class'));
        $phantomJob = $this->enrichPhantomJobSnapshotItem($this->firstSnapshotItem($snapshot, 'phantom_job'));

        return [
            'class_name' => $class['name'] ?? null,
            'class_icon_url' => $class['icon_url'] ?? null,
            'phantom_job_name' => $phantomJob['name'] ?? null,
            'phantom_job_icon_url' => $phantomJob['icon_url'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>|null
     */
    private function firstSnapshotItem(array $snapshot, string $kind): ?array
    {
        foreach ($snapshot as $fieldKey => $value) {
            if (! is_array($value)) {
                continue;
            }

            $values = array_is_list($value) && isset($value[0]) && is_array($value[0])
                ? $value
                : [$value];

            foreach ($values as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                if ($kind === 'class' && $this->isClassSnapshotEntry((string) $fieldKey, $entry)) {
                    return $entry;
                }

                if ($kind === 'phantom_job' && $this->isPhantomJobSnapshotEntry((string) $fieldKey, $entry)) {
                    return $entry;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function isClassSnapshotEntry(string $fieldKey, array $entry): bool
    {
        return str_contains($fieldKey, 'class')
            || array_key_exists('role', $entry)
            || array_key_exists('shorthand', $entry);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function isPhantomJobSnapshotEntry(string $fieldKey, array $entry): bool
    {
        return str_contains($fieldKey, 'phantom')
            || (str_contains($fieldKey, 'job') && ! $this->isClassSnapshotEntry($fieldKey, $entry));
    }

    /**
     * @param  array<string, mixed>|null  $item
     * @return array{name: string, icon_url: string|null}|null
     */
    private function enrichClassSnapshotItem(?array $item): ?array
    {
        if (! $item) {
            return null;
        }

        if (filled($item['id'] ?? null)) {
            $characterClass = CharacterClass::query()
                ->select(['id', 'name', 'icon_url', 'flaticon_url'])
                ->find((int) $item['id']);

            if ($characterClass) {
                return [
                    'name' => $characterClass->name,
                    'icon_url' => $characterClass->icon_url ?: $characterClass->flaticon_url,
                ];
            }
        }

        $name = $item['name'] ?? $item['label'] ?? null;

        if (! filled($name)) {
            return null;
        }

        return [
            'name' => (string) $name,
            'icon_url' => $item['icon_url'] ?? $item['flaticon_url'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $item
     * @return array{name: string, icon_url: string|null}|null
     */
    private function enrichPhantomJobSnapshotItem(?array $item): ?array
    {
        if (! $item) {
            return null;
        }

        if (filled($item['id'] ?? null)) {
            $phantomJob = PhantomJob::query()
                ->select(['id', 'name', 'icon_url', 'transparent_icon_url'])
                ->find((int) $item['id']);

            if ($phantomJob) {
                return [
                    'name' => $phantomJob->name,
                    'icon_url' => $phantomJob->transparent_icon_url ?: $phantomJob->icon_url,
                ];
            }
        }

        $name = $item['name'] ?? $item['label'] ?? null;

        if (! filled($name)) {
            return null;
        }

        return [
            'name' => (string) $name,
            'icon_url' => $item['transparent_icon_url'] ?? $item['icon_url'] ?? null,
        ];
    }

    private function resolveProgressPercent(Activity $activity): ?int
    {
        if ($activity->furthest_progress_percent !== null) {
            return (int) round((float) $activity->furthest_progress_percent);
        }

        return $activity->is_completed || $activity->status === Activity::STATUS_COMPLETE
            ? 100
            : null;
    }

    /**
     * @return array<string, string|null>|null
     */
    private function resolveProgressLabel(Activity $activity): ?array
    {
        if (blank($activity->furthest_progress_key)) {
            return null;
        }

        $milestoneLabel = $activity->progressMilestones
            ->firstWhere('milestone_key', $activity->furthest_progress_key)
            ?->milestone_label;

        if (is_array($milestoneLabel)) {
            return $milestoneLabel;
        }

        $schemaMilestone = collect($activity->activityTypeVersion?->progress_schema['milestones'] ?? [])
            ->firstWhere('key', $activity->furthest_progress_key);

        if (is_array($schemaMilestone) && is_array($schemaMilestone['label'] ?? null)) {
            return $schemaMilestone['label'];
        }

        $progPoint = collect($activity->activityTypeVersion?->prog_points ?? [])
            ->firstWhere('key', $activity->furthest_progress_key);

        if (is_array($progPoint) && is_array($progPoint['label'] ?? null)) {
            return $progPoint['label'];
        }

        return ['en' => (string) $activity->furthest_progress_key];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeNextRun(User $user): ?array
    {
        $now = CarbonImmutable::now();
        $until = $now->addHours(6);
        $characterIds = $user->characters()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $activity = collect([
            $this->nextApplicationActivity($user, $now, $until),
            $this->nextAssignedActivity($characterIds, $now, $until),
            $this->nextCurrentSlotActivity($characterIds, $now, $until),
        ])
            ->filter()
            ->unique('id')
            ->sortBy(fn (Activity $activity) => $activity->starts_at?->getTimestamp() ?? PHP_INT_MAX)
            ->first();

        if (! $activity) {
            return null;
        }

        return [
            'activity_id' => $activity->id,
            'activity_title' => $activity->title,
            'activity_type_name' => $activity->activityTypeVersion?->name,
            'starts_at' => $activity->starts_at?->toIso8601String(),
            'group' => [
                'name' => $activity->group?->name,
                'slug' => $activity->group?->slug,
            ],
        ];
    }

    private function nextApplicationActivity(User $user, CarbonImmutable $now, CarbonImmutable $until): ?Activity
    {
        return ActivityApplication::query()
            ->select('activity_applications.*')
            ->join('activities', 'activities.id', '=', 'activity_applications.activity_id')
            ->with([
                'activity.group',
                'activity.activityTypeVersion',
            ])
            ->where('activity_applications.user_id', $user->id)
            ->whereIn('activity_applications.status', [
                ActivityApplication::STATUS_APPROVED,
                ActivityApplication::STATUS_ON_BENCH,
            ])
            ->whereNotIn('activities.status', Activity::ARCHIVED_STATUSES)
            ->whereNotNull('activities.starts_at')
            ->where('activities.starts_at', '>=', $now)
            ->where('activities.starts_at', '<=', $until)
            ->orderBy('activities.starts_at')
            ->first()
            ?->activity;
    }

    /**
     * @param  array<int, int>  $characterIds
     */
    private function nextAssignedActivity(array $characterIds, CarbonImmutable $now, CarbonImmutable $until): ?Activity
    {
        if ($characterIds === []) {
            return null;
        }

        return ActivitySlotAssignment::query()
            ->select('activity_slot_assignments.*')
            ->join('activities', 'activities.id', '=', 'activity_slot_assignments.activity_id')
            ->with([
                'activity.group',
                'activity.activityTypeVersion',
            ])
            ->whereIn('activity_slot_assignments.character_id', $characterIds)
            ->whereNull('activity_slot_assignments.ended_at')
            ->whereNotIn('activities.status', Activity::ARCHIVED_STATUSES)
            ->whereNotNull('activities.starts_at')
            ->where('activities.starts_at', '>=', $now)
            ->where('activities.starts_at', '<=', $until)
            ->orderBy('activities.starts_at')
            ->first()
            ?->activity;
    }

    /**
     * @param  array<int, int>  $characterIds
     */
    private function nextCurrentSlotActivity(array $characterIds, CarbonImmutable $now, CarbonImmutable $until): ?Activity
    {
        if ($characterIds === []) {
            return null;
        }

        return Activity::query()
            ->with(['group', 'activityTypeVersion'])
            ->whereHas('slots', fn ($query) => $query->whereIn('assigned_character_id', $characterIds))
            ->whereNotIn('status', Activity::ARCHIVED_STATUSES)
            ->whereNotNull('starts_at')
            ->where('starts_at', '>=', $now)
            ->where('starts_at', '<=', $until)
            ->orderBy('starts_at')
            ->first();
    }

    /**
     * @return array<int, array{start: string, end: string, count: int}>
     */
    private function serializeWeeklyParticipation(User $user): array
    {
        $now = CarbonImmutable::now();
        $firstWeekStart = $now->startOfWeek()->subWeeks(13);
        $weeks = collect(range(0, 13))
            ->map(fn (int $offset) => $firstWeekStart->addWeeks($offset));
        $lastWeekEnd = $weeks->last()->addWeek();
        $applications = $this->participationApplicationsBetween($user, $firstWeekStart, $lastWeekEnd);

        return $weeks
            ->map(function (CarbonImmutable $start) use ($applications) {
                $end = $start->addWeek();

                return [
                    'start' => $start->toDateString(),
                    'end' => $end->toDateString(),
                    'count' => $applications
                        ->filter(function (ActivityApplication $application) use ($start, $end) {
                            $startsAt = $this->toImmutable($application->activity?->starts_at);

                            return $startsAt?->gte($start) && $startsAt->lt($end);
                        })
                        ->count(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, ActivityApplication>
     */
    private function participationApplicationsBetween(User $user, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return ActivityApplication::query()
            ->select('activity_applications.*')
            ->join('activities', 'activities.id', '=', 'activity_applications.activity_id')
            ->with('activity:id,starts_at')
            ->where('activity_applications.user_id', $user->id)
            ->whereIn('activity_applications.status', [
                ActivityApplication::STATUS_APPROVED,
                ActivityApplication::STATUS_ON_BENCH,
            ])
            ->whereNotNull('activities.starts_at')
            ->where('activities.starts_at', '>=', $start)
            ->where('activities.starts_at', '<', $end)
            ->get();
    }

    private function toImmutable(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value);
        }

        if (filled($value)) {
            return CarbonImmutable::parse($value);
        }

        return null;
    }
}
