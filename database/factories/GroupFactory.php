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
            'banner_image_url' => null,
            'discord_invite_url' => fake()->boolean(60) ? 'https://discord.gg/'.fake()->lexify('????????') : null,
            'datacenter' => fake()->randomElement(['Light', 'Chaos', 'Aether', 'Crystal', 'Primal', 'Dynamis']),
            'is_visible' => true,
            'slug' => strtolower(fake()->unique()->regexify('[a-z0-9]{8}')),
            'group_type' => Group::TYPE_COMMUNITY,
            'join_mode' => Group::JOIN_MODE_INVITE_ONLY,
            'membership_application_schema' => null,
            'primary_focuses' => [],
            'experience_expectation' => null,
            'voice_expectation' => null,
            'preferred_languages' => [],
            'tags' => [],
            'active_timezone' => null,
            'active_days' => [],
            'active_start_time' => null,
            'active_end_time' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Group $group): void {
            if (! in_array($group->join_mode, Group::joinModesForType($group->group_type), true)) {
                $group->join_mode = Group::JOIN_MODE_INVITE_ONLY;
                $group->save();
            }

            $group->memberships()->firstOrCreate(
                ['user_id' => $group->owner_id],
                [
                    'role' => GroupMembership::ROLE_OWNER,
                    'joined_at' => $group->created_at,
                ]
            );

            if ($group->hasPermanentInvite()) {
                $group->ensureSystemInvite();
            }

        });
    }

    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_visible' => true,
            'join_mode' => Group::JOIN_MODE_OPEN,
        ]);
    }

    public function inviteOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'join_mode' => Group::JOIN_MODE_INVITE_ONLY,
        ]);
    }

    /** Create a legacy record only for migration and retired-route coverage. */
    public function applicationBased(): static
    {
        return $this->inviteOnly()->afterCreating(function (Group $group): void {
            $group->update(['join_mode' => Group::JOIN_MODE_APPLICATION]);
        });
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

        });
    }
}
