<?php

namespace Database\Seeders;

use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\ScheduledRun;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class GroupSeeder extends Seeder
{
    private const TARGET_GROUP_COUNT = 20;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $users = User::query()
            ->where('is_admin', false)
            ->with('primaryCharacter')
            ->get();

        if ($users->count() < 200) {
            throw new \RuntimeException('Expected a larger non-admin user pool before seeding groups.');
        }

        $ownerPool = $users->shuffle()->values();
        $blueprints = collect($this->groupBlueprints())->take(self::TARGET_GROUP_COUNT - 1);

        $this->createSpecificForkedTowerGroup($users);

        $blueprints->each(function (array $blueprint, int $index) use ($ownerPool, $users) {
            /** @var User $owner */
            $owner = $ownerPool[$index];

            $group = Group::factory()->create([
                'owner_id' => $owner->id,
                'name' => $blueprint['name'],
                'description' => $blueprint['description'],
                'discord_invite_url' => $blueprint['discord_invite_url'],
                'datacenter' => $blueprint['datacenter'],
                'is_public' => $blueprint['is_public'],
                'is_visible' => $blueprint['is_visible'],
                'slug' => $blueprint['slug'],
            ]);

            $this->seedMemberships($group, $users, $owner);
            $this->seedLegacyRunsForGroup($group, $owner->id);
        });
    }

    private function createSpecificForkedTowerGroup(Collection $users): void
    {
        $createdAt = now()->subDays(40);
        $updatedAt = $createdAt->copy()->addHours(2);

        $group = Group::create([
            'owner_id' => 1,
            'name' => 'Forked Tower Enjoyers',
            'description' => 'A chill but focused static for Forked Tower clears and progression nights.',
            'profile_picture_url' => null,
            'discord_invite_url' => 'https://discord.gg/ftel',
            'datacenter' => 'Light',
            'is_public' => true,
            'is_visible' => true,
            'slug' => 'ftel',
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ]);

        $group->memberships()->create([
            'user_id' => 1,
            'role' => GroupMembership::ROLE_OWNER,
            'joined_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $group->ensureSystemInvite();

        $this->seedMemberships($group, $users, null, 120);
        $this->seedLegacyRunsForGroup($group, 1);
    }

    private function seedMemberships(Group $group, Collection $users, ?User $owner, ?int $forcedCount = null): void
    {
        $targetCount = $forcedCount ?? fake()->numberBetween(50, 200);
        $availableUsers = $users
            ->reject(fn (User $user) => $owner && $user->id === $owner->id)
            ->shuffle()
            ->values();

        $selectedUsers = $availableUsers->take(max(0, $targetCount - 1));
        $moderatorCount = min(fake()->numberBetween(0, 8), $selectedUsers->count());
        $moderatorIds = $selectedUsers->take($moderatorCount)->pluck('id')->all();

        $selectedUsers->each(function (User $user, int $index) use ($group, $moderatorIds): void {
            $joinedAt = $group->created_at->copy()->addHours($index + 1);

            $group->memberships()->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'role' => in_array($user->id, $moderatorIds, true)
                        ? GroupMembership::ROLE_MODERATOR
                        : GroupMembership::ROLE_MEMBER,
                    'joined_at' => $joinedAt,
                    'created_at' => $joinedAt,
                    'updated_at' => $joinedAt,
                ]
            );
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function groupBlueprints(): array
    {
        return [
            $this->groupBlueprint('Lich Nightwatch', 'Late evening raid planning and progression for players based on Lich and beyond.', 'lnight', 'Light', false, true, null),
            $this->groupBlueprint('Aether Vanguard', 'Structured raid planning and clean communication for progression-minded players.', 'aetherv', 'Aether', true, true, 'https://discord.gg/aetherv'),
            $this->groupBlueprint('Chaos Forgehall', 'A small but organized EU group focused on consistent clears and static reliability.', 'forgehll', 'Chaos', false, true, null),
            $this->groupBlueprint('Primal Lantern', 'Flexible scheduling and friendly run leadership for savage and criterion groups.', 'primalan', 'Primal', true, false, 'https://discord.gg/primalan'),
            $this->groupBlueprint('Meteor Archive', 'A planning-heavy group for spreadsheets, assignments, and clean execution.', 'metearch', 'Meteor', false, true, null),
            $this->groupBlueprint('Crystal Echo', 'Relaxed group culture with steady clears and mount farm organization.', 'crystlec', 'Crystal', true, true, 'https://discord.gg/crystlec'),
            $this->groupBlueprint('Mana Pioneers', 'JP-centered schedule coordination for players who like well-prepared runs.', 'manapion', 'Mana', false, true, null),
            $this->groupBlueprint('Dynamis Drift', 'Casual planning, alt runs, and open community clears.', 'dynamisd', 'Dynamis', true, false, 'https://discord.gg/dynamisd'),
            $this->groupBlueprint('Light Rampart', 'Hardcore raiding static focused on Ultimate and Savage content.', 'lightram', 'Light', false, true, null),
            $this->groupBlueprint('Endwalker Raiders', 'Casual-midcore group for weekly clears and mount farms.', 'endraids', 'Aether', true, true, 'https://discord.gg/endraids'),
            $this->groupBlueprint('Crystal Cartel', 'Tightly coordinated scheduling for players who want polished group operations.', 'crystalc', 'Crystal', false, true, null),
            $this->groupBlueprint('Savage Sunday', 'Weekend progression group with a focus on preparation and consistency.', 'savsundy', 'Primal', true, true, 'https://discord.gg/savsundy'),
            $this->groupBlueprint('Materia Crosswinds', 'Oceanic scheduling hub for progression and reclears.', 'matcross', 'Materia', true, true, 'https://discord.gg/matcross'),
            $this->groupBlueprint('Gaia Relay', 'JP relay-style organization for people juggling multiple raid groups.', 'gaiarely', 'Gaia', false, true, null),
            $this->groupBlueprint('Aether Bloom', 'Friendly atmosphere with strong organizers and active learning runs.', 'aetherbl', 'Aether', true, true, 'https://discord.gg/aetherbl'),
            $this->groupBlueprint('Chaos Lantern', 'EU scheduling and recruitment hub for mixed-skill groups.', 'chaoslan', 'Chaos', false, false, null),
            $this->groupBlueprint('Primal Garrison', 'Consistent moderation, roster stability, and no-drama clears.', 'primgarr', 'Primal', true, true, 'https://discord.gg/primgarr'),
            $this->groupBlueprint('Elemental Current', 'Steady run organization for Elemental players who want reliable uptime.', 'elemcurr', 'Elemental', true, true, 'https://discord.gg/elemcurr'),
            $this->groupBlueprint('Meteor Signal', 'A quiet but efficient planning space for scheduled clears.', 'metersig', 'Meteor', false, true, null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function groupBlueprint(
        string $name,
        string $description,
        string $slug,
        string $datacenter,
        bool $isPublic,
        bool $isVisible,
        ?string $discordInviteUrl,
    ): array {
        return [
            'name' => $name,
            'description' => $description,
            'slug' => $slug,
            'datacenter' => $datacenter,
            'is_public' => $isPublic,
            'is_visible' => $isVisible,
            'discord_invite_url' => $discordInviteUrl,
        ];
    }

    private function seedLegacyRunsForGroup(Group $group, int $ownerId): void
    {
        $runCount = fake()->numberBetween(1, 4);
        $statuses = [
            ScheduledRun::STATUS_SCHEDULED,
            ScheduledRun::STATUS_UPCOMING,
            ScheduledRun::STATUS_PLANNED,
        ];

        foreach (range(1, $runCount) as $runIndex) {
            $runTimestamp = $group->updated_at->copy()->addHours($runIndex);

            $group->scheduledRuns()->create([
                'organized_by_user_id' => $ownerId,
                'status' => fake()->randomElement($statuses),
                'created_at' => $runTimestamp,
                'updated_at' => $runTimestamp,
            ]);
        }
    }
}
