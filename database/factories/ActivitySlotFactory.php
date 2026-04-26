<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\Character;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivitySlot>
 */
class ActivitySlotFactory extends Factory
{
    protected $model = ActivitySlot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'activity_id' => Activity::factory(),
            'group_key' => 'party-a',
            'group_label' => ['en' => 'Party A'],
            'slot_key' => 'party-a-slot-1',
            'slot_label' => ['en' => 'Party A 1'],
            'position_in_group' => 1,
            'sort_order' => 1,
            'assigned_character_id' => null,
            'assigned_by_user_id' => null,
        ];
    }

    public function assignedTo(Character $character, ?User $assignedBy = null): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned_character_id' => $character->id,
            'assigned_by_user_id' => $assignedBy?->id ?? $character->user_id,
        ]);
    }
}
