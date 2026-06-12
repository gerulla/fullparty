<?php

use App\Models\Activity;
use App\Models\ActivitySlotAssignment;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns discovery details for visible groups', function () {
    $user = User::factory()->create();
    $group = Group::factory()->open()->create([
        'name' => 'Detail Group',
        'slug' => 'detailgp',
        'description' => 'Detailed description',
        'primary_focuses' => ['progression', 'maps'],
        'experience_expectation' => 'midcore',
        'voice_expectation' => 'preferred',
        'preferred_languages' => ['en', 'de'],
        'tags' => ['Late Night', 'Blind Prog'],
        'active_timezone' => 'Europe/London',
        'active_days' => ['wed', 'fri'],
        'active_start_time' => '19:00:00',
        'active_end_time' => '22:00:00',
    ]);
    $completedActivity = Activity::factory()->complete()->create([
        'group_id' => $group->id,
        'starts_at' => now()->subDays(2),
        'title' => 'Ultimate Cleanup',
    ]);
    $cancelledActivity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $completedActivity->activity_type_id,
        'activity_type_version_id' => $completedActivity->activity_type_version_id,
        'status' => Activity::STATUS_CANCELLED,
        'starts_at' => now()->subWeek(),
        'title' => 'Maps Night',
    ]);
    $upcomingActivity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $completedActivity->activity_type_id,
        'activity_type_version_id' => $completedActivity->activity_type_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'starts_at' => now()->addDays(3),
        'title' => 'Ultimate Reclear',
    ]);
    $character = Character::factory()->create([
        'user_id' => $group->owner_id,
    ]);
    Character::factory()->primary()->create([
        'user_id' => $group->owner_id,
        'name' => 'Owner Main',
        'avatar_url' => 'https://example.com/owner-main.png',
    ]);
    $slot = $completedActivity->slots()->firstOrFail();

    ActivitySlotAssignment::query()->create([
        'activity_id' => $completedActivity->id,
        'group_id' => $group->id,
        'activity_slot_id' => $slot->id,
        'character_id' => $character->id,
        'attendance_status' => ActivitySlotAssignment::STATUS_CHECKED_IN,
        'assigned_at' => now()->subDays(2),
        'checked_in_at' => now()->subDays(2)->addMinutes(5),
    ]);

    $response = $this->actingAs($user)->getJson(route('groups.details', $group));

    $response
        ->assertOk()
        ->assertJsonPath('data.name', 'Detail Group')
        ->assertJsonPath('data.region', $group->inferredRegion())
        ->assertJsonPath('data.primary_focuses.0', 'progression')
        ->assertJsonPath('data.experience_expectation', 'midcore')
        ->assertJsonPath('data.voice_expectation', 'preferred')
        ->assertJsonPath('data.preferred_languages.0', 'en')
        ->assertJsonPath('data.tags.0', 'Late Night')
        ->assertJsonPath('data.links.dashboard', null)
        ->assertJsonPath('data.notifications.enabled', true)
        ->assertJsonPath('data.permissions.can_join', true)
        ->assertJsonPath('data.permissions.can_leave', false)
        ->assertJsonPath('data.permissions.can_toggle_notifications', false)
        ->assertJsonPath('data.owner.name', 'Owner Main')
        ->assertJsonPath('data.owner.avatar_url', 'https://example.com/owner-main.png')
        ->assertJsonPath('data.stats.member_count', 1)
        ->assertJsonPath('data.activity_summary.completed_runs', 1)
        ->assertJsonPath('data.activity_summary.total_runs', 3)
        ->assertJsonPath('data.recent_runs.0.id', $completedActivity->id)
        ->assertJsonPath('data.recent_runs.0.status', Activity::STATUS_COMPLETE)
        ->assertJsonPath('data.recent_runs.0.activity_image_url', $completedActivity->activityTypeVersion?->small_image_url)
        ->assertJsonPath('data.recent_runs.0.turnout_count', 1)
        ->assertJsonPath('data.content_summary.total_runs', 3)
        ->assertJsonPath('data.content_summary.status_breakdown.0.status', 'draft')
        ->assertJsonPath('data.content_summary.status_breakdown.0.count', 0)
        ->assertJsonPath('data.content_summary.status_breakdown.1.status', 'scheduled')
        ->assertJsonPath('data.content_summary.status_breakdown.1.count', 1)
        ->assertJsonPath('data.content_summary.status_breakdown.3.status', 'complete')
        ->assertJsonPath('data.content_summary.status_breakdown.3.count', 1)
        ->assertJsonPath('data.content_summary.status_breakdown.4.status', 'cancelled')
        ->assertJsonPath('data.content_summary.status_breakdown.4.count', 1)
        ->assertJsonPath('data.content_items.0.activity_name', $completedActivity->activityTypeVersion?->name['en'])
        ->assertJsonPath('data.content_items.0.activity_image_url', $completedActivity->activityTypeVersion?->small_image_url)
        ->assertJsonPath('data.content_items.0.total_runs', 3)
        ->assertJsonPath('data.content_items.0.completed_runs', 1)
        ->assertJsonPath('data.content_items.0.last_run_at', $completedActivity->starts_at?->toIso8601String())
        ->assertJsonPath('data.content_items.0.next_run_at', $upcomingActivity->starts_at?->toIso8601String())
        ->assertJsonPath('data.recent_runs.1.id', $cancelledActivity->id)
        ->assertJsonPath('data.recent_runs.1.status', Activity::STATUS_CANCELLED);
});

it('does not expose discovery details for hidden groups', function () {
    $user = User::factory()->create();
    $group = Group::factory()->hidden()->create([
        'slug' => 'hiddenon',
    ]);

    $this->actingAs($user)
        ->getJson(route('groups.details', $group))
        ->assertNotFound();
});

it('derives turnout from filled slots even before assignment snapshots are materialized', function () {
    $user = User::factory()->create();
    $group = Group::factory()->open()->create([
        'slug' => 'turnoutg',
    ]);
    $activity = Activity::factory()->complete()->create([
        'group_id' => $group->id,
        'starts_at' => now()->subDay(),
    ]);
    $firstCharacter = Character::factory()->create([
        'user_id' => $group->owner_id,
    ]);
    $secondCharacter = Character::factory()->create();
    $slots = $activity->slots()->orderBy('sort_order')->take(2)->get();

    $slots[0]->update([
        'assigned_character_id' => $firstCharacter->id,
    ]);
    $slots[1]->update([
        'assigned_character_id' => $secondCharacter->id,
    ]);

    expect($activity->slotAssignments()->count())->toBe(0);

    $this->actingAs($user)
        ->getJson(route('groups.details', $group))
        ->assertOk()
        ->assertJsonPath('data.recent_runs.0.id', $activity->id)
        ->assertJsonPath('data.recent_runs.0.turnout_count', 2);
});

it('exposes discoverable runs in discovery activity details', function () {
    $user = User::factory()->create();
    $group = Group::factory()->open()->create([
        'slug' => 'pubruns',
    ]);
    $publicActivity = Activity::factory()->complete()->create([
        'group_id' => $group->id,
        'is_public' => true,
        'starts_at' => now()->subDay(),
    ]);
    $membersOnlyActivity = Activity::factory()->complete()->private()->create([
        'group_id' => $group->id,
        'activity_type_id' => $publicActivity->activity_type_id,
        'activity_type_version_id' => $publicActivity->activity_type_version_id,
        'starts_at' => now()->subHours(12),
    ]);

    $this->actingAs($user)
        ->getJson(route('groups.details', $group))
        ->assertOk()
        ->assertJsonPath('data.activity_summary.completed_runs', 2)
        ->assertJsonPath('data.activity_summary.total_runs', 2)
        ->assertJsonPath('data.content_summary.total_runs', 2)
        ->assertJsonPath('data.content_items.0.total_runs', 2)
        ->assertJsonCount(2, 'data.recent_runs')
        ->assertJsonCount(1, 'data.content_items')
        ->assertJsonPath('data.recent_runs.0.id', $membersOnlyActivity->id)
        ->assertJsonPath('data.recent_runs.1.id', $publicActivity->id);

    expect($publicActivity->id)->not->toBe($membersOnlyActivity->id);
});

it('groups discovery content by activity type instead of published version', function () {
    $user = User::factory()->create();
    $group = Group::factory()->open()->create([
        'slug' => 'vergroup',
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

    $this->actingAs($user)
        ->getJson(route('groups.details', $group))
        ->assertOk()
        ->assertJsonCount(1, 'data.content_items')
        ->assertJsonPath('data.content_items.0.key', 'type:'.$activityType->id)
        ->assertJsonPath('data.content_items.0.activity_name', 'Forked Tower V2')
        ->assertJsonPath('data.content_items.0.activity_image_url', '/storage/activities/forked-tower-v2.webp')
        ->assertJsonPath('data.content_items.0.total_runs', 2)
        ->assertJsonPath('data.content_items.0.completed_runs', 1)
        ->assertJsonPath('data.content_items.0.active_runs', 1);
});

it('only exposes owner and moderators in discovery team details', function () {
    $viewer = User::factory()->create();
    $group = Group::factory()->open()->create([
        'slug' => 'teamdtl',
    ]);
    $admin = User::factory()->create([
        'name' => 'Helpful Admin',
    ]);
    $moderator = User::factory()->create([
        'name' => 'Helpful Mod',
    ]);
    $member = User::factory()->create([
        'name' => 'Normal Member',
    ]);

    $group->memberships()->create([
        'user_id' => $admin->id,
        'role' => GroupMembership::ROLE_ADMIN,
        'joined_at' => now()->subDays(12),
    ]);
    $group->memberships()->create([
        'user_id' => $moderator->id,
        'role' => GroupMembership::ROLE_MODERATOR,
        'joined_at' => now()->subDays(10),
    ]);
    $group->memberships()->create([
        'user_id' => $member->id,
        'role' => GroupMembership::ROLE_MEMBER,
        'joined_at' => now()->subDays(5),
    ]);

    Character::factory()->primary()->create([
        'user_id' => $group->owner_id,
        'name' => 'Owner Main',
        'avatar_url' => 'https://example.com/owner-main.png',
    ]);
    Character::factory()->primary()->create([
        'user_id' => $admin->id,
        'name' => 'Admin Main',
        'avatar_url' => 'https://example.com/admin-main.png',
    ]);

    $this->actingAs($viewer)
        ->getJson(route('groups.details', $group))
        ->assertOk()
        ->assertJsonCount(3, 'data.team_members')
        ->assertJsonPath('data.team_members.0.role', GroupMembership::ROLE_OWNER)
        ->assertJsonPath('data.team_members.0.id', $group->owner_id)
        ->assertJsonPath('data.team_members.0.name', 'Owner Main')
        ->assertJsonPath('data.team_members.0.avatar_url', 'https://example.com/owner-main.png')
        ->assertJsonPath('data.team_members.1.role', GroupMembership::ROLE_ADMIN)
        ->assertJsonPath('data.team_members.1.id', $admin->id)
        ->assertJsonPath('data.team_members.1.name', 'Admin Main')
        ->assertJsonPath('data.team_members.1.avatar_url', 'https://example.com/admin-main.png')
        ->assertJsonPath('data.team_members.2.role', GroupMembership::ROLE_MODERATOR)
        ->assertJsonPath('data.team_members.2.id', $moderator->id)
        ->assertJsonPath('data.team_members.2.name', 'Helpful Mod');
});

it('returns member interaction state for discovery details', function () {
    $member = User::factory()->create();
    $group = Group::factory()
        ->open()
        ->withMember($member)
        ->create([
            'slug' => 'memstate',
        ]);
    $group->memberships()
        ->where('user_id', $member->id)
        ->update(['notifications_enabled' => false]);

    $this->actingAs($member)
        ->getJson(route('groups.details', $group))
        ->assertOk()
        ->assertJsonPath('data.current_user_role', GroupMembership::ROLE_MEMBER)
        ->assertJsonPath('data.links.dashboard', route('groups.dashboard', $group, false))
        ->assertJsonPath('data.notifications.enabled', false)
        ->assertJsonPath('data.permissions.can_join', false)
        ->assertJsonPath('data.permissions.can_leave', true)
        ->assertJsonPath('data.permissions.can_toggle_notifications', true);
});
