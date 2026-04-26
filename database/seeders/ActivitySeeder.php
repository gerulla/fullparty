<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivityType;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use RuntimeException;

class ActivitySeeder extends Seeder
{
    /**
     * Seed the application's activities.
     */
    public function run(): void
    {
        $groups = Group::query()
            ->with([
                'memberships.user.primaryCharacter',
            ])
            ->orderBy('id')
            ->get();

        if ($groups->count() < 20) {
            throw new RuntimeException('Expected at least 20 groups before seeding activities.');
        }

        $activityTypes = ActivityType::query()
            ->with('currentPublishedVersion')
            ->get()
            ->keyBy('slug');

        $forkedTower = $activityTypes->get('forked-tower');
        $chaotic = $activityTypes->get('cloud-of-darkness-chaotic');
        $savage = $activityTypes->get('savage-raids');

        if (!$forkedTower?->currentPublishedVersion || !$chaotic?->currentPublishedVersion || !$savage?->currentPublishedVersion) {
            throw new RuntimeException('Expected published activity types for forked tower, chaotic, and savage.');
        }

        $referenceDate = now()->startOfDay();

        $groups->each(function (Group $group) use ($forkedTower, $chaotic, $savage) {
            $groupMemberUsers = $group->memberships
                ->map(fn (GroupMembership $membership) => $membership->user)
                ->filter(fn (?User $user) => $user instanceof User && $user->primaryCharacter)
                ->values();

            if ($groupMemberUsers->isEmpty()) {
                return;
            }

            $organizerPool = $group->memberships
                ->filter(fn (GroupMembership $membership) => $membership->user && $membership->user->primaryCharacter)
                ->values();

            $activityCount = $group->slug === 'ftel'
                ? 24
                : fake()->numberBetween(5, 50);

            foreach (range(1, $activityCount) as $activityIndex) {
                $type = $group->slug === 'ftel'
                    ? $forkedTower
                    : $this->pickActivityType($forkedTower, $chaotic, $savage);

                /** @var GroupMembership $organizerMembership */
                $organizerMembership = $organizerPool->random();
                $organizer = $organizerMembership->user;
                $startsAt = $this->futureStartsAt($activityIndex);
                $status = $this->resolveFutureStatus($startsAt);
                $progPointKey = $this->pickTargetProgPointKey($type->currentPublishedVersion->prog_points ?? []);
                $slotGroups = $type->currentPublishedVersion->layout_schema['groups'] ?? [];
                $minAssignedSlots = $this->minimumAssignedSlotCount($slotGroups);
                $maxAssignedSlots = $this->maximumAssignedSlotCount($slotGroups, $minAssignedSlots);

                $activity = Activity::factory()
                    ->for($group)
                    ->for($type)
                    ->for($type->currentPublishedVersion, 'activityTypeVersion')
                    ->withRandomAssignments($minAssignedSlots, $maxAssignedSlots)
                    ->create([
                        'organized_by_user_id' => $organizer->id,
                        'organized_by_character_id' => $organizer->primaryCharacter->id,
                        'status' => $status,
                        'title' => $this->activityTitleForType($type->slug),
                        'description' => fake()->sentence(),
                        'notes' => fake()->boolean(35) ? fake()->paragraph() : null,
                        'starts_at' => $startsAt,
                        'duration_hours' => fake()->randomElement([2, 3, 6]),
                        'target_prog_point_key' => $progPointKey,
                        'is_public' => $group->is_public ? fake()->boolean(80) : fake()->boolean(35),
                        'needs_application' => true,
                        'created_at' => $startsAt->copy()->subDays(fake()->numberBetween(3, 14)),
                        'updated_at' => $startsAt->copy()->subHours(fake()->numberBetween(1, 48)),
                    ]);

                $this->seedApplicationsForActivity($activity, $groupMemberUsers, $organizerPool);
            }
        });

        $groups->each(function (Group $group) use ($forkedTower, $chaotic, $savage, $referenceDate) {
            $groupMemberUsers = $group->memberships
                ->map(fn (GroupMembership $membership) => $membership->user)
                ->filter(fn (?User $user) => $user instanceof User && $user->primaryCharacter)
                ->values();

            if ($groupMemberUsers->isEmpty()) {
                return;
            }

            $organizerPool = $group->memberships
                ->filter(fn (GroupMembership $membership) => $membership->user && $membership->user->primaryCharacter)
                ->values();

            $historicalActivityCount = $group->slug === 'ftel'
                ? 12
                : fake()->numberBetween(4, 6);

            foreach (range(1, $historicalActivityCount) as $activityIndex) {
                $type = $group->slug === 'ftel'
                    ? $forkedTower
                    : $this->pickActivityType($forkedTower, $chaotic, $savage);

                /** @var GroupMembership $organizerMembership */
                $organizerMembership = $organizerPool->random();
                $organizer = $organizerMembership->user;
                $startsAt = $this->historicalStartsAt($referenceDate, $activityIndex);
                $progPointKey = $this->pickTargetProgPointKey($type->currentPublishedVersion->prog_points ?? []);
                $slotGroups = $type->currentPublishedVersion->layout_schema['groups'] ?? [];
                $minAssignedSlots = $this->historicalMinimumAssignedSlotCount($slotGroups);
                $maxAssignedSlots = $this->historicalMaximumAssignedSlotCount($slotGroups, $minAssignedSlots);
                $durationHours = fake()->randomElement([2, 3, 6]);

                Activity::factory()
                    ->for($group)
                    ->for($type)
                    ->for($type->currentPublishedVersion, 'activityTypeVersion')
                    ->complete()
                    ->withRandomAssignments($minAssignedSlots, $maxAssignedSlots)
                    ->create([
                        'organized_by_user_id' => $organizer->id,
                        'organized_by_character_id' => $organizer->primaryCharacter->id,
                        'status' => Activity::STATUS_COMPLETE,
                        'title' => $this->historicalActivityTitleForType($type->slug),
                        'description' => fake()->sentence(),
                        'notes' => fake()->boolean(30) ? fake()->paragraph() : null,
                        'starts_at' => $startsAt,
                        'duration_hours' => $durationHours,
                        'target_prog_point_key' => $progPointKey,
                        'is_public' => $group->is_public ? fake()->boolean(80) : fake()->boolean(35),
                        'needs_application' => true,
                        'is_completed' => true,
                        'completed_at' => $startsAt->copy()->addHours($durationHours),
                        'created_at' => $startsAt->copy()->subDays(fake()->numberBetween(7, 28)),
                        'updated_at' => $startsAt->copy()->subHours(fake()->numberBetween(2, 72)),
                    ]);
            }
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     */
    private function minimumAssignedSlotCount(array $groups): int
    {
        $slotCount = collect($groups)->sum(fn (array $group) => (int) ($group['size'] ?? 0));

        return match (true) {
            $slotCount <= 8 => 1,
            $slotCount <= 24 => fake()->numberBetween(4, 10),
            default => fake()->numberBetween(8, 20),
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     */
    private function maximumAssignedSlotCount(array $groups, int $minimum): int
    {
        $slotCount = collect($groups)->sum(fn (array $group) => (int) ($group['size'] ?? 0));

        return match (true) {
            $slotCount <= 8 => $slotCount,
            $slotCount <= 24 => max($minimum, fake()->numberBetween(10, $slotCount)),
            default => max($minimum, fake()->numberBetween(20, $slotCount)),
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     */
    private function historicalMinimumAssignedSlotCount(array $groups): int
    {
        $slotCount = collect($groups)->sum(fn (array $group) => (int) ($group['size'] ?? 0));

        return match (true) {
            $slotCount <= 8 => max(4, min(8, $slotCount)),
            $slotCount <= 24 => fake()->numberBetween(10, min(18, $slotCount)),
            default => fake()->numberBetween(18, min(36, $slotCount)),
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     */
    private function historicalMaximumAssignedSlotCount(array $groups, int $minimum): int
    {
        $slotCount = collect($groups)->sum(fn (array $group) => (int) ($group['size'] ?? 0));
        $floor = max($minimum, (int) floor($slotCount * 0.75));

        return match (true) {
            $slotCount <= 8 => $slotCount,
            $slotCount <= 24 => min($slotCount, max($floor, fake()->numberBetween($minimum, $slotCount))),
            default => min($slotCount, max($floor, fake()->numberBetween($minimum, $slotCount))),
        };
    }

    private function pickActivityType(ActivityType $forkedTower, ActivityType $chaotic, ActivityType $savage): ActivityType
    {
        $roll = fake()->numberBetween(1, 100);

        if ($roll <= 60) {
            return $savage;
        }

        if ($roll <= 80) {
            return $chaotic;
        }

        return $forkedTower;
    }

    private function futureStartsAt(int $activityIndex): \Illuminate\Support\Carbon
    {
        $base = now()->startOfDay()->addDays(fake()->numberBetween(1, 90));
        $hour = fake()->randomElement([18, 19, 20, 21, 22]);
        $minute = fake()->randomElement([0, 15, 30, 45]);

        return $base
            ->copy()
            ->setTime($hour, $minute)
            ->addDays(intdiv($activityIndex, 4));
    }

    private function historicalStartsAt(\Illuminate\Support\Carbon $referenceDate, int $activityIndex): \Illuminate\Support\Carbon
    {
        $daysBack = fake()->numberBetween(5, 150) + intdiv($activityIndex, 3);
        $hour = fake()->randomElement([18, 19, 20, 21, 22]);
        $minute = fake()->randomElement([0, 15, 30, 45]);

        return $referenceDate
            ->copy()
            ->subDays($daysBack)
            ->setTime($hour, $minute);
    }

    private function resolveFutureStatus(\Illuminate\Support\Carbon $startsAt): string
    {
        $daysUntil = now()->diffInDays($startsAt, false);

        if ($daysUntil <= 2) {
            return Activity::STATUS_UPCOMING;
        }

        if ($daysUntil <= 10) {
            return Activity::STATUS_SCHEDULED;
        }

        return Activity::STATUS_PLANNED;
    }

    /**
     * @param  array<int, array<string, mixed>>  $progPoints
     */
    private function pickTargetProgPointKey(array $progPoints): ?string
    {
        if ($progPoints === []) {
            return null;
        }

        $point = collect($progPoints)->random();

        return $point['key'] ?? null;
    }

    private function activityTitleForType(string $slug): string
    {
        return match ($slug) {
            'forked-tower' => fake()->randomElement([
                'Forked Tower Weekly Clear',
                'Forked Tower Fresh Prog',
                'Forked Tower Bridges Cleanup',
                'Forked Tower Late Night Push',
                'Forked Tower Magitaur Attempts',
            ]),
            'cloud-of-darkness-chaotic' => fake()->randomElement([
                'Chaotic Alliance Fill',
                'Cloud of Darkness Reclear',
                'Chaotic Learning Run',
                'Alliance Night Pulls',
            ]),
            default => fake()->randomElement([
                'Savage Weekly Reclear',
                'Savage Prog Night',
                'Savage Alt Run',
                'Savage Static Fill',
            ]),
        };
    }

    private function historicalActivityTitleForType(string $slug): string
    {
        return match ($slug) {
            'forked-tower' => fake()->randomElement([
                'Forked Tower Reclear Night',
                'Forked Tower Archive Clear',
                'Forked Tower Weekly History',
                'Forked Tower Blood Cleanup',
            ]),
            'cloud-of-darkness-chaotic' => fake()->randomElement([
                'Chaotic Archive Clear',
                'Cloud of Darkness Farm',
                'Chaotic Reclear Night',
            ]),
            default => fake()->randomElement([
                'Savage Reclear Archive',
                'Savage Historical Farm',
                'Savage Weekly Log Run',
            ]),
        };
    }

    private function seedApplicationsForActivity(Activity $activity, Collection $groupMemberUsers, Collection $organizerPool): void
    {
        $applicantPool = $groupMemberUsers
            ->reject(fn (User $user) => $user->id === $activity->organized_by_user_id)
            ->shuffle()
            ->values();

        if ($applicantPool->isEmpty()) {
            return;
        }

        $memberCount = $groupMemberUsers->count();
        $baseCount = (int) round($memberCount * fake()->randomFloat(2, 0.08, 0.65));
        $applicationCount = max(1, min(
            100,
            $applicantPool->count(),
            $baseCount + fake()->numberBetween(0, 12)
        ));

        $selectedApplicants = $applicantPool->take($applicationCount);

        $selectedApplicants->each(function (User $user) use ($activity, $organizerPool): void {
            $status = fake()->randomElement([
                ActivityApplication::STATUS_PENDING,
                ActivityApplication::STATUS_PENDING,
                ActivityApplication::STATUS_PENDING,
                ActivityApplication::STATUS_APPROVED,
                ActivityApplication::STATUS_DECLINED,
            ]);

            $reviewerId = null;
            $reviewedAt = null;

            if ($status !== ActivityApplication::STATUS_PENDING) {
                /** @var GroupMembership $reviewerMembership */
                $reviewerMembership = $organizerPool->random();
                $reviewerId = $reviewerMembership->user_id;
                $reviewedAt = $activity->starts_at->copy()->subHours(fake()->numberBetween(4, 48));
            }

            ActivityApplication::factory()
                ->for($activity)
                ->for($user)
                ->create([
                    'status' => $status,
                    'notes' => fake()->boolean(55) ? fake()->sentence() : null,
                    'submitted_at' => $activity->starts_at->copy()->subDays(fake()->numberBetween(1, 10)),
                    'reviewed_by_user_id' => $reviewerId,
                    'reviewed_at' => $reviewedAt,
                ]);
        });
    }
}
