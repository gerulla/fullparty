<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\Group;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ActivitySeeder extends Seeder
{
    /**
     * Seed the application's activities.
     */
    public function run(): void
    {
        $group = Group::query()
            ->with('memberships')
            ->where('slug', 'ftel')
            ->first();

        if (!$group) {
            throw new RuntimeException('Forked Tower Enjoyers group was not found. Seed groups before activities.');
        }

        $activityType = ActivityType::query()
            ->with('currentPublishedVersion')
            ->where('slug', 'forked-tower')
            ->first();

        if (!$activityType || !$activityType->currentPublishedVersion) {
            throw new RuntimeException('Forked Tower activity type was not found. Seed activity types before activities.');
        }

        DB::transaction(function () use ($group, $activityType) {
            DB::table('activity_slot_field_values')->delete();
            DB::table('activity_slots')->delete();
            DB::table('activity_application_answers')->delete();
            DB::table('activity_applications')->delete();
            DB::table('activity_progress_milestones')->delete();
            DB::table('activities')->delete();

            $organizerIds = $group->memberships
                ->pluck('user_id')
                ->unique()
                ->values()
                ->all();

            foreach ($this->activityBlueprints() as $index => $blueprint) {
                $startsAt = Carbon::parse($blueprint['starts_at']);
                $status = $this->resolveStatus($startsAt, $index);
                $organizerId = $organizerIds[$index % count($organizerIds)] ?? $group->owner_id;

                $activity = $group->activities()->create([
                    'activity_type_id' => $activityType->id,
                    'activity_type_version_id' => $activityType->currentPublishedVersion->id,
                    'organized_by_user_id' => $organizerId,
                    'status' => $status,
                    'title' => $blueprint['title'],
                    'description' => $blueprint['description'],
                    'starts_at' => $startsAt,
                    'is_completed' => $status === Activity::STATUS_COMPLETE,
                    'completed_at' => $status === Activity::STATUS_COMPLETE ? $startsAt->copy()->addHours(3) : null,
                    'created_at' => $startsAt->copy()->subDays(5),
                    'updated_at' => $status === Activity::STATUS_COMPLETE
                        ? $startsAt->copy()->addHours(3)
                        : $startsAt->copy()->subHours(4),
                ]);

                $this->materializeSlots($activity, $activityType->currentPublishedVersion);
                $this->materializeProgressMilestones($activity, $activityType->currentPublishedVersion, $status);
            }
        });
    }

    /**
     * @return array<int, array{title: string, description: string, starts_at: string}>
     */
    private function activityBlueprints(): array
    {
        return [
            $this->activityBlueprint('Forked Tower Fresh Prog', 'Fresh pull night focused on getting everyone comfortable with boss one assignments.', '2026-04-02 19:30:00'),
            $this->activityBlueprint('Forked Tower Boss 2 Cleanup', 'Follow-up run for players who already saw Demon Tablet and want smoother boss two pulls.', '2026-04-02 22:00:00'),
            $this->activityBlueprint('Forked Tower Reclear Night', 'Weekly reclear pace with flexible party lead assignments.', '2026-04-04 19:00:00'),
            $this->activityBlueprint('Forked Tower Learning Party', 'Relaxed teaching-focused run for newer members and alt characters.', '2026-04-08 19:30:00'),
            $this->activityBlueprint('Forked Tower Boss 3 Prog', 'Marble Dragon progression with emphasis on cleaner transitions and recoveries.', '2026-04-08 22:00:00'),
            $this->activityBlueprint('Forked Tower Weekend Push', 'Longer weekend block for groups aiming to get deeper into boss three.', '2026-04-08 23:30:00'),
            $this->activityBlueprint('Forked Tower Midweek Reclear', 'Structured reclear run with pre-assigned support and lead roles.', '2026-04-14 19:30:00'),
            $this->activityBlueprint('Forked Tower Tonight', 'Tonight\'s run for members available on short notice.', '2026-04-16 20:00:00'),
            $this->activityBlueprint('Forked Tower Friday Prog', 'Friday evening progression run with callout coverage for every party.', '2026-04-16 22:30:00'),
            $this->activityBlueprint('Forked Tower Saturday Learning', 'Weekend learner-friendly session for new signups and backups.', '2026-04-18 18:30:00'),
            $this->activityBlueprint('Forked Tower Boss 4 Attempts', 'Dedicated Magitaur attempts for members already consistent on earlier bosses.', '2026-04-18 21:30:00'),
            $this->activityBlueprint('Forked Tower Weekly Clear', 'Core weekly clear run with experienced players filling key spots.', '2026-04-22 19:30:00'),
            $this->activityBlueprint('Forked Tower Late Night Alt Run', 'Alt-focused run with looser roster expectations and backup slots.', '2026-04-22 22:00:00'),
            $this->activityBlueprint('Forked Tower Sunday Push', 'Long-form progression push for anyone close to a clear.', '2026-04-22 23:30:00'),
            $this->activityBlueprint('Forked Tower Cleanup & Clears', 'Cleanup session for parties needing one more consistent set of pulls.', '2026-04-27 19:30:00'),
            $this->activityBlueprint('Forked Tower End of Month Reclear', 'Month-end reclear to wrap up April and test alternate compositions.', '2026-04-29 19:30:00'),
            $this->activityBlueprint('Forked Tower Casual Night', 'Casual social run for whoever is around at the end of the month.', '2026-04-29 22:30:00'),
        ];
    }

    /**
     * @return array{title: string, description: string, starts_at: string}
     */
    private function activityBlueprint(string $title, string $description, string $startsAt): array
    {
        return [
            'title' => $title,
            'description' => $description,
            'starts_at' => $startsAt,
        ];
    }

    private function resolveStatus(Carbon $startsAt, int $index): string
    {
        $now = now();

        if ($startsAt->isFuture()) {
            if ($startsAt->isSameDay($now)) {
                return $startsAt->lessThanOrEqualTo($now->copy()->addHours(2))
                    ? Activity::STATUS_ONGOING
                    : Activity::STATUS_UPCOMING;
            }

            $daysUntil = $now->diffInDays($startsAt, false);

            if ($daysUntil <= 2) {
                return Activity::STATUS_UPCOMING;
            }

            if ($daysUntil <= 7) {
                return Activity::STATUS_SCHEDULED;
            }

            return Activity::STATUS_PLANNED;
        }

        return $index % 6 === 0
            ? Activity::STATUS_CANCELLED
            : Activity::STATUS_COMPLETE;
    }

    private function materializeSlots(Activity $activity, ActivityTypeVersion $activityTypeVersion): void
    {
        $slotDefinitions = $activityTypeVersion->slot_schema ?? [];
        $groups = $activityTypeVersion->layout_schema['groups'] ?? [];
        $sortOrder = 1;

        foreach ($groups as $groupDefinition) {
            $groupKey = (string) ($groupDefinition['key'] ?? 'group');
            $groupLabel = is_array($groupDefinition['label'] ?? null) ? $groupDefinition['label'] : ['en' => $groupKey];
            $size = max(1, (int) ($groupDefinition['size'] ?? 1));

            for ($position = 1; $position <= $size; $position++) {
                $slot = $activity->slots()->create([
                    'group_key' => $groupKey,
                    'group_label' => $groupLabel,
                    'slot_key' => sprintf('%s-slot-%d', $groupKey, $position),
                    'slot_label' => ['en' => sprintf('%s %d', $groupLabel['en'] ?? $groupKey, $position)],
                    'position_in_group' => $position,
                    'sort_order' => $sortOrder,
                ]);

                foreach ($slotDefinitions as $fieldDefinition) {
                    $slot->fieldValues()->create([
                        'field_key' => (string) ($fieldDefinition['key'] ?? ''),
                        'field_label' => is_array($fieldDefinition['label'] ?? null) ? $fieldDefinition['label'] : ['en' => (string) ($fieldDefinition['key'] ?? '')],
                        'field_type' => (string) ($fieldDefinition['type'] ?? 'text'),
                        'source' => $fieldDefinition['source'] ?? null,
                        'value' => null,
                    ]);
                }

                $sortOrder++;
            }
        }
    }

    private function materializeProgressMilestones(Activity $activity, ActivityTypeVersion $activityTypeVersion, string $status): void
    {
        $milestones = $activityTypeVersion->progress_schema['milestones'] ?? [];

        foreach ($milestones as $index => $milestoneDefinition) {
            $isEarlierMilestone = $status === Activity::STATUS_COMPLETE && $index < 2;
            $isFinalMilestone = $status === Activity::STATUS_COMPLETE && $index === count($milestones) - 1;

            $activity->progressMilestones()->create([
                'milestone_key' => (string) ($milestoneDefinition['key'] ?? ('milestone-'.($index + 1))),
                'milestone_label' => is_array($milestoneDefinition['label'] ?? null) ? $milestoneDefinition['label'] : ['en' => (string) ($milestoneDefinition['key'] ?? 'Milestone')],
                'sort_order' => (int) ($milestoneDefinition['order'] ?? $index + 1),
                'kills' => $status === Activity::STATUS_COMPLETE
                    ? ($isEarlierMilestone ? 1 : ($isFinalMilestone ? 1 : 0))
                    : 0,
                'best_progress_percent' => $status === Activity::STATUS_COMPLETE
                    ? ($isEarlierMilestone || $isFinalMilestone ? 100 : null)
                    : null,
                'source' => null,
                'notes' => null,
            ]);
        }
    }
}
