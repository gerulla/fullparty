<?php

namespace Database\Seeders;

require_once __DIR__.'/SeederFaker.php';

use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\ScheduledRun;
use App\Models\User;
use App\Services\Groups\GeneratedGroupImageService;
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
            ->with('primaryCharacter')
            ->orderBy('id')
            ->get();

        if ($users->count() < 200) {
            throw new \RuntimeException('Expected a larger seeded user pool before seeding groups.');
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
                'profile_picture_url' => $blueprint['profile_picture_url'],
                'banner_image_url' => $blueprint['banner_image_url'],
                'discord_invite_url' => $blueprint['discord_invite_url'],
                'datacenter' => $blueprint['datacenter'],
                'is_visible' => $blueprint['is_visible'],
                'slug' => $blueprint['slug'],
                'group_type' => $blueprint['group_type'],
                'join_mode' => $blueprint['join_mode'],
                'primary_focuses' => $blueprint['primary_focuses'],
                'experience_expectation' => $blueprint['experience_expectation'],
                'voice_expectation' => $blueprint['voice_expectation'],
                'preferred_languages' => $blueprint['preferred_languages'],
                'tags' => $blueprint['tags'],
                'active_timezone' => $blueprint['active_timezone'],
                'active_days' => $blueprint['active_days'],
                'active_start_time' => $blueprint['active_start_time'],
                'active_end_time' => $blueprint['active_end_time'],
            ]);

            $this->seedMemberships($group, $users, $owner);
            $this->seedLegacyRunsForGroup($group, $owner->id);
        });
    }

    private function createSpecificForkedTowerGroup(Collection $users): void
    {
        /** @var User $owner */
        $owner = $users->firstOrFail();
        $createdAt = now()->subDays(40);
        $updatedAt = $createdAt->copy()->addHours(2);

        $group = Group::create([
            'owner_id' => $owner->id,
            'name' => 'Forked Tower Enjoyers',
            'description' => 'A chill but focused static for Forked Tower clears and progression nights.',
            'profile_picture_url' => $this->seededProfileUrl('ftel', 'Forked Tower Enjoyers', 'Light'),
            'banner_image_url' => $this->seededBannerUrl('ftel', 'Forked Tower Enjoyers', 'Light'),
            'discord_invite_url' => 'https://discord.gg/ftel',
            'datacenter' => 'Light',
            'is_visible' => true,
            'group_type' => Group::TYPE_STATIC,
            'join_mode' => Group::JOIN_MODE_INVITE_ONLY,
            'slug' => 'ftel',
            'primary_focuses' => ['progression', 'clears', 'reclears'],
            'experience_expectation' => 'midcore',
            'voice_expectation' => 'required',
            'preferred_languages' => ['en', 'de', 'fr'],
            'tags' => ['Forked Tower', 'Static', 'Late Night'],
            'active_timezone' => 'Europe/London',
            'active_days' => ['wed', 'sun'],
            'active_start_time' => '19:30',
            'active_end_time' => '22:30',
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ]);

        $group->memberships()->create([
            'user_id' => $owner->id,
            'role' => GroupMembership::ROLE_OWNER,
            'joined_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $group->ensureSystemInvite();

        $this->seedMemberships($group, $users, $owner, 120);
        $this->seedLegacyRunsForGroup($group, $owner->id);
    }

    private function seedMemberships(Group $group, Collection $users, ?User $owner, ?int $forcedCount = null): void
    {
        $targetCount = $forcedCount ?? fake()->numberBetween(50, 200);
        $availableUsers = $users
            ->reject(fn (User $user) => $owner && $user->id === $owner->id)
            ->shuffle()
            ->values();

        $selectedUsers = $availableUsers->take(max(0, $targetCount - 1));
        $adminCount = $selectedUsers->isEmpty()
            ? 0
            : min(fake()->numberBetween(1, 3), $selectedUsers->count());
        $adminIds = $selectedUsers->take($adminCount)->pluck('id')->all();

        $remainingUsers = $selectedUsers->slice($adminCount)->values();
        $moderatorCount = min(fake()->numberBetween(0, 8), $remainingUsers->count());
        $moderatorIds = $remainingUsers->take($moderatorCount)->pluck('id')->all();

        $selectedUsers->each(function (User $user, int $index) use ($group, $adminIds, $moderatorIds): void {
            $joinedAt = $group->created_at->copy()->addHours($index + 1);

            $group->memberships()->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'role' => match (true) {
                        in_array($user->id, $adminIds, true) => GroupMembership::ROLE_ADMIN,
                        in_array($user->id, $moderatorIds, true) => GroupMembership::ROLE_MODERATOR,
                        default => GroupMembership::ROLE_MEMBER,
                    },
                    'joined_at' => $joinedAt,
                    'created_at' => $joinedAt,
                    'updated_at' => $joinedAt,
                ]
            );
        });
    }

    private function groupBlueprints(): array
    {
        return [
            $this->groupBlueprint('Lich Nightwatch', 'Late evening raid planning and progression for players based on Lich and beyond.', 'lnight', 'Light', Group::JOIN_MODE_INVITE_ONLY, true, null, [
                'primary_focuses' => ['progression', 'reclears'],
                'experience_expectation' => 'semi_hardcore',
                'voice_expectation' => 'required',
                'preferred_languages' => ['en', 'de'],
                'tags' => ['Late Night', 'Savage'],
                'active_days' => ['wed', 'sun'],
            ]),
            $this->groupBlueprint('Aether Vanguard', 'Structured raid planning and clean communication for progression-minded players.', 'aetherv', 'Aether', Group::JOIN_MODE_OPEN, true, 'https://discord.gg/aetherv', [
                'primary_focuses' => ['progression', 'clears'],
                'experience_expectation' => 'midcore',
                'voice_expectation' => 'required',
                'tags' => ['Midcore', 'Rostered'],
            ]),
            $this->groupBlueprint('Chaos Forgehall', 'A small but organized EU group focused on consistent clears and static reliability.', 'forgehll', 'Chaos', Group::JOIN_MODE_INVITE_ONLY, true, null, [
                'group_type' => Group::TYPE_STATIC,
                'primary_focuses' => ['clears', 'reclears'],
                'experience_expectation' => 'semi_hardcore',
                'voice_expectation' => 'preferred',
                'preferred_languages' => ['en', 'fr'],
                'tags' => ['Static', 'Consistency'],
            ]),
            $this->groupBlueprint('Primal Lantern', 'Flexible scheduling and friendly run leadership for savage and criterion groups.', 'primalan', 'Primal', Group::JOIN_MODE_OPEN, false, 'https://discord.gg/primalan', [
                'primary_focuses' => ['progression', 'maps'],
                'experience_expectation' => 'casual',
                'voice_expectation' => 'preferred',
                'tags' => ['Criterion', 'Flexible'],
                'active_days' => ['thu', 'sat'],
            ]),
            $this->groupBlueprint('Meteor Archive', 'A planning-heavy group for spreadsheets, assignments, and clean execution.', 'metearch', 'Meteor', Group::JOIN_MODE_INVITE_ONLY, true, null, [
                'primary_focuses' => ['progression', 'clears'],
                'experience_expectation' => 'hardcore',
                'voice_expectation' => 'preferred',
                'tags' => ['Prepared', 'Assignments'],
            ]),
            $this->groupBlueprint('Crystal Echo', 'Relaxed group culture with steady clears and mount farm organization.', 'crystlec', 'Crystal', Group::JOIN_MODE_OPEN, true, 'https://discord.gg/crystlec', [
                'primary_focuses' => ['farming', 'mount_farming'],
                'experience_expectation' => 'beginner_friendly',
                'voice_expectation' => 'optional',
                'tags' => ['Casual', 'Friendly'],
            ]),
            $this->groupBlueprint('Mana Pioneers', 'JP-centered schedule coordination for players who like well-prepared runs.', 'manapion', 'Mana', Group::JOIN_MODE_INVITE_ONLY, true, null, [
                'primary_focuses' => ['progression', 'reclears'],
                'experience_expectation' => 'midcore',
                'voice_expectation' => 'preferred',
                'preferred_languages' => ['ja'],
                'tags' => ['JP Prime Time', 'Prepared'],
            ]),
            $this->groupBlueprint('Dynamis Drift', 'Casual planning, alt runs, and open community clears.', 'dynamisd', 'Dynamis', Group::JOIN_MODE_OPEN, false, 'https://discord.gg/dynamisd', [
                'primary_focuses' => ['casual_roulettes'],
                'experience_expectation' => 'casual',
                'voice_expectation' => 'optional',
                'tags' => ['Alt Friendly', 'Open Community'],
            ]),
            $this->groupBlueprint('Light Rampart', 'Hardcore raiding static focused on Ultimate and Savage content.', 'lightram', 'Light', Group::JOIN_MODE_INVITE_ONLY, true, null, [
                'group_type' => Group::TYPE_STATIC,
                'primary_focuses' => ['progression', 'clears'],
                'experience_expectation' => 'hardcore',
                'voice_expectation' => 'required',
                'preferred_languages' => ['en'],
                'tags' => ['Ultimate', 'Hardcore'],
                'active_start_time' => '20:00',
                'active_end_time' => '23:00',
            ]),
            $this->groupBlueprint('Endwalker Raiders', 'Casual-midcore group for weekly clears and mount farms.', 'endraids', 'Aether', Group::JOIN_MODE_OPEN, true, 'https://discord.gg/endraids', [
                'primary_focuses' => ['clears', 'mount_farming'],
                'experience_expectation' => 'casual',
                'voice_expectation' => 'preferred',
                'tags' => ['Weekly Clears', 'Mounts'],
            ]),
            $this->groupBlueprint('Crystal Cartel', 'Tightly coordinated scheduling for players who want polished group operations.', 'crystalc', 'Crystal', Group::JOIN_MODE_INVITE_ONLY, true, null, [
                'group_type' => Group::TYPE_STATIC,
                'primary_focuses' => ['clears', 'reclears'],
                'experience_expectation' => 'semi_hardcore',
                'voice_expectation' => 'required',
                'tags' => ['Organized', 'No Drama'],
            ]),
            $this->groupBlueprint('Savage Sunday', 'Weekend progression group with a focus on preparation and consistency.', 'savsundy', 'Primal', Group::JOIN_MODE_OPEN, true, 'https://discord.gg/savsundy', [
                'primary_focuses' => ['progression', 'clears'],
                'experience_expectation' => 'midcore',
                'voice_expectation' => 'required',
                'tags' => ['Weekend', 'Savage'],
                'active_days' => ['sun'],
            ]),
            $this->groupBlueprint('Materia Crosswinds', 'Oceanic scheduling hub for progression and reclears.', 'matcross', 'Materia', Group::JOIN_MODE_OPEN, true, 'https://discord.gg/matcross', [
                'primary_focuses' => ['progression', 'reclears'],
                'experience_expectation' => 'midcore',
                'voice_expectation' => 'preferred',
                'preferred_languages' => ['en'],
                'tags' => ['Oceanic', 'Night Raids'],
            ]),
            $this->groupBlueprint('Gaia Relay', 'JP relay-style organization for people juggling multiple raid groups.', 'gaiarely', 'Gaia', Group::JOIN_MODE_INVITE_ONLY, true, null, [
                'primary_focuses' => ['maps'],
                'experience_expectation' => 'casual',
                'voice_expectation' => 'optional',
                'preferred_languages' => ['ja'],
                'tags' => ['Relay', 'Multi Group'],
            ]),
            $this->groupBlueprint('Aether Bloom', 'Friendly atmosphere with strong organizers and active learning runs.', 'aetherbl', 'Aether', Group::JOIN_MODE_OPEN, true, 'https://discord.gg/aetherbl', [
                'primary_focuses' => ['progression'],
                'experience_expectation' => 'beginner_friendly',
                'voice_expectation' => 'preferred',
                'tags' => ['Learning Runs', 'Welcoming'],
            ]),
            $this->groupBlueprint('Chaos Lantern', 'EU scheduling and recruitment hub for mixed-skill groups.', 'chaoslan', 'Chaos', Group::JOIN_MODE_INVITE_ONLY, false, null, [
                'primary_focuses' => ['casual_roulettes'],
                'experience_expectation' => 'mixed',
                'voice_expectation' => 'optional',
                'preferred_languages' => ['en', 'fr'],
                'tags' => ['Recruiting Hub', 'Mixed Skill'],
            ]),
            $this->groupBlueprint('Primal Garrison', 'Consistent moderation, roster stability, and no-drama clears.', 'primgarr', 'Primal', Group::JOIN_MODE_INVITE_ONLY, true, 'https://discord.gg/primgarr', [
                'group_type' => Group::TYPE_STATIC,
                'primary_focuses' => ['clears', 'reclears'],
                'experience_expectation' => 'semi_hardcore',
                'voice_expectation' => 'required',
                'tags' => ['Roster Stability', 'Consistent'],
            ]),
            $this->groupBlueprint('Elemental Current', 'Steady run organization for Elemental players who want reliable uptime.', 'elemcurr', 'Elemental', Group::JOIN_MODE_OPEN, true, 'https://discord.gg/elemcurr', [
                'primary_focuses' => ['clears', 'mount_farming'],
                'experience_expectation' => 'casual',
                'voice_expectation' => 'preferred',
                'preferred_languages' => ['ja', 'en'],
                'tags' => ['Reliable', 'Steady'],
            ]),
            $this->groupBlueprint('Meteor Signal', 'A quiet but efficient planning space for scheduled clears.', 'metersig', 'Meteor', Group::JOIN_MODE_INVITE_ONLY, true, null, [
                'group_type' => Group::TYPE_STATIC,
                'primary_focuses' => ['clears'],
                'experience_expectation' => 'mixed',
                'voice_expectation' => 'optional',
                'tags' => ['Quiet', 'Efficient'],
            ]),
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
        string $joinMode,
        bool $isVisible,
        ?string $discordInviteUrl,
        array $discovery = [],
    ): array {
        return array_merge($this->defaultDiscoveryMetadata($slug, $name, $datacenter, $discordInviteUrl), [
            'name' => $name,
            'description' => $description,
            'slug' => $slug,
            'datacenter' => $datacenter,
            'group_type' => $discovery['group_type'] ?? Group::TYPE_COMMUNITY,
            'join_mode' => $joinMode,
            'is_visible' => $isVisible,
            'discord_invite_url' => $discordInviteUrl,
        ], $discovery);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultDiscoveryMetadata(string $slug, string $name, string $datacenter, ?string $discordInviteUrl): array
    {
        $region = Group::regionForDatacenter($datacenter);

        return [
            'profile_picture_url' => $this->seededProfileUrl($slug, $name, $datacenter),
            'banner_image_url' => $this->seededBannerUrl($slug, $name, $datacenter),
            'primary_focuses' => ['clears'],
            'experience_expectation' => 'mixed',
            'voice_expectation' => $discordInviteUrl ? 'preferred' : 'optional',
            'preferred_languages' => $this->defaultLanguagesForRegion($region),
            'tags' => [],
            'active_timezone' => $this->defaultTimezoneForRegion($region),
            'active_days' => $this->defaultDaysForRegion($region),
            'active_start_time' => $this->defaultStartTimeForRegion($region),
            'active_end_time' => $this->defaultEndTimeForRegion($region),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function defaultLanguagesForRegion(?string $region): array
    {
        return match ($region) {
            'EU' => ['en', 'de', 'fr'],
            'JP' => ['ja'],
            default => ['en'],
        };
    }

    /**
     * @return array<int, string>
     */
    private function defaultDaysForRegion(?string $region): array
    {
        return match ($region) {
            'EU' => ['wed', 'fri', 'sun'],
            'JP' => ['wed', 'sat'],
            'OCE' => ['fri', 'sun'],
            default => ['tue', 'thu', 'sat'],
        };
    }

    private function defaultTimezoneForRegion(?string $region): string
    {
        return match ($region) {
            'EU' => 'Europe/London',
            'JP' => 'Asia/Tokyo',
            'OCE' => 'Australia/Sydney',
            default => 'America/New_York',
        };
    }

    private function defaultStartTimeForRegion(?string $region): string
    {
        return match ($region) {
            'EU' => '19:30',
            'JP' => '21:00',
            'OCE' => '19:00',
            default => '20:00',
        };
    }

    private function defaultEndTimeForRegion(?string $region): string
    {
        return match ($region) {
            'EU' => '22:30',
            'JP' => '23:30',
            'OCE' => '22:00',
            default => '23:00',
        };
    }

    private function seededProfileUrl(string $slug, string $name, string $datacenter): string
    {
        return $this->generatedGroupImageService()->generateProfileImage($slug, $name, $datacenter);
    }

    private function seededBannerUrl(string $slug, string $name, string $datacenter): string
    {
        return $this->generatedGroupImageService()->generateBannerImage($slug, $name, $datacenter);
    }

    private function generatedGroupImageService(): GeneratedGroupImageService
    {
        return app(GeneratedGroupImageService::class);
    }

    private function seedLegacyRunsForGroup(Group $group, int $ownerId): void
    {
        $runCount = fake()->numberBetween(1, 4);
        $statuses = [
            ScheduledRun::STATUS_SCHEDULED,
            ScheduledRun::STATUS_UPCOMING,
            ScheduledRun::STATUS_DRAFT,
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
