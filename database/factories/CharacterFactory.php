<?php

namespace Database\Factories;

use App\Models\Character;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Arr;

/**
 * @extends Factory<Character>
 */
class CharacterFactory extends Factory
{
    protected $model = Character::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $worldData = Arr::random($this->worldOptions());

        return [
            'user_id' => User::factory(),
            'is_primary' => false,
            'name' => fake()->firstName().' '.fake()->lastName(),
            'world' => $worldData['world'],
            'datacenter' => $worldData['datacenter'],
            'lodestone_id' => (string) fake()->unique()->numberBetween(10_000_000, 99_999_999),
            'avatar_url' => fake()->imageUrl(256, 256, 'people'),
            'token' => null,
            'expires_at' => null,
            'verified_at' => now(),
            'add_method' => 'manual',
        ];
    }

    public function primary(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_primary' => true,
        ])->afterCreating(function (Character $character): void {
            Character::query()
                ->where('user_id', $character->user_id)
                ->whereKeyNot($character->id)
                ->update(['is_primary' => false]);
        });
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'verified_at' => null,
            'token' => fake()->regexify('[A-Z0-9]{8}'),
            'expires_at' => now()->addDay(),
        ]);
    }

    /**
     * @return array<int, array{world: string, datacenter: string}>
     */
    private function worldOptions(): array
    {
        return [
            ['world' => 'Twintania', 'datacenter' => 'Light'],
            ['world' => 'Lich', 'datacenter' => 'Light'],
            ['world' => 'Gilgamesh', 'datacenter' => 'Aether'],
            ['world' => 'Cactuar', 'datacenter' => 'Aether'],
            ['world' => 'Mateus', 'datacenter' => 'Crystal'],
            ['world' => 'Faerie', 'datacenter' => 'Aether'],
            ['world' => 'Moogle', 'datacenter' => 'Chaos'],
            ['world' => 'Ragnarok', 'datacenter' => 'Chaos'],
        ];
    }
}
