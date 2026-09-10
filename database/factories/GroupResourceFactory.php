<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceCollection;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GroupResource> */
class GroupResourceFactory extends Factory
{
    protected $model = GroupResource::class;

    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'collection_id' => fn (array $attributes) => GroupResourceCollection::create(['group_id' => $attributes['group_id'], 'name' => 'Guides', 'slug' => fake()->unique()->slug()])->id,
            'slug' => fake()->unique()->slug(),
            'access_level' => 'everyone',
            'management_access_level' => 'everyone',
            'status' => 'draft',
        ];
    }
}
