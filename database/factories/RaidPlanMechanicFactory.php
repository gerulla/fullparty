<?php

namespace Database\Factories;

use App\Models\RaidPlan;
use App\Models\RaidPlanMechanic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RaidPlanMechanic>
 */
class RaidPlanMechanicFactory extends Factory
{
    protected $model = RaidPlanMechanic::class;

    public function definition(): array
    {
        return [
            'raid_plan_id' => RaidPlan::factory(),
            'parent_id' => null,
            'name' => fake()->words(3, true),
            'type' => RaidPlanMechanic::TYPE_FIXED,
            'sort_order' => 0,
            'duration_ms' => 10_000,
            'selection_weight' => 1,
            'is_enabled' => true,
            'timeline' => [],
            'timeline_schema_version' => RaidPlanMechanic::CURRENT_TIMELINE_SCHEMA_VERSION,
        ];
    }

    public function randomSet(): static
    {
        return $this->state(fn (): array => [
            'type' => RaidPlanMechanic::TYPE_RANDOM_SET,
            'duration_ms' => 0,
            'timeline' => [],
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => [
            'is_enabled' => false,
        ]);
    }
}
