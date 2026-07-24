<?php

namespace Database\Factories;

use App\Models\RaidPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RaidPlan>
 */
class RaidPlanFactory extends Factory
{
    protected $model = RaidPlan::class;

    public function definition(): array
    {
        return [
            'author_id' => User::factory(),
            'activity_type_id' => null,
            'name' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'visibility' => RaidPlan::VISIBILITY_UNLISTED,
        ];
    }
}
