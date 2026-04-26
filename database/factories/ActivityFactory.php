<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\ActivitySlotFieldValue;
use App\Models\ActivityProgressMilestone;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\PhantomJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    protected $model = Activity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'activity_type_id' => null,
            'activity_type_version_id' => ActivityTypeVersion::factory(),
            'organized_by_user_id' => null,
            'organized_by_character_id' => null,
            'status' => fake()->randomElement([
                Activity::STATUS_PLANNED,
                Activity::STATUS_SCHEDULED,
                Activity::STATUS_UPCOMING,
            ]),
            'title' => fake()->sentence(3),
            'description' => fake()->sentence(),
            'notes' => fake()->boolean(35) ? fake()->paragraph() : null,
            'starts_at' => now()->addDays(fake()->numberBetween(1, 21)),
            'duration_hours' => fake()->randomElement([2, 3, 6]),
            'target_prog_point_key' => null,
            'is_public' => true,
            'needs_application' => true,
            'secret_key' => null,
            'settings' => [],
            'progress_entry_mode' => null,
            'progress_link_url' => null,
            'progress_notes' => null,
            'furthest_progress_key' => null,
            'furthest_progress_percent' => null,
            'is_completed' => false,
            'completed_at' => null,
            'progress_recorded_by_user_id' => null,
            'progress_recorded_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this
            ->afterMaking(function (Activity $activity): void {
                $activity->loadMissing('group.owner', 'activityTypeVersion.activityType');

                $version = $activity->activityTypeVersion;
                $group = $activity->group;

                if ($version && !$activity->activity_type_id) {
                    $activity->activity_type_id = $version->activity_type_id;
                }

                if (!$activity->organized_by_user_id && $group) {
                    $activity->organized_by_user_id = $group->owner_id;
                }

                if ($activity->organized_by_user_id && !$activity->organized_by_character_id) {
                    $activity->organized_by_character_id = $this->resolveOrganizerCharacterId($activity->organized_by_user_id);
                }

                if (!$activity->target_prog_point_key && $version) {
                    $activity->target_prog_point_key = $version->prog_points[0]['key'] ?? null;
                }

                if ($activity->is_public) {
                    $activity->secret_key = null;
                } elseif (!$activity->secret_key) {
                    $activity->secret_key = Activity::generateSecretKey();
                }

                if ($activity->status === Activity::STATUS_COMPLETE) {
                    $activity->is_completed = true;
                    $activity->completed_at ??= $activity->starts_at?->copy()->addHours($activity->duration_hours ?? 2);
                }
            })
            ->afterCreating(function (Activity $activity): void {
                $activity->loadMissing('group', 'activityTypeVersion.activityType');

                $this->ensureOrganizerMembership($activity);
                $this->ensurePublishedVersionIsLinked($activity);
                $this->materializeSlots($activity);
                $this->materializeProgressMilestones($activity);
            });
    }

    public function private(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_public' => false,
        ]);
    }

    public function withoutApplications(): static
    {
        return $this->state(fn (array $attributes) => [
            'needs_application' => false,
        ]);
    }

    public function complete(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Activity::STATUS_COMPLETE,
            'is_completed' => true,
            'completed_at' => now(),
        ]);
    }

    public function withRandomAssignments(int $minimumAssignments = 0, ?int $maximumAssignments = null): static
    {
        return $this->afterCreating(function (Activity $activity) use ($minimumAssignments, $maximumAssignments): void {
            $this->fillRandomSlots($activity, $minimumAssignments, $maximumAssignments);
        });
    }

    private function resolveOrganizerCharacterId(int $userId): int
    {
        $character = Character::query()
            ->where('user_id', $userId)
            ->whereNotNull('verified_at')
            ->orderByDesc('is_primary')
            ->first();

        if (!$character) {
            $character = Character::factory()
                ->primary()
                ->create(['user_id' => $userId]);
        }

        return $character->id;
    }

    private function ensureOrganizerMembership(Activity $activity): void
    {
        if (!$activity->organized_by_user_id) {
            return;
        }

        $activity->group->memberships()->firstOrCreate(
            ['user_id' => $activity->organized_by_user_id],
            [
                'role' => $activity->organized_by_user_id === $activity->group->owner_id
                    ? GroupMembership::ROLE_OWNER
                    : GroupMembership::ROLE_MEMBER,
                'joined_at' => $activity->created_at ?? now(),
            ]
        );
    }

    private function ensurePublishedVersionIsLinked(Activity $activity): void
    {
        $type = $activity->activityTypeVersion?->activityType;

        if (!$type instanceof ActivityType) {
            return;
        }

        if ($type->current_published_version_id === null) {
            $type->updateQuietly([
                'current_published_version_id' => $activity->activity_type_version_id,
            ]);
        }
    }

    private function materializeSlots(Activity $activity): void
    {
        if ($activity->slots()->exists()) {
            return;
        }

        $version = $activity->activityTypeVersion;
        $groups = $version?->layout_schema['groups'] ?? [];
        $fields = $version?->slot_schema ?? [];
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

                foreach ($fields as $fieldDefinition) {
                    $slot->fieldValues()->create([
                        'field_key' => (string) ($fieldDefinition['key'] ?? ''),
                        'field_label' => is_array($fieldDefinition['label'] ?? null)
                            ? $fieldDefinition['label']
                            : ['en' => (string) ($fieldDefinition['key'] ?? '')],
                        'field_type' => (string) ($fieldDefinition['type'] ?? 'text'),
                        'source' => $fieldDefinition['source'] ?? null,
                        'value' => null,
                    ]);
                }

                $sortOrder++;
            }
        }
    }

    private function materializeProgressMilestones(Activity $activity): void
    {
        if ($activity->progressMilestones()->exists()) {
            return;
        }

        $milestones = $activity->activityTypeVersion?->progress_schema['milestones'] ?? [];

        foreach ($milestones as $index => $milestoneDefinition) {
            $activity->progressMilestones()->create([
                'milestone_key' => (string) ($milestoneDefinition['key'] ?? ('milestone-'.($index + 1))),
                'milestone_label' => is_array($milestoneDefinition['label'] ?? null)
                    ? $milestoneDefinition['label']
                    : ['en' => (string) ($milestoneDefinition['key'] ?? 'Milestone')],
                'sort_order' => (int) ($milestoneDefinition['order'] ?? $index + 1),
                'kills' => $activity->status === Activity::STATUS_COMPLETE && $index === count($milestones) - 1 ? 1 : 0,
                'best_progress_percent' => $activity->status === Activity::STATUS_COMPLETE && $index === count($milestones) - 1 ? 100 : null,
                'source' => null,
                'notes' => null,
            ]);
        }
    }

    private function fillRandomSlots(Activity $activity, int $minimumAssignments = 0, ?int $maximumAssignments = null): void
    {
        $activity->loadMissing([
            'group.memberships.user.primaryCharacter',
            'slots.fieldValues',
            'activityTypeVersion',
        ]);

        $slots = $activity->slots->values();

        if ($slots->isEmpty()) {
            return;
        }

        $characters = $activity->group->memberships
            ->map(fn (GroupMembership $membership) => $membership->user?->primaryCharacter)
            ->filter(fn (?Character $character) => $character instanceof Character)
            ->unique('id')
            ->shuffle()
            ->values();

        if ($characters->isEmpty()) {
            return;
        }

        $slotCount = $slots->count();
        $maxAssignments = min($maximumAssignments ?? $slotCount, $slotCount, $characters->count());
        $minAssignments = min(max(0, $minimumAssignments), $maxAssignments);
        $assignmentCount = fake()->numberBetween($minAssignments, $maxAssignments);

        if ($assignmentCount === 0) {
            return;
        }

        $slotDefinitions = collect($activity->activityTypeVersion?->slot_schema ?? [])
            ->keyBy(fn (array $definition) => (string) ($definition['key'] ?? ''));

        $selectedSlots = $slots->shuffle()->take($assignmentCount)->values();
        $selectedCharacters = $characters->take($assignmentCount)->values();

        foreach ($selectedSlots as $index => $slot) {
            /** @var Character $character */
            $character = $selectedCharacters[$index];

            $this->ensureCharacterLoadout($character);

            $slot->update([
                'assigned_character_id' => $character->id,
                'assigned_by_user_id' => $activity->organized_by_user_id,
            ]);

            foreach ($slot->fieldValues as $fieldValue) {
                $definition = $slotDefinitions->get($fieldValue->field_key, []);
                $value = $this->resolveSlotFieldValue($slot, $fieldValue, $character, is_array($definition) ? $definition : []);

                if ($value !== null) {
                    $fieldValue->update([
                        'value' => $value,
                    ]);
                }
            }
        }
    }

    private function ensureCharacterLoadout(Character $character): void
    {
        $character->loadMissing('classes', 'preferredClasses', 'phantomJobs', 'preferredPhantomJobs');

        if ($character->classes->isEmpty()) {
            $classes = CharacterClass::query()
                ->inRandomOrder()
                ->limit(fake()->numberBetween(1, 3))
                ->get();

            foreach ($classes as $index => $class) {
                $character->classes()->attach($class->id, [
                    'level' => fake()->numberBetween(90, 100),
                    'is_preferred' => $index === 0,
                ]);
            }
        }

        if ($character->phantomJobs->isEmpty()) {
            $phantomJobs = PhantomJob::query()
                ->inRandomOrder()
                ->limit(fake()->numberBetween(1, 2))
                ->get();

            foreach ($phantomJobs as $index => $phantomJob) {
                $character->phantomJobs()->attach($phantomJob->id, [
                    'current_level' => fake()->numberBetween(1, $phantomJob->max_level),
                    'is_preferred' => $index === 0,
                ]);
            }
        }

        $character->load('classes', 'preferredClasses', 'phantomJobs', 'preferredPhantomJobs');
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>|null
     */
    private function resolveSlotFieldValue(
        \App\Models\ActivitySlot $slot,
        ActivitySlotFieldValue $fieldValue,
        Character $character,
        array $definition
    ): ?array {
        if ($fieldValue->source === 'character_classes') {
            $class = $this->pickAssignedClass($character);

            if (!$class) {
                return null;
            }

            return [
                'id' => $class->id,
                'name' => $class->name,
                'shorthand' => $class->shorthand,
                'role' => $class->role,
            ];
        }

        if ($fieldValue->source === 'phantom_jobs') {
            $phantomJob = $this->pickAssignedPhantomJob($character);

            if (!$phantomJob) {
                return null;
            }

            return [
                'id' => $phantomJob->id,
                'name' => $phantomJob->name,
                'max_level' => $phantomJob->max_level,
            ];
        }

        if ($fieldValue->source === 'static_options') {
            $option = $this->resolveStaticOptionForSlot($slot, $fieldValue->field_key, $definition);

            if (!$option) {
                return null;
            }

            return [
                'key' => $option['value'] ?? $option['key'] ?? null,
                'label' => $option['label'] ?? null,
            ];
        }

        return null;
    }

    private function pickAssignedClass(Character $character): ?CharacterClass
    {
        $character->loadMissing('classes', 'preferredClasses');

        if ($character->classes->isEmpty()) {
            return null;
        }

        $preferredClass = $character->preferredClasses->first();

        if ($preferredClass && fake()->boolean(45)) {
            return $preferredClass;
        }

        /** @var CharacterClass $class */
        $class = $character->classes->random();

        return $class;
    }

    private function pickAssignedPhantomJob(Character $character): ?PhantomJob
    {
        $character->loadMissing('phantomJobs', 'preferredPhantomJobs');

        if ($character->phantomJobs->isEmpty()) {
            return null;
        }

        $preferredPhantomJob = $character->preferredPhantomJobs->first();

        if ($preferredPhantomJob && fake()->boolean(45)) {
            return $preferredPhantomJob;
        }

        /** @var PhantomJob $phantomJob */
        $phantomJob = $character->phantomJobs->random();

        return $phantomJob;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>|null
     */
    private function resolveStaticOptionForSlot(\App\Models\ActivitySlot $slot, string $fieldKey, array $definition): ?array
    {
        $options = collect($definition['options'] ?? []);

        if ($options->isEmpty()) {
            return null;
        }

        if ($fieldKey === 'raid_position') {
            $positionKeys = ['mt', 'ot', 'h1', 'h2', 'm1', 'm2', 'r1', 'r2'];
            $targetKey = $positionKeys[max(0, $slot->position_in_group - 1)] ?? null;

            if ($targetKey) {
                return $options->first(
                    fn (array $option) => ($option['value'] ?? $option['key'] ?? null) === $targetKey
                );
            }
        }

        return $options->random();
    }
}
