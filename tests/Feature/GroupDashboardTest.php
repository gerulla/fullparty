<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlotAssignment;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\PhantomJob;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders a single generated group embed image on the dashboard page', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
        'name' => 'Storm Keepers',
        'slug' => 'stormkee',
        'description' => 'Late-night raid group for progression and cleanup.',
        'profile_picture_url' => '/storage/groups/storm-profile.webp',
        'banner_image_url' => '/storage/groups/storm-banner.webp',
    ]);

    $response = $this->actingAs($owner)
        ->get(route('groups.dashboard', $group));

    $response
        ->assertOk()
        ->assertSee('<meta property="og:title" content="Storm Keepers - FullParty.gg">', false)
        ->assertSee('<meta property="og:image" content="http://fullparty.test/storage/groups/embeds/stormkee-', false)
        ->assertDontSee('<meta property="og:image" content="http://fullparty.test/storage/groups/storm-banner.webp">', false)
        ->assertDontSee('<meta property="og:image" content="http://fullparty.test/storage/groups/storm-profile.webp">', false);

    expect(substr_count($response->getContent(), '<meta property="og:image"'))->toBe(1);
});

it('renders the FTEL legacy leaderboard from the static export', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
        'name' => 'Forked Tower Enjoyers',
        'slug' => 'ftel',
    ]);

    $response = $this->actingAs($owner)
        ->get(route('groups.dashboard.legacy-leaderboard', $group));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Groups/LegacyLeaderboard')
            ->where('group.slug', 'ftel')
            ->where('legacy_leaderboard.source.is_static', true)
            ->where('legacy_leaderboard.summary.total_players', 1447)
            ->where('legacy_leaderboard.summary.total_participations', 7408)
            ->where('legacy_leaderboard.summary.total_raid_leader_participations', 992)
            ->where('legacy_leaderboard.rankings.participations.0.character.name', 'Kai Dazkar')
            ->where('legacy_leaderboard.rankings.participations.0.character.avatar_url', 'https://img2.finalfantasyxiv.com/f/52272c6ea1e24f626b7a314b62cf7de6_c33f640c0cdd35f7def85b8aa31a0007fc0.jpg?1778864995')
            ->where('legacy_leaderboard.rankings.participations.0.participation_count', 128)
            ->where('legacy_leaderboard.rankings.participations.0.badges.0.type', 'participation')
            ->where('legacy_leaderboard.rankings.participations.0.badges.0.key', 'elite')
            ->where('legacy_leaderboard.rankings.participations.0.badges.1.type', 'leader')
            ->where('legacy_leaderboard.rankings.participations.0.badges.1.key', 'legendary')
            ->where('legacy_leaderboard.rankings.raid_leaders.0.character.name', 'Kai Dazkar')
            ->where('legacy_leaderboard.rankings.raid_leaders.0.raid_leader_count', 121)
            ->where('legacy_leaderboard.rankings.raid_leaders.0.badges.0.key', 'elite')
            ->where('legacy_leaderboard.rankings.raid_leaders.0.badges.1.key', 'legendary')
        )
        ->assertDontSee('TRAP MAGNET')
        ->assertDontSee('zerker tank if possible');
});

it('only exposes the legacy leaderboard for FTEL', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
        'slug' => 'not-ftel',
    ]);

    $this->actingAs($owner)
        ->get(route('groups.dashboard.legacy-leaderboard', $group))
        ->assertNotFound();
});

it('renders the group dashboard with activity-driven overview data', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-27 12:00:00'));

    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
        'description' => 'Late-night raid group for progression and cleanup.',
        'discord_invite_url' => 'https://discord.gg/fullparty',
    ]);

    $ownerCharacter = Character::factory()->primary()->create([
        'user_id' => $owner->id,
    ]);

    $moderator = User::factory()->create();
    $group->memberships()->create([
        'user_id' => $moderator->id,
        'role' => GroupMembership::ROLE_MODERATOR,
        'joined_at' => now()->subDay(),
    ]);

    $member = User::factory()->create();
    $group->memberships()->create([
        'user_id' => $member->id,
        'role' => GroupMembership::ROLE_MEMBER,
        'joined_at' => now()->subHours(3),
    ]);
    $memberCharacter = Character::factory()->primary()->create([
        'user_id' => $member->id,
    ]);

    $type = ActivityType::factory()->create([
        'created_by_user_id' => $owner->id,
        'slug' => 'chaotic-alliance',
        'draft_name' => ['en' => 'Chaotic Alliance'],
    ]);

    $version = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $type->id,
        'published_by_user_id' => $owner->id,
        'layout_schema' => [
            'groups' => [
                [
                    'key' => 'party-a',
                    'label' => ['en' => 'Party A'],
                    'size' => 4,
                ],
            ],
        ],
        'slot_schema' => [],
        'progress_schema' => ['milestones' => []],
        'bench_size' => 0,
        'prog_points' => [],
    ]);

    $type->update([
        'current_published_version_id' => $version->id,
    ]);

    $draftActivity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_DRAFT,
        'title' => 'Planning Night',
        'allow_guest_applications' => true,
        'is_public' => true,
        'starts_at' => now()->addDays(3),
        'updated_at' => now()->subHours(2),
    ]);

    ActivityApplication::factory()->create([
        'activity_id' => $draftActivity->id,
        'user_id' => $owner->id,
        'selected_character_id' => $ownerCharacter->id,
    ]);

    $assignedActivity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_ASSIGNED,
        'title' => 'Roster Locked',
        'allow_guest_applications' => false,
        'is_public' => false,
        'starts_at' => now()->addDay(),
        'updated_at' => now()->subHour(),
    ]);

    $scheduledActivity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Soon Pull',
        'needs_application' => false,
        'allow_guest_applications' => false,
        'is_public' => true,
        'starts_at' => now()->addHours(2),
        'updated_at' => now()->subDays(3),
    ]);

    $completeActivity = Activity::factory()->complete()->create([
        'group_id' => $group->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $owner->id,
        'title' => 'Cleanup Clear',
        'allow_guest_applications' => false,
        'is_public' => true,
        'starts_at' => now()->subDay(),
        'completed_at' => now()->subHour(),
        'updated_at' => now()->subMinutes(20),
    ]);

    $cancelledActivity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_CANCELLED,
        'title' => 'Cancelled Night',
        'allow_guest_applications' => false,
        'is_public' => true,
        'starts_at' => now()->subDays(2),
        'updated_at' => now()->subHours(4),
    ]);

    ActivityApplication::factory()->create([
        'activity_id' => $draftActivity->id,
        'user_id' => $member->id,
        'selected_character_id' => $memberCharacter->id,
    ]);
    ActivityApplication::factory()->guest()->declined()->create([
        'activity_id' => $draftActivity->id,
    ]);

    try {
        $this->actingAs($owner);

        $response = $this->get(route('groups.dashboard', $group));

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Groups/CommunityDashboard')
                ->where('group.name', $group->name)
                ->where('group.stats.activity_count', 5)
                ->where('group.stats.draft_count', 1)
                ->where('group.stats.scheduled_count', 1)
                ->where('group.stats.assigned_count', 1)
                ->where('group.stats.completed_count', 1)
                ->where('group.stats.cancelled_count', 1)
                ->where('group.stats.open_application_count', 2)
                ->where('group.stats.public_activity_count', 4)
                ->where('group.notifications.enabled', true)
                ->where('group.permissions.can_toggle_notifications', true)
                ->where('group.permissions.can_leave', false)
                ->where('group.member_role_breakdown.owner', 1)
                ->where('group.member_role_breakdown.moderator', 1)
                ->where('group.member_role_breakdown.member', 1)
                ->where('group.content_summary.total_runs', 5)
                ->where('group.content_summary.status_breakdown.0.status', 'draft')
                ->where('group.content_summary.status_breakdown.0.count', 1)
                ->where('group.content_summary.status_breakdown.1.status', 'scheduled')
                ->where('group.content_summary.status_breakdown.1.count', 1)
                ->where('group.content_items.0.activity_name', $version->name['en'])
                ->where('group.content_items.0.total_runs', 5)
                ->where('group.content_items.0.completed_runs', 1)
                ->where('group.content_items.0.active_runs', 3)
                ->where('group.current_week.start_date', '2026-05-27')
                ->where('group.current_week.end_date', '2026-06-02')
                ->has('group.activity_status_breakdown', 7)
                ->has('group.current_week_activities', 3)
                ->where('group.current_week_activities.0.id', $scheduledActivity->id)
                ->where('group.current_week_activities.1.id', $assignedActivity->id)
                ->where('group.current_week_activities.2.id', $draftActivity->id)
                ->has('group.upcoming_activities', 3)
                ->where('group.upcoming_activities.0.id', $scheduledActivity->id)
                ->where('group.upcoming_activities.0.title', 'Soon Pull')
                ->where('group.upcoming_activities.0.can_view_overview', true)
                ->where('group.upcoming_activities.0.can_apply', false)
                ->where('group.upcoming_activities.0.secret_key', null)
                ->where('group.upcoming_activities.0.links.view', route('groups.dashboard.activities.show', [
                    'group' => $group->slug,
                    'activity' => $scheduledActivity->id,
                ], false))
                ->where('group.upcoming_activities.0.links.apply', null)
                ->where('group.upcoming_activities.1.id', $assignedActivity->id)
                ->where('group.upcoming_activities.1.can_view_overview', true)
                ->where('group.upcoming_activities.1.secret_key', null)
                ->where('group.upcoming_activities.2.id', $draftActivity->id)
                ->where('group.upcoming_activities.2.has_existing_application', true)
                ->where('group.upcoming_activities.2.can_apply', false)
                ->where('group.upcoming_activities.2.can_view_overview', true)
                ->where('group.upcoming_activities.2.activity_type.slug', 'chaotic-alliance')
                ->where('group.upcoming_activities.2.application_count', 2)
                ->where('group.upcoming_activities.2.slot_count', 4)
                ->where('group.upcoming_activities.2.links.apply', route('groups.activities.application', [
                    'group' => $group->slug,
                    'activity' => $draftActivity->id,
                ], false))
                ->has('group.history_activities', 2)
                ->where('group.history_activities.0.id', $completeActivity->id)
                ->where('group.history_activities.0.can_view_overview', true)
                ->where('group.history_activities.1.id', $cancelledActivity->id)
                ->where('group.history_activities.1.can_view_overview', true)
            );

        $this->actingAs($member)
            ->get(route('groups.dashboard', $group))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('group.stats.activity_count', 4)
                ->where('group.stats.draft_count', 0)
                ->where('group.stats.scheduled_count', 1)
                ->where('group.stats.assigned_count', 1)
                ->where('group.stats.completed_count', 1)
                ->where('group.stats.cancelled_count', 1)
                ->where('group.stats.open_application_count', 1)
                ->where('group.notifications.enabled', true)
                ->where('group.permissions.can_toggle_notifications', true)
                ->where('group.permissions.can_leave', true)
                ->where('group.content_summary.total_runs', 4)
                ->where('group.content_items.0.total_runs', 4)
                ->where('group.content_items.0.active_runs', 2)
                ->has('group.current_week_activities', 2)
                ->where('group.current_week_activities.0.id', $scheduledActivity->id)
                ->where('group.current_week_activities.1.id', $assignedActivity->id)
                ->has('group.upcoming_activities', 2)
                ->where('group.upcoming_activities.0.id', $scheduledActivity->id)
                ->where('group.upcoming_activities.0.can_view_overview', true)
                ->where('group.upcoming_activities.0.secret_key', null)
                ->where('group.upcoming_activities.1.id', $assignedActivity->id)
                ->where('group.upcoming_activities.1.can_view_overview', true)
                ->where('group.upcoming_activities.1.secret_key', null)
                ->has('group.history_activities', 2)
                ->where('group.history_activities.0.id', $completeActivity->id)
                ->where('group.history_activities.0.can_view_overview', true)
                ->where('group.history_activities.1.id', $cancelledActivity->id)
                ->where('group.history_activities.1.can_view_overview', true)
            );

    } finally {
        Carbon::setTestNow();
    }
});

it('renders the community dashboard page for static groups', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->create([
        'owner_id' => $owner->id,
        'group_type' => Group::TYPE_STATIC,
    ]);

    $this->actingAs($owner)
        ->get(route('groups.dashboard', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Groups/CommunityDashboard')
            ->where('group.id', $group->id)
            ->where('group.group_type', Group::TYPE_STATIC)
        );
});

it('groups dashboard content by activity type instead of published version', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
        'slug' => 'dashvers',
    ]);
    $activityType = ActivityType::factory()->create([
        'slug' => 'forked-tower',
        'draft_name' => ['en' => 'Forked Tower'],
    ]);
    $firstVersion = ActivityTypeVersion::factory()->for($activityType)->create([
        'version' => 1,
        'name' => ['en' => 'Forked Tower V1'],
        'small_image_url' => '/storage/activities/forked-tower-v1.webp',
    ]);
    $secondVersion = ActivityTypeVersion::factory()->for($activityType)->create([
        'version' => 2,
        'name' => ['en' => 'Forked Tower V2'],
        'small_image_url' => '/storage/activities/forked-tower-v2.webp',
    ]);

    $activityType->update(['current_published_version_id' => $secondVersion->id]);

    Activity::factory()->complete()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $firstVersion->id,
        'starts_at' => now()->subWeek(),
    ]);
    Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $secondVersion->id,
        'status' => Activity::STATUS_SCHEDULED,
        'starts_at' => now()->addDay(),
    ]);

    $this->actingAs($owner)
        ->get(route('groups.dashboard', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('group.content_items', 1)
            ->where('group.content_items.0.key', 'type:'.$activityType->id)
            ->where('group.content_items.0.activity_name', 'Forked Tower V2')
            ->where('group.content_items.0.activity_image_url', '/storage/activities/forked-tower-v2.webp')
            ->where('group.content_items.0.total_runs', 2)
            ->where('group.content_items.0.completed_runs', 1)
            ->where('group.content_items.0.active_runs', 1)
        );
});

it('renders the group statistics page when no participants have been rostered', function () {
    Cache::flush();

    try {
        $owner = User::factory()->create();
        $group = Group::factory()->open()->create([
            'owner_id' => $owner->id,
        ]);

        Activity::factory()->create([
            'group_id' => $group->id,
            'starts_at' => now()->addDay(),
        ]);

        $this->actingAs($owner)
            ->get(route('groups.dashboard.statistics', $group))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Groups/Statistics')
                ->where('group.slug', $group->slug)
                ->where('statistics.summary.total_runs', 1)
                ->where('statistics.summary.total_participants', 0)
                ->where('statistics.summary.unique_participants', 0)
                ->where('statistics.classes.total', 0)
                ->where('statistics.phantom_jobs.total', 0)
            );
    } finally {
        Cache::flush();
    }
});

it('renders the group statistics page with participation application and loadout data', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-26 12:00:00'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-26 12:00:00'));
    Cache::flush();

    try {
        $owner = User::factory()->create();
        $group = Group::factory()->open()->create([
            'owner_id' => $owner->id,
        ]);
        $member = User::factory()->create();
        $group->memberships()->create([
            'user_id' => $member->id,
            'role' => GroupMembership::ROLE_MEMBER,
            'joined_at' => now()->subMonths(2),
        ]);
        $whiteMage = CharacterClass::query()->create([
            'name' => 'White Mage',
            'shorthand' => 'WHM',
            'role' => 'healer',
            'icon_url' => null,
            'flaticon_url' => null,
        ]);
        $warrior = CharacterClass::query()->create([
            'name' => 'Warrior',
            'shorthand' => 'WAR',
            'role' => 'tank',
            'icon_url' => null,
            'flaticon_url' => null,
        ]);
        $phantomBard = PhantomJob::query()->create([
            'name' => 'Phantom Bard',
            'max_level' => 10,
            'icon_url' => null,
            'black_icon_url' => null,
            'transparent_icon_url' => null,
            'sprite_url' => null,
        ]);
        $phantomKnight = PhantomJob::query()->create([
            'name' => 'Phantom Mystic Knight',
            'max_level' => 10,
            'icon_url' => null,
            'black_icon_url' => null,
            'transparent_icon_url' => null,
            'sprite_url' => null,
        ]);
        $ownerCharacter = Character::factory()->primary()->create([
            'user_id' => $owner->id,
        ]);
        $memberCharacter = Character::factory()->primary()->create([
            'user_id' => $member->id,
        ]);
        $recentRun = Activity::factory()->complete()->create([
            'group_id' => $group->id,
            'starts_at' => now()->subDays(10),
        ]);
        $newerRun = Activity::factory()->complete()->create([
            'group_id' => $group->id,
            'starts_at' => now()->subDays(3),
        ]);
        Activity::factory()->create([
            'group_id' => $group->id,
            'starts_at' => now()->subMonths(2),
        ]);

        $createAssignment = function (
            Activity $activity,
            Character $character,
            CharacterClass $class,
            PhantomJob $phantomJob,
            int $slotIndex,
        ) use ($group): void {
            $slot = $activity->slots()->orderBy('sort_order')->skip($slotIndex)->firstOrFail();
            $snapshot = [
                'character_class' => [
                    'id' => $class->id,
                    'name' => $class->name,
                    'role' => $class->role,
                    'shorthand' => $class->shorthand,
                ],
                'phantom_job' => [
                    'id' => $phantomJob->id,
                    'name' => $phantomJob->name,
                ],
            ];

            $slot->fieldValues()->where('field_key', 'character_class')->update(['value' => $snapshot['character_class']]);
            $slot->fieldValues()->where('field_key', 'phantom_job')->update(['value' => $snapshot['phantom_job']]);

            ActivitySlotAssignment::query()->create([
                'activity_id' => $activity->id,
                'group_id' => $group->id,
                'activity_slot_id' => $slot->id,
                'character_id' => $character->id,
                'application_id' => null,
                'assignment_source' => ActivitySlotAssignment::SOURCE_MANUAL,
                'field_values_snapshot' => $snapshot,
                'attendance_status' => ActivitySlotAssignment::STATUS_CHECKED_IN,
                'assigned_at' => $activity->starts_at,
                'assigned_by_user_id' => $group->owner_id,
            ]);
        };

        $createAssignment($recentRun, $ownerCharacter, $whiteMage, $phantomBard, 0);
        $createAssignment($recentRun, $memberCharacter, $warrior, $phantomKnight, 1);
        $createAssignment($newerRun, $ownerCharacter, $whiteMage, $phantomBard, 0);

        $unmaterializedSlot = $newerRun->slots()->orderBy('sort_order')->skip(1)->firstOrFail();
        $unmaterializedSlot->update([
            'assigned_character_id' => $memberCharacter->id,
            'assigned_by_user_id' => $owner->id,
        ]);
        $unmaterializedSlot->fieldValues()->where('field_key', 'character_class')->update([
            'value' => [
                'id' => $whiteMage->id,
                'name' => $whiteMage->name,
                'role' => $whiteMage->role,
                'shorthand' => $whiteMage->shorthand,
            ],
        ]);
        $unmaterializedSlot->fieldValues()->where('field_key', 'phantom_job')->update([
            'value' => [
                'id' => $phantomBard->id,
                'name' => $phantomBard->name,
            ],
        ]);

        ActivityApplication::factory()->create([
            'activity_id' => $recentRun->id,
            'user_id' => $owner->id,
            'selected_character_id' => $ownerCharacter->id,
            'status' => ActivityApplication::STATUS_PENDING,
            'submitted_at' => now()->subDays(8),
        ]);
        ActivityApplication::factory()->approved($owner)->create([
            'activity_id' => $recentRun->id,
            'user_id' => $member->id,
            'selected_character_id' => $memberCharacter->id,
            'submitted_at' => now()->subDays(7),
        ]);
        ActivityApplication::factory()->declined($owner)->create([
            'activity_id' => $newerRun->id,
            'user_id' => User::factory()->create()->id,
            'submitted_at' => now()->subDays(2),
        ]);

        $this->actingAs($owner)
            ->get(route('groups.dashboard.statistics', $group))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Groups/Statistics')
                ->where('group.slug', $group->slug)
                ->where('statistics.summary.total_runs', 3)
                ->where('statistics.summary.total_participants', 4)
                ->where('statistics.summary.unique_participants', 2)
                ->where('statistics.summary.active_players_past_month', 2)
                ->where('statistics.summary.average_participants_per_raid', 1.3)
                ->where('statistics.participation_trend.0.participant_count', 2)
                ->where('statistics.participation_trend.1.participant_count', 2)
                ->where('statistics.applications.distribution.0.key', 'pending')
                ->where('statistics.applications.distribution.0.count', 1)
                ->where('statistics.applications.distribution.1.key', 'approved')
                ->where('statistics.applications.distribution.1.count', 1)
                ->where('statistics.applications.distribution.2.key', 'declined')
                ->where('statistics.applications.distribution.2.count', 1)
                ->has('statistics.applications.daily_submissions', 7)
                ->where('statistics.applications.daily_submissions.0.date', '2026-05-20')
                ->where('statistics.applications.daily_submissions.0.count', 0)
                ->where('statistics.applications.daily_submissions.4.date', '2026-05-24')
                ->where('statistics.applications.daily_submissions.4.count', 1)
                ->where('statistics.applications.daily_submissions.6.date', '2026-05-26')
                ->where('statistics.applications.daily_submissions.6.count', 0)
                ->where('statistics.classes.distribution.0.label', 'White Mage')
                ->where('statistics.classes.distribution.0.count', 3)
                ->where('statistics.classes.distribution.1.label', 'Warrior')
                ->where('statistics.classes.distribution.1.count', 1)
                ->where('statistics.phantom_jobs.distribution.0.label', 'Phantom Bard')
                ->where('statistics.phantom_jobs.distribution.0.count', 3)
                ->where('statistics_cache.can_refresh', true)
                ->where('statistics_cache.refresh_cooldown_seconds', 0)
            );

        Activity::factory()->complete()->create([
            'group_id' => $group->id,
            'starts_at' => now()->subDay(),
        ]);

        $this->actingAs($owner)
            ->get(route('groups.dashboard.statistics', $group))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('statistics.summary.total_runs', 3)
            );

        $this->actingAs($owner)
            ->post(route('groups.dashboard.statistics.refresh', $group))
            ->assertRedirect(route('groups.dashboard.statistics', $group))
            ->assertSessionHas('success', 'group_statistics_refreshed');

        $this->actingAs($owner)
            ->get(route('groups.dashboard.statistics', $group))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('statistics.summary.total_runs', 4)
                ->where('statistics_cache.can_refresh', false)
                ->where('statistics_cache.refresh_cooldown_seconds', 300)
            );

        Activity::factory()->complete()->create([
            'group_id' => $group->id,
            'starts_at' => now()->subHours(12),
        ]);

        $this->actingAs($owner)
            ->post(route('groups.dashboard.statistics.refresh', $group))
            ->assertRedirect(route('groups.dashboard.statistics', $group))
            ->assertSessionHas('error', 'group_statistics_refresh_cooldown');

        $this->actingAs($owner)
            ->get(route('groups.dashboard.statistics', $group))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('statistics.summary.total_runs', 4)
            );

        Carbon::setTestNow(Carbon::parse('2026-05-26 12:05:01'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-26 12:05:01'));

        $this->actingAs($owner)
            ->post(route('groups.dashboard.statistics.refresh', $group))
            ->assertRedirect(route('groups.dashboard.statistics', $group))
            ->assertSessionHas('success', 'group_statistics_refreshed');

        $this->actingAs($owner)
            ->get(route('groups.dashboard.statistics', $group))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('statistics.summary.total_runs', 5)
                ->where('statistics_cache.can_refresh', false)
                ->where('statistics_cache.refresh_cooldown_seconds', 300)
            );

        $this->actingAs($owner)
            ->get(route('groups.dashboard.leaderboard', $group))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Groups/Leaderboard')
                ->where('group.slug', $group->slug)
            );
    } finally {
        Cache::flush();
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
    }
});

it('renders the group leaderboard with participation and host success rankings', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);

    $type = ActivityType::factory()->create([
        'created_by_user_id' => $owner->id,
    ]);
    $version = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $type->id,
        'published_by_user_id' => $owner->id,
        'prog_points' => [
            ['key' => 'phase-1', 'label' => ['en' => 'Phase 1'], 'order' => 1],
            ['key' => 'phase-2', 'label' => ['en' => 'Phase 2'], 'order' => 2],
            ['key' => 'clear', 'label' => ['en' => 'Clear'], 'order' => 3],
        ],
    ]);
    $type->update([
        'current_published_version_id' => $version->id,
    ]);

    $kevin = Character::factory()->primary()->create([
        'name' => 'Kevin Clear',
    ]);
    $alice = Character::factory()->primary()->create([
        'name' => 'Alice Anchor',
    ]);
    $mira = Character::factory()->primary()->create([
        'name' => 'Mira Marker',
    ]);

    $createRun = function (
        Character $character,
        ?string $targetProgPoint,
        ?string $furthestProgress,
        string $status = Activity::STATUS_COMPLETE,
        bool $isHost = false,
        bool $isRaidLeader = false,
        int $daysAgo = 1,
    ) use ($group, $type, $version, $owner): Activity {
        $activity = Activity::factory()->create([
            'group_id' => $group->id,
            'activity_type_id' => $type->id,
            'activity_type_version_id' => $version->id,
            'organized_by_user_id' => $owner->id,
            'status' => $status,
            'target_prog_point_key' => $targetProgPoint,
            'furthest_progress_key' => $furthestProgress,
            'starts_at' => now()->subDays($daysAgo),
        ]);
        $activity->forceFill([
            'target_prog_point_key' => $targetProgPoint,
            'furthest_progress_key' => $furthestProgress,
        ])->save();

        $activity->slots()->orderBy('sort_order')->firstOrFail()->update([
            'assigned_character_id' => $character->id,
            'assigned_by_user_id' => $owner->id,
            'is_host' => $isHost,
            'is_raid_leader' => $isRaidLeader,
        ]);

        return $activity;
    };

    $createRun($kevin, 'phase-2', 'clear', isHost: true, isRaidLeader: true, daysAgo: 1);
    $createRun($kevin, 'clear', 'phase-2', isHost: true, daysAgo: 40);
    $createRun($kevin, null, null, isHost: true, daysAgo: 200);
    $createRun($kevin, 'phase-1', 'phase-2', Activity::STATUS_ASSIGNED, isHost: true);
    $createRun($alice, null, null, isHost: true, isRaidLeader: true, daysAgo: 2);
    $createRun($alice, 'phase-1', 'phase-2', isRaidLeader: true, daysAgo: 50);
    $createRun($mira, 'phase-1', 'phase-1', daysAgo: 3);

    foreach (range(1, 11) as $index) {
        $extraCharacter = Character::factory()->primary()->create(['name' => sprintf('Extra Player %02d', $index)]);
        $createRun($extraCharacter, null, null, daysAgo: 4);
    }

    $this->actingAs($owner)
        ->get(route('groups.dashboard.leaderboard', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Groups/Leaderboard')
            ->where('leaderboard.summary.total_participations', 17)
            ->where('leaderboard.summary.ranked_participants', 14)
            ->where('leaderboard.summary.raid_leader_participations', 3)
            ->where('leaderboard.summary.host_participations', 4)
            ->where('leaderboard.summary.completed_hosted_runs', 4)
            ->where('leaderboard.rankings.overall.0.character.name', 'Kevin Clear')
            ->where('leaderboard.rankings.overall.0.count', 3)
            ->where('leaderboard.rankings.raid_leaders.0.character.name', 'Alice Anchor')
            ->where('leaderboard.rankings.raid_leaders.0.count', 2)
            ->where('leaderboard.rankings.hosts.0.character.name', 'Kevin Clear')
            ->where('leaderboard.rankings.hosts.0.count', 3)
            ->has('leaderboard.rankings.host_success', 1)
            ->where('leaderboard.rankings.host_success.0.character.name', 'Kevin Clear')
            ->where('leaderboard.rankings.host_success.0.hosted_runs', 3)
            ->where('leaderboard.rankings.host_success.0.successful_runs', 2)
            ->where('leaderboard.rankings.host_success.0.documented_successes', 1)
            ->where('leaderboard.rankings.host_success.0.auto_successes', 1)
            ->where('leaderboard.rankings.host_success.0.failed_runs', 1)
            ->where('leaderboard.rankings.host_success.0.success_rate', 66.7)
            ->where('leaderboard.rankings.host_success.0.weighted_success_rate', 50)
            ->where('leaderboard.rankings.host_success.0.performance_score', 50)
        );

    $this->actingAs($owner)
        ->getJson(route('groups.dashboard.leaderboard.ranking', [
            'group' => $group,
            'category' => 'overall',
            'period' => 'past_30_days',
            'limit' => 'all',
        ]))
        ->assertOk()
        ->assertJsonCount(14, 'data')
        ->assertJsonPath('data.0.character.name', 'Alice Anchor')
        ->assertJsonPath('data.0.count', 1);

    $this->actingAs($owner)
        ->getJson(route('groups.dashboard.leaderboard.ranking', [
            'group' => $group,
            'category' => 'overall',
            'period' => 'past_30_days',
            'limit' => '10',
        ]))
        ->assertOk()
        ->assertJsonCount(10, 'data');
});

it('caches the group leaderboard and refreshes it with a cooldown', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-26 12:00:00'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-26 12:00:00'));

    try {
        $owner = User::factory()->create();
        $group = Group::factory()->open()->create([
            'owner_id' => $owner->id,
        ]);

        $type = ActivityType::factory()->create([
            'created_by_user_id' => $owner->id,
        ]);
        $version = ActivityTypeVersion::factory()->create([
            'activity_type_id' => $type->id,
            'published_by_user_id' => $owner->id,
            'prog_points' => [],
        ]);
        $type->update([
            'current_published_version_id' => $version->id,
        ]);

        $host = Character::factory()->primary()->create([
            'name' => 'Cache Host',
        ]);

        $createHostedRun = function () use ($group, $type, $version, $owner, $host): Activity {
            $activity = Activity::factory()->complete()->create([
                'group_id' => $group->id,
                'activity_type_id' => $type->id,
                'activity_type_version_id' => $version->id,
                'organized_by_user_id' => $owner->id,
                'target_prog_point_key' => null,
                'furthest_progress_key' => null,
                'starts_at' => now()->subDay(),
            ]);

            $activity->slots()->orderBy('sort_order')->firstOrFail()->update([
                'assigned_character_id' => $host->id,
                'assigned_by_user_id' => $owner->id,
                'is_host' => true,
            ]);

            return $activity;
        };

        $createHostedRun();
        $createHostedRun();

        $this->actingAs($owner)
            ->get(route('groups.dashboard.leaderboard', $group))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('leaderboard.summary.completed_hosted_runs', 2)
                ->where('leaderboard.rankings.host_success.0.hosted_runs', 2)
                ->where('leaderboard_cache.can_refresh', true)
                ->where('leaderboard_cache.refresh_cooldown_seconds', 0)
            );

        $createHostedRun();

        $this->actingAs($owner)
            ->get(route('groups.dashboard.leaderboard', $group))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('leaderboard.summary.completed_hosted_runs', 2)
            );

        $this->actingAs($owner)
            ->post(route('groups.dashboard.leaderboard.refresh', $group))
            ->assertRedirect(route('groups.dashboard.leaderboard', $group))
            ->assertSessionHas('success', 'group_leaderboard_refreshed');

        $this->actingAs($owner)
            ->get(route('groups.dashboard.leaderboard', $group))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('leaderboard.summary.completed_hosted_runs', 3)
                ->where('leaderboard_cache.can_refresh', false)
                ->where('leaderboard_cache.refresh_cooldown_seconds', 300)
            );

        $createHostedRun();

        $this->actingAs($owner)
            ->post(route('groups.dashboard.leaderboard.refresh', $group))
            ->assertRedirect(route('groups.dashboard.leaderboard', $group))
            ->assertSessionHas('error', 'group_leaderboard_refresh_cooldown');

        $this->actingAs($owner)
            ->get(route('groups.dashboard.leaderboard', $group))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('leaderboard.summary.completed_hosted_runs', 3)
            );

        Carbon::setTestNow(Carbon::parse('2026-05-26 12:05:01'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-26 12:05:01'));

        $this->actingAs($owner)
            ->post(route('groups.dashboard.leaderboard.refresh', $group))
            ->assertRedirect(route('groups.dashboard.leaderboard', $group))
            ->assertSessionHas('success', 'group_leaderboard_refreshed');

        $this->actingAs($owner)
            ->get(route('groups.dashboard.leaderboard', $group))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('leaderboard.summary.completed_hosted_runs', 4)
                ->where('leaderboard_cache.can_refresh', false)
                ->where('leaderboard_cache.refresh_cooldown_seconds', 300)
            );
    } finally {
        Cache::flush();
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
    }
});
