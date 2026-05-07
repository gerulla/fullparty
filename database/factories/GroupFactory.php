<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    protected $model = Group::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'profile_picture_url' => null,
            'discord_invite_url' => fake()->boolean(60) ? fake()->url() : null,
            'datacenter' => fake()->randomElement(['Light', 'Chaos', 'Aether', 'Crystal', 'Primal', 'Dynamis']),
            'is_public' => fake()->boolean(70),
            'is_visible' => true,
            'slug' => strtolower(fake()->unique()->regexify('[a-z0-9]{8}')),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Group $group): void {
            $group->memberships()->firstOrCreate(
                ['user_id' => $group->owner_id],
                [
                    'role' => GroupMembership::ROLE_OWNER,
                    'joined_at' => $group->created_at,
                ]
            );

            if ($group->is_public) {
                $group->ensureSystemInvite();
            }

            $group->followers()->syncWithoutDetaching([
                $group->owner_id => ['notifications_enabled' => true],
            ]);
        });
    }

    public function public(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_public' => true,
            'is_visible' => true,
        ]);
    }

    public function private(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_public' => false,
        ]);
    }

    public function hidden(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_visible' => false,
        ]);
    }

    public function withMember(?User $user = null, string $role = GroupMembership::ROLE_MEMBER): static
    {
        return $this->afterCreating(function (Group $group) use ($user, $role): void {
            $member = $user ?? User::factory()->create();

            $group->memberships()->firstOrCreate(
                ['user_id' => $member->id],
                [
                    'role' => $role,
                    'joined_at' => now(),
                ]
            );

            $group->followers()->syncWithoutDetaching([
                $member->id => ['notifications_enabled' => true],
            ]);
        });
    }
}
