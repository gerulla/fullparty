<?php

namespace Database\Factories;

use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ActivityType>
 */
class ActivityTypeFactory extends Factory
{
    protected $model = ActivityType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(10, 99),
            'draft_name' => ['en' => ucwords($name)],
            'draft_description' => ['en' => fake()->sentence()],
            'draft_layout_schema' => [
                'groups' => [
                    [
                        'key' => 'party-a',
                        'label' => ['en' => 'Party A'],
                        'size' => 8,
                    ],
                ],
            ],
            'draft_slot_schema' => [
                [
                    'key' => 'character_class',
                    'label' => ['en' => 'Character Class'],
                    'type' => 'single_select',
                    'source' => 'character_classes',
                ],
                [
                    'key' => 'phantom_job',
                    'label' => ['en' => 'Phantom Job'],
                    'type' => 'single_select',
                    'source' => 'phantom_jobs',
                ],
            ],
            'draft_application_schema' => [
                'questions' => [
                    [
                        'key' => 'experience',
                        'label' => ['en' => 'Experience'],
                        'type' => 'textarea',
                        'required' => false,
                    ],
                ],
            ],
            'draft_progress_schema' => [
                'milestones' => [
                    [
                        'key' => 'clear',
                        'label' => ['en' => 'Clear'],
                        'order' => 1,
                    ],
                ],
            ],
            'draft_prog_points' => [
                [
                    'key' => 'clear',
                    'label' => ['en' => 'Clear'],
                    'order' => 1,
                ],
            ],
            'is_active' => true,
            'created_by_user_id' => User::factory(),
            'current_published_version_id' => null,
        ];
    }

    public function withPublishedVersion(): static
    {
        return $this->afterCreating(function (ActivityType $activityType): void {
            if ($activityType->current_published_version_id) {
                return;
            }

            $version = ActivityTypeVersion::factory()
                ->for($activityType)
                ->create([
                    'version' => 1,
                    'name' => $activityType->draft_name,
                    'description' => $activityType->draft_description,
                    'layout_schema' => $activityType->draft_layout_schema,
                    'slot_schema' => $activityType->draft_slot_schema,
                    'application_schema' => $activityType->draft_application_schema,
                    'progress_schema' => $activityType->draft_progress_schema,
                    'prog_points' => $activityType->draft_prog_points,
                ]);

            $activityType->updateQuietly([
                'current_published_version_id' => $version->id,
            ]);
        });
    }
}
