<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\GroupMembership;
use App\Models\PhantomJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityApplication>
 */
class ActivityApplicationFactory extends Factory
{
    protected $model = ActivityApplication::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'activity_id' => Activity::factory(),
            'user_id' => User::factory(),
            'selected_character_id' => null,
            'applicant_lodestone_id' => null,
            'applicant_character_name' => null,
            'applicant_world' => null,
            'applicant_datacenter' => null,
            'applicant_avatar_url' => null,
            'guest_access_token' => null,
            'status' => ActivityApplication::STATUS_PENDING,
            'notes' => fake()->boolean(50) ? fake()->sentence() : null,
            'reviewed_by_user_id' => null,
            'submitted_at' => now(),
            'reviewed_at' => null,
            'review_reason' => null,
        ];
    }

    public function configure(): static
    {
        return $this
            ->afterMaking(function (ActivityApplication $application): void {
                if ($application->user_id && !$application->selected_character_id) {
                    $application->selected_character_id = $this->resolveSelectedCharacterId($application->user_id);
                }

                if ($application->selected_character_id && !$application->applicant_lodestone_id) {
                    $selectedCharacter = Character::query()->find($application->selected_character_id);

                    if ($selectedCharacter) {
                        $application->applicant_lodestone_id = $selectedCharacter->lodestone_id;
                        $application->applicant_character_name = $selectedCharacter->name;
                        $application->applicant_world = $selectedCharacter->world;
                        $application->applicant_datacenter = $selectedCharacter->datacenter;
                        $application->applicant_avatar_url = $selectedCharacter->avatar_url;
                    }
                }

                if ($application->status === ActivityApplication::STATUS_PENDING) {
                    $application->reviewed_by_user_id = null;
                    $application->reviewed_at = null;
                    $application->review_reason = null;
                }

                if ($application->user_id === null && !$application->guest_access_token) {
                    $application->guest_access_token = ActivityApplication::generateGuestAccessToken();
                }

                if ($application->user_id === null && $application->applicant_lodestone_id && !$application->selected_character_id) {
                    $guestCharacter = Character::query()
                        ->where('lodestone_id', $application->applicant_lodestone_id)
                        ->first();

                    if (!$guestCharacter || !$guestCharacter->verified_at) {
                        $guestCharacter ??= Character::factory()->provisional()->create([
                            'name' => $application->applicant_character_name,
                            'world' => $application->applicant_world,
                            'datacenter' => $application->applicant_datacenter,
                            'lodestone_id' => $application->applicant_lodestone_id,
                            'avatar_url' => $application->applicant_avatar_url,
                        ]);

                        $application->selected_character_id = $guestCharacter->id;
                    }
                }
            })
            ->afterCreating(function (ActivityApplication $application): void {
                $application->loadMissing('activity.group', 'activity.activityTypeVersion', 'selectedCharacter.classes', 'selectedCharacter.phantomJobs');

                if ($application->user_id) {
                    $application->activity->group->memberships()->firstOrCreate(
                        ['user_id' => $application->user_id],
                        [
                            'role' => GroupMembership::ROLE_MEMBER,
                            'joined_at' => $application->submitted_at ?? now(),
                        ]
                    );
                }

                $this->ensureCharacterLoadout($application->selectedCharacter);
                $this->seedApplicationAnswers($application);
            });
    }

    public function approved(?User $reviewer = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ActivityApplication::STATUS_APPROVED,
            'reviewed_by_user_id' => $reviewer?->id,
            'reviewed_at' => now(),
        ]);
    }

    public function declined(?User $reviewer = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ActivityApplication::STATUS_DECLINED,
            'reviewed_by_user_id' => $reviewer?->id,
            'reviewed_at' => now(),
            'review_reason' => fake()->boolean(70) ? fake()->sentence() : null,
        ]);
    }

    public function cancelled(?User $reviewer = null, ?string $reason = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ActivityApplication::STATUS_CANCELLED,
            'reviewed_by_user_id' => $reviewer?->id,
            'reviewed_at' => now(),
            'review_reason' => $reason ?? 'Run cancelled.',
        ]);
    }

    public function guest(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
            'selected_character_id' => null,
            'applicant_lodestone_id' => (string) fake()->unique()->numberBetween(10_000_000, 99_999_999),
            'applicant_character_name' => fake()->firstName().' '.fake()->lastName(),
            'applicant_world' => fake()->randomElement(['Twintania', 'Lich', 'Moogle', 'Gilgamesh']),
            'applicant_datacenter' => fake()->randomElement(['Light', 'Chaos', 'Aether']),
            'applicant_avatar_url' => fake()->imageUrl(256, 256, 'people'),
            'guest_access_token' => ActivityApplication::generateGuestAccessToken(),
        ]);
    }

    private function resolveSelectedCharacterId(int $userId): int
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

    private function ensureCharacterLoadout(?Character $character): void
    {
        if (!$character) {
            return;
        }

        $character->loadMissing('classes', 'phantomJobs');

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

        $character->load('classes', 'phantomJobs');
    }

    private function seedApplicationAnswers(ActivityApplication $application): void
    {
        if ($application->answers()->exists()) {
            return;
        }

        $schema = collect($application->activity->activityTypeVersion?->application_schema ?? [])
            ->filter(fn ($question) => is_array($question) && filled($question['key'] ?? null));

        if ($schema->isEmpty()) {
            return;
        }

        $selectedCharacter = $application->selectedCharacter;

        $schema->each(function (array $question) use ($application, $selectedCharacter): void {
            $value = $this->generateAnswerValue($question, $selectedCharacter);

            if ($value === null) {
                return;
            }

            $application->answers()->create([
                'question_key' => (string) $question['key'],
                'question_label' => is_array($question['label'] ?? null) ? $question['label'] : ['en' => (string) $question['key']],
                'question_type' => (string) ($question['type'] ?? 'text'),
                'source' => $question['source'] ?? null,
                'value' => $value,
            ]);
        });
    }

    private function generateAnswerValue(array $question, ?Character $selectedCharacter): mixed
    {
        $source = $question['source'] ?? null;
        $type = (string) ($question['type'] ?? 'text');
        $required = (bool) ($question['required'] ?? false);

        if (!$required && fake()->boolean(15)) {
            return null;
        }

        if ($source === 'character_classes') {
            $classIds = $selectedCharacter?->classes?->pluck('id')->map(fn ($id) => (string) $id)->values() ?? collect();

            if ($classIds->isEmpty()) {
                return $type === 'multi_select' ? [] : null;
            }

            if ($type === 'multi_select') {
                return $classIds->shuffle()->take(fake()->numberBetween(1, min(3, $classIds->count())))->values()->all();
            }

            return $classIds->random();
        }

        if ($source === 'phantom_jobs') {
            $phantomJobIds = $selectedCharacter?->phantomJobs?->pluck('id')->map(fn ($id) => (string) $id)->values() ?? collect();

            if ($phantomJobIds->isEmpty()) {
                return $type === 'multi_select' ? [] : null;
            }

            if ($type === 'multi_select') {
                return $phantomJobIds->shuffle()->take(fake()->numberBetween(1, min(2, $phantomJobIds->count())))->values()->all();
            }

            return $phantomJobIds->random();
        }

        if ($source === 'static_options') {
            $options = collect($question['options'] ?? [])
                ->map(fn ($option) => (string) ($option['value'] ?? $option['key'] ?? ''))
                ->filter();

            if ($options->isEmpty()) {
                return $type === 'multi_select' ? [] : null;
            }

            if ($type === 'multi_select') {
                return $options->shuffle()->take(fake()->numberBetween(1, min(3, $options->count())))->values()->all();
            }

            return $options->random();
        }

        return match ($type) {
            'boolean' => fake()->boolean(),
            'textarea' => fake()->sentence(fake()->numberBetween(6, 16)),
            'url' => fake()->url(),
            'multi_select' => [],
            default => fake()->sentence(fake()->numberBetween(2, 8)),
        };
    }
}
