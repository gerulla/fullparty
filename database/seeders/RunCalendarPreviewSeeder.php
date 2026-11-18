<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivityType;
use App\Models\Character;
use App\Models\Group;
use App\Services\Groups\ActivitySlotAttendanceService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Additive fixtures: top up six months of runs without rebuilding the development database. */
class RunCalendarPreviewSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 403);
        if (app()->environment('local')) {
            $connection = config('database.default');
            abort_unless(in_array(config("database.connections.{$connection}.host"), ['127.0.0.1', 'localhost', '::1'], true), 403);
        }

        $now = now();
        $end = $now->copy()->addMonthsNoOverflow(6);
        $previewCharacter = Character::where('name', 'Giki Chomusuke')->whereNotNull('user_id')->first();
        $types = ActivityType::whereIn('slug', ['forked-tower', 'delubrum-reginae-savage', 'cloud-of-darkness-chaotic'])
            ->whereNotNull('current_published_version_id')->with('currentPublishedVersion')->orderBy('id')->get();
        if ($types->isEmpty()) {
            throw new \RuntimeException('Seed the published activity types before adding calendar fixtures.');
        }

        $archived = $this->archivePastRuns($now);
        $created = 0;
        foreach (Group::with('owner.primaryCharacter')->orderBy('id')->get() as $group) {
            if (! $group->owner || $group->isBanned($group->owner_id)) {
                continue;
            }
            $characters = Character::whereNotNull('verified_at')->where('is_primary', true)
                ->whereHas('user.groupMemberships', fn ($memberships) => $memberships->where('group_id', $group->id))
                ->whereNotIn('user_id', $group->bans()->select('user_id'))
                ->orderBy('id')->get();
            $previewMember = $previewCharacter && $characters->contains('user_id', $previewCharacter->user_id)
                ? $previewCharacter : null;
            // More density in the main preview group also exercises the calendar's +more state.
            $weeklySlots = $group->slug === 'ftel'
                ? [[0, 19], [2, 20], [4, 18], [5, 11], [5, 15], [5, 19], [5, 22]]
                : [[0, 19], [2, 20], [5, 18]];
            $added = 0;
            for ($week = $now->copy()->startOfWeek(); $week->lt($end); $week->addWeek()) {
                foreach ($weeklySlots as $index => [$day, $hour]) {
                    $startsAt = $week->copy()->addDays($day)->setTime($hour, ($group->id % 4) * 15);
                    if ($startsAt->lt($now) || $startsAt->gt($end)) {
                        continue;
                    }
                    $marker = "calendar-preview:{$group->id}:".$startsAt->format('Y-m-d-H-i');
                    if (Activity::where('settings->preview_seed', $marker)->exists()) {
                        continue;
                    }
                    $sequence = (int) $week->format('W') + $index + $group->id;
                    $type = $types[$sequence % $types->count()];
                    DB::transaction(function () use ($group, $type, $startsAt, $marker, $sequence, $characters, $previewMember): void {
                        $styles = [Activity::RUN_STYLE_PROGRESSION, Activity::RUN_STYLE_RECLEAR, Activity::RUN_STYLE_FARM];
                        $titles = ['Learning night', 'Weekly reclear', 'Weekend farming', 'Mechanics practice', 'First-clear workshop', 'Late-night progression'];
                        $activity = Activity::factory()->withRandomAssignments(2, 20)->create([
                            'group_id' => $group->id,
                            'activity_type_id' => $type->id,
                            'activity_type_version_id' => $type->current_published_version_id,
                            'target_prog_point_key' => PreviewRunProgPointSeeder::targetKey($type->currentPublishedVersion, $marker),
                            'organized_by_user_id' => $group->owner_id,
                            'organized_by_character_id' => $group->owner->primaryCharacter?->id,
                            'status' => $sequence % 11 === 0 ? Activity::STATUS_DRAFT : Activity::STATUS_SCHEDULED,
                            'title' => $titles[$sequence % count($titles)],
                            'description' => 'Mock run for local calendar, application and roster previews.',
                            'notes' => null,
                            'starts_at' => $startsAt,
                            'duration_hours' => $sequence % 2 ? 2 : 3,
                            'datacenter' => $group->datacenter,
                            'is_public' => $sequence % 4 !== 0,
                            'needs_application' => true,
                            'allow_guest_applications' => $sequence % 4 === 1,
                            'beginner_friendly' => $sequence % 3 === 0,
                            'run_style' => $styles[$sequence % count($styles)],
                            'settings' => ['preview_seed' => $marker],
                        ]);
                        $this->populateApplications($activity, $characters, $previewMember, $sequence);
                    });
                    $added++;
                }
            }
            $created += $added;
            $this->command?->info("{$group->slug}: added {$added} runs.");
        }

        $this->command?->info("Added {$created} runs through {$end->toDateString()}; completed {$archived['complete']} and cancelled {$archived['cancelled']} past runs.");
    }

    /** @return array{complete: int, cancelled: int} */
    private function archivePastRuns(Carbon $now): array
    {
        $counts = ['complete' => 0, 'cancelled' => 0];
        Activity::where('starts_at', '<', $now)->whereNotIn('status', Activity::ARCHIVED_STATUSES)
            ->chunkById(100, function ($activities) use ($now, &$counts): void {
                DB::transaction(function () use ($activities, $now, &$counts): void {
                    foreach ($activities as $activity) {
                        $completed = $activity->is_completed || random_int(1, 4) !== 1;
                        $status = $completed ? Activity::STATUS_COMPLETE : Activity::STATUS_CANCELLED;
                        $finishedAt = $activity->starts_at->copy()->addMinutes((int) round(($activity->duration_hours ?? 2) * 60))->min($now);
                        // Fixtures deliberately skip mail, Discord, follow-up invitations and audit delivery.
                        // Keep the cancellation's application and assignment state consistent with real runs.
                        if (! $completed) {
                            $activity->applications()->whereIn('status', ActivityApplication::ACTIVE_STATUSES)->update([
                                'status' => ActivityApplication::STATUS_CANCELLED,
                                'guest_access_token' => null,
                                'reviewed_by_user_id' => $activity->organized_by_user_id,
                                'reviewed_at' => $now,
                                'review_reason' => 'Run cancelled.',
                            ]);
                            $activity->slotAssignments()->whereNull('ended_at')->update(['ended_at' => $now]);
                        }
                        $activity->update([
                            'status' => $status,
                            'is_completed' => $completed,
                            'completed_at' => $completed ? ($activity->completed_at ?? $finishedAt) : null,
                            'progress_recorded_by_user_id' => $completed ? $activity->organized_by_user_id : null,
                            'progress_recorded_at' => $completed ? $finishedAt : null,
                        ]);
                        $counts[$status]++;
                    }
                });
            });

        return $counts;
    }

    /** @param Collection<int, Character> $characters */
    private function populateApplications(Activity $activity, Collection $characters, ?Character $previewCharacter, int $sequence): void
    {
        $activity->slots()->whereNotNull('assigned_character_id')->whereNotIn('assigned_character_id', $characters->modelKeys())
            ->update(['assigned_character_id' => null, 'assigned_by_user_id' => null]);
        $activity->load('slots.fieldValues');
        $assigned = $activity->slots->whereNotNull('assigned_character_id')->pluck('assigned_character_id');
        $applicants = $characters->whereIn('id', $assigned)->merge($characters->shuffle()->take(8));
        if ($previewCharacter && $previewCharacter->user_id !== $activity->organized_by_user_id) {
            $applicants = $applicants->push($previewCharacter);
        }

        foreach ($applicants->unique('user_id') as $character) {
            $status = $assigned->contains($character->id)
                ? ActivityApplication::STATUS_APPROVED
                : ActivityApplication::STATUS_PENDING;
            if ($character->id === $previewCharacter?->id && ! $assigned->contains($character->id)) {
                $status = [ActivityApplication::STATUS_PENDING, ActivityApplication::STATUS_APPROVED, ActivityApplication::STATUS_ON_BENCH][$sequence % 3];
                if ($status === ActivityApplication::STATUS_ON_BENCH) {
                    $activity->slots()->create([
                        'slot_kind' => ActivitySlot::SLOT_KIND_BENCH,
                        'group_key' => 'bench', 'group_label' => ['en' => 'Bench'],
                        'slot_key' => 'bench-1', 'slot_label' => ['en' => 'Bench 1'],
                        'position_in_group' => 1, 'sort_order' => $activity->slots->count() + 1,
                        'assigned_character_id' => $character->id,
                        'assigned_by_user_id' => $activity->organized_by_user_id,
                    ]);
                }
            }
            ActivityApplication::factory()->create([
                'activity_id' => $activity->id,
                'user_id' => $character->user_id,
                'selected_character_id' => $character->id,
                'status' => $status,
                'reviewed_by_user_id' => $status === ActivityApplication::STATUS_PENDING ? null : $activity->organized_by_user_id,
                'reviewed_at' => $status === ActivityApplication::STATUS_PENDING ? null : now(),
            ]);
        }

        app(ActivitySlotAttendanceService::class)->ensureActiveAssignments($activity);
    }
}
