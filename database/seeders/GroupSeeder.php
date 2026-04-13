<?php

namespace Database\Seeders;

use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\ScheduledRun;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GroupSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        DB::table('scheduled_runs')->delete();
        DB::table('group_invites')->delete();
        DB::table('group_memberships')->delete();
        DB::table('groups')->delete();

        $groups = collect($this->groupBlueprints());

        $groups->each(function (array $blueprint, int $index) {
            $createdAt = now()->subDays(40 - $index);
            $updatedAt = $createdAt->copy()->addHours(($index % 6) + 1);

            $group = Group::create([
                'owner_id' => $blueprint['owner_id'],
                'name' => $blueprint['name'],
                'description' => $blueprint['description'],
                'profile_picture_url' => null,
                'discord_invite_url' => $blueprint['discord_invite_url'],
                'datacenter' => $blueprint['datacenter'],
                'is_public' => $blueprint['is_public'],
                'is_visible' => $blueprint['is_visible'],
                'slug' => $blueprint['slug'],
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ]);

            $group->memberships()->create([
                'user_id' => $blueprint['owner_id'],
                'role' => GroupMembership::ROLE_OWNER,
                'joined_at' => $createdAt,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            if ($blueprint['user_one_role']) {
                $group->memberships()->create([
                    'user_id' => 1,
                    'role' => $blueprint['user_one_role'],
                    'joined_at' => $createdAt->copy()->addDay(),
                    'created_at' => $createdAt->copy()->addDay(),
                    'updated_at' => $createdAt->copy()->addDay(),
                ]);
            }

            if ($blueprint['is_public']) {
                $group->ensureSystemInvite();
            }

            $this->seedRunsForGroup($group, $index, $blueprint['owner_id'], $updatedAt);
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function groupBlueprints(): array
    {
        return [
            $this->groupBlueprint('Forked Tower Enjoyers', 'A chill but focused static for Forked Tower clears and progression nights.', 'ftel', 1, 'Light', true, true, 'https://discord.gg/ftel'),
            $this->groupBlueprint('Lich Nightwatch', 'Late evening raid planning and progression for players based on Lich and beyond.', 'lnight', 1, 'Light', false, true, null),

            $this->groupBlueprint('Aether Vanguard', 'Structured raid planning and clean communication for progression-minded players.', 'aetherv', 2, 'Aether', true, true, 'https://discord.gg/aetherv', GroupMembership::ROLE_MODERATOR),
            $this->groupBlueprint('Chaos Forgehall', 'A small but organized EU group focused on consistent clears and static reliability.', 'forgehll', 3, 'Chaos', false, true, null, GroupMembership::ROLE_MODERATOR),
            $this->groupBlueprint('Primal Lantern', 'Flexible scheduling and friendly run leadership for savage and criterion groups.', 'primalan', 2, 'Primal', true, false, 'https://discord.gg/primalan', GroupMembership::ROLE_MODERATOR),
            $this->groupBlueprint('Meteor Archive', 'A planning-heavy group for spreadsheets, assignments, and clean execution.', 'metearch', 3, 'Meteor', false, true, null, GroupMembership::ROLE_MODERATOR),

            $this->groupBlueprint('Crystal Echo', 'Relaxed group culture with steady clears and mount farm organization.', 'crystlec', 2, 'Crystal', true, true, 'https://discord.gg/crystlec', GroupMembership::ROLE_MEMBER),
            $this->groupBlueprint('Mana Pioneers', 'JP-centered schedule coordination for players who like well-prepared runs.', 'manapion', 3, 'Mana', false, true, null, GroupMembership::ROLE_MEMBER),
            $this->groupBlueprint('Dynamis Drift', 'Casual planning, alt runs, and open community clears.', 'dynamisd', 2, 'Dynamis', true, false, 'https://discord.gg/dynamisd', GroupMembership::ROLE_MEMBER),

            $this->groupBlueprint('Light Rampart', 'Hardcore raiding static focused on Ultimate and Savage content.', 'lightram', 2, 'Light', false, true, null),
            $this->groupBlueprint('Endwalker Raiders', 'Casual-midcore group for weekly clears and mount farms.', 'endraids', 3, 'Aether', true, true, 'https://discord.gg/endraids'),
            $this->groupBlueprint('Crystal Cartel', 'Tightly coordinated scheduling for players who want polished group operations.', 'crystalc', 2, 'Crystal', false, true, null),
            $this->groupBlueprint('Savage Sunday', 'Weekend progression group with a focus on preparation and consistency.', 'savsundy', 3, 'Primal', true, true, 'https://discord.gg/savsundy'),
            $this->groupBlueprint('Materia Crosswinds', 'Oceanic scheduling hub for progression and reclears.', 'matcross', 2, 'Materia', true, true, 'https://discord.gg/matcross'),
            $this->groupBlueprint('Gaia Relay', 'JP relay-style organization for people juggling multiple raid groups.', 'gaiarely', 3, 'Gaia', false, true, null),
            $this->groupBlueprint('Aether Bloom', 'Friendly atmosphere with strong organizers and active learning runs.', 'aetherbl', 2, 'Aether', true, true, 'https://discord.gg/aetherbl'),
            $this->groupBlueprint('Chaos Lantern', 'EU scheduling and recruitment hub for mixed-skill groups.', 'chaoslan', 3, 'Chaos', false, false, null),
            $this->groupBlueprint('Primal Garrison', 'Consistent moderation, roster stability, and no-drama clears.', 'primgarr', 2, 'Primal', true, true, 'https://discord.gg/primgarr'),
            $this->groupBlueprint('Elemental Current', 'Steady run organization for Elemental players who want reliable uptime.', 'elemcurr', 3, 'Elemental', true, true, 'https://discord.gg/elemcurr'),
            $this->groupBlueprint('Meteor Signal', 'A quiet but efficient planning space for scheduled clears.', 'metersig', 2, 'Meteor', false, true, null),
            $this->groupBlueprint('Lunar Ledger', 'Spreadsheet enthusiasts welcome. Expect assignments and planning.', 'lunarled', 3, 'Light', false, true, null),
            $this->groupBlueprint('Static Harbor', 'Open static recruitment and fill-in scheduling for flexible players.', 'statharb', 2, 'Crystal', true, true, 'https://discord.gg/statharb'),
            $this->groupBlueprint('Clear Count Club', 'Focused on reclears, clean logs, and clean communication.', 'clearcnt', 3, 'Aether', true, false, 'https://discord.gg/clearcnt'),
            $this->groupBlueprint('Midnight Queue', 'Night owls coordinating late-night runs and spontaneous fills.', 'midqueue', 2, 'Dynamis', true, true, 'https://discord.gg/midqueue'),
            $this->groupBlueprint('Sunrise Circle', 'Morning run planners and cozy weekly content scheduling.', 'sunrisec', 3, 'Mana', false, true, null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function groupBlueprint(
        string $name,
        string $description,
        string $slug,
        int $ownerId,
        string $datacenter,
        bool $isPublic,
        bool $isVisible,
        ?string $discordInviteUrl,
        ?string $userOneRole = null
    ): array {
        return [
            'name' => $name,
            'description' => $description,
            'slug' => $slug,
            'owner_id' => $ownerId,
            'datacenter' => $datacenter,
            'is_public' => $isPublic,
            'is_visible' => $isVisible,
            'discord_invite_url' => $discordInviteUrl,
            'user_one_role' => $userOneRole,
        ];
    }

    private function seedRunsForGroup(Group $group, int $index, int $ownerId, $updatedAt): void
    {
        $runCount = ($index % 4) + 1;
        $statuses = [
            ScheduledRun::STATUS_SCHEDULED,
            ScheduledRun::STATUS_UPCOMING,
            ScheduledRun::STATUS_COMPLETE,
            ScheduledRun::STATUS_CANCELLED,
            ScheduledRun::STATUS_PLANNED,
        ];

        foreach (range(1, $runCount) as $runIndex) {
            $status = $statuses[($index + $runIndex) % count($statuses)];
            $runTimestamp = $updatedAt->copy()->addHours($runIndex);

            $group->scheduledRuns()->create([
                'organized_by_user_id' => $ownerId,
                'status' => $status,
                'created_at' => $runTimestamp,
                'updated_at' => $runTimestamp,
            ]);
        }

        $group->updateQuietly([
            'updated_at' => $updatedAt->copy()->addHours($runCount + 1),
        ]);
    }
}
