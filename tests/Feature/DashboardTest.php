<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlotAssignment;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\DiscordUserIntegration;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\NotificationEvent;
use App\Models\PhantomJob;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the home banner eagerly and loads home activity data as deferred props', function () {
    $user = User::factory()->create([
        'name' => 'Dashboard User',
    ]);

    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
        'name' => 'Meteor Survivor',
    ]);
    $displayClass = CharacterClass::create([
        'name' => 'Astrologian',
        'shorthand' => 'AST',
        'role' => 'healer',
        'icon_url' => '/role-icons/healer.png',
        'flaticon_url' => '/role-icons/healer-flat.png',
    ]);
    $character->classes()->attach($displayClass->id, [
        'level' => 100,
        'is_preferred' => true,
    ]);
    $phantomJob = PhantomJob::create([
        'name' => 'Phantom Geomancer',
        'max_level' => 100,
        'icon_url' => '/seed-data/phantom-jobs/icons/phantom-geomancer.png',
        'transparent_icon_url' => '/seed-data/phantom-jobs/transparent-icons/phantom-geomancer.png',
    ]);
    $user->homeProfile()->create([
        'display_character_class_id' => $displayClass->id,
        'description' => 'The stars guide the party.',
        'background_image_url' => '/storage/home-profiles/banner.jpg',
    ]);

    $user->socialAccounts()->create([
        'provider' => 'discord',
        'provider_user_id' => 'discord-1',
        'provider_name' => 'Discord User',
    ]);
    DiscordUserIntegration::query()->create([
        'user_id' => $user->id,
        'discord_user_id' => 'discord-1',
        'username' => 'Discord User',
        'user_app_installed_at' => now(),
    ]);
    $user->socialAccounts()->create([
        'provider' => 'xivauth',
        'provider_user_id' => 'xiv-1',
        'provider_name' => 'XIVAuth User',
    ]);

    $ownedGroup = Group::factory()->open()->create([
        'owner_id' => $user->id,
        'name' => 'Owned Group',
    ]);

    $moderatedGroup = Group::factory()->open()->create([
        'name' => 'Moderated Group',
    ]);
    $moderatedGroup->memberships()->create([
        'user_id' => $user->id,
        'role' => GroupMembership::ROLE_MODERATOR,
        'joined_at' => now()->subDays(2),
    ]);

    $memberGroup = Group::factory()->open()->create([
        'name' => 'Member Group',
    ]);
    $memberGroup->memberships()->create([
        'user_id' => $user->id,
        'role' => GroupMembership::ROLE_MEMBER,
        'joined_at' => now()->subDay(),
    ]);

    $type = ActivityType::factory()->create([
        'slug' => 'criterion-night',
    ]);
    $version = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $type->id,
        'name' => ['en' => 'Criterion Night'],
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
        'progress_schema' => [
            'milestones' => [
                [
                    'key' => 'phase_3',
                    'label' => ['en' => 'Phase 3'],
                    'order' => 1,
                ],
            ],
        ],
        'prog_points' => [
            [
                'key' => 'phase_3',
                'label' => ['en' => 'Phase 3'],
            ],
        ],
        'small_image_url' => '/prereqimages/criterion-night.png',
        'bench_size' => 0,
    ]);
    $type->update([
        'current_published_version_id' => $version->id,
    ]);

    $approvedActivity = Activity::factory()->create([
        'group_id' => $memberGroup->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $version->id,
        'status' => Activity::STATUS_ASSIGNED,
        'title' => 'Friday Prog',
        'starts_at' => now()->addHours(2),
    ]);

    $pendingActivity = Activity::factory()->create([
        'group_id' => $moderatedGroup->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $version->id,
        'status' => Activity::STATUS_DRAFT,
        'title' => 'Saturday Fill',
        'starts_at' => now()->addDays(2),
    ]);

    $completeActivity = Activity::factory()->complete()->create([
        'group_id' => $ownedGroup->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $version->id,
        'title' => 'Last Week Clear',
        'starts_at' => now()->subDays(8),
        'completed_at' => now()->subDays(8)->addHours(2),
        'furthest_progress_key' => 'phase_3',
        'furthest_progress_percent' => 76,
    ]);

    $approvedApplication = ActivityApplication::factory()->approved($memberGroup->owner)->create([
        'activity_id' => $approvedActivity->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
        'submitted_at' => now()->subHours(5),
    ]);

    $pendingApplication = ActivityApplication::factory()->create([
        'activity_id' => $pendingActivity->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
        'submitted_at' => now()->subHour(),
    ]);

    $completedApplication = ActivityApplication::factory()->approved($ownedGroup->owner)->create([
        'activity_id' => $completeActivity->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
        'submitted_at' => now()->subDays(8),
    ]);
    ActivitySlotAssignment::create([
        'activity_id' => $completeActivity->id,
        'group_id' => $ownedGroup->id,
        'activity_slot_id' => $completeActivity->slots()->value('id'),
        'character_id' => $character->id,
        'application_id' => $completedApplication->id,
        'field_values_snapshot' => [
            'character_class' => [
                'id' => $displayClass->id,
                'name' => 'Astrologian',
                'shorthand' => 'AST',
                'role' => 'healer',
            ],
            'phantom_job' => [
                'id' => $phantomJob->id,
                'name' => 'Phantom Geomancer',
            ],
        ],
        'attendance_status' => ActivitySlotAssignment::STATUS_CHECKED_IN,
        'assigned_at' => now()->subDays(8),
    ]);

    $this->actingAs($user);

    $response = $this->get(route('profile.show', ['user' => $user->id]));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile/Show')
            ->where('profile.name', 'Dashboard User')
            ->where('profile.primary_character.name', 'Meteor Survivor')
            ->where('profile.home_profile.description', 'The stars guide the party.')
            ->where('profile.home_profile.background_image_url', '/storage/home-profiles/banner.jpg')
            ->where('profile.home_profile.display_job.name', 'Astrologian')
            ->where('homeBanner.character.name', 'Meteor Survivor')
            ->where('homeBanner.character.display_job.name', 'Astrologian')
            ->where('homeBanner.character.display_job_level', 100)
            ->has('homeProfileOptions.character_classes', 1)
            ->where('homeProfileOptions.character_classes.0.name', 'Astrologian')
            ->missing('homeBannerDetails')
            ->missing('homeActivityOverview')
            ->missing('homeAccountCompletion')
            ->loadDeferredProps('home-banner-details', fn (Assert $page) => $page
                ->where('homeBannerDetails.last_run.activity_title', 'Last Week Clear')
                ->where('homeBannerDetails.last_run.activity_type_name.en', 'Criterion Night')
                ->where('homeBannerDetails.last_run.activity_icon_url', '/prereqimages/criterion-night.png')
                ->where('homeBannerDetails.last_run.progress', 76)
                ->where('homeBannerDetails.last_run.progress_label.en', 'Phase 3')
                ->where('homeBannerDetails.last_run.class_name', 'Astrologian')
                ->where('homeBannerDetails.last_run.class_icon_url', '/role-icons/healer.png')
                ->where('homeBannerDetails.last_run.phantom_job_name', 'Phantom Geomancer')
                ->where('homeBannerDetails.last_run.phantom_job_icon_url', '/seed-data/phantom-jobs/transparent-icons/phantom-geomancer.png')
                ->where('homeBannerDetails.next_run.activity_id', $approvedActivity->id)
                ->where('homeBannerDetails.next_run.activity_title', 'Friday Prog')
                ->where('homeBannerDetails.next_run.activity_type_name.en', 'Criterion Night')
                ->where('homeBannerDetails.next_run.group.slug', $memberGroup->slug)
                ->where('homeBannerDetails.weekly_participation', fn ($weeks) => $weeks->count() === 14
                    && collect($weeks)->sum('count') >= 1)
            )
            ->loadDeferredProps('home-activity-overview', fn (Assert $page) => $page
                ->has('homeActivityOverview.runs', 2)
                ->where('homeActivityOverview.runs.0.activity_id', $approvedActivity->id)
                ->where('homeActivityOverview.runs.0.status_key', 'confirmed')
                ->where('homeActivityOverview.runs.0.activity_type_name.en', 'Criterion Night')
                ->where('homeActivityOverview.runs.1.activity_id', $completeActivity->id)
                ->where('homeActivityOverview.runs.1.status_key', 'completed')
                ->has('homeActivityOverview.applications', 3)
                ->where('homeActivityOverview.applications.0.id', $pendingApplication->id)
                ->where('homeActivityOverview.applications.0.status_key', 'pending')
                ->has('homeActivityOverview.groups', 3)
                ->has('homeActivityOverview.notifications', 0)
            )
            ->loadDeferredProps('home-account-completion', fn (Assert $page) => $page
                ->where('homeAccountCompletion.percent', 83)
                ->where('homeAccountCompletion.completed_count', 5)
                ->where('homeAccountCompletion.total_count', 6)
                ->has('homeAccountCompletion.items', 6)
                ->where('homeAccountCompletion.items.0.key', 'email_verified')
                ->where('homeAccountCompletion.items.0.is_complete', true)
                ->where('homeAccountCompletion.items.1.key', 'verified_character')
                ->where('homeAccountCompletion.items.1.is_complete', true)
                ->where('homeAccountCompletion.items.2.key', 'primary_character')
                ->where('homeAccountCompletion.items.2.is_complete', true)
                ->where('homeAccountCompletion.items.3.key', 'joined_group')
                ->where('homeAccountCompletion.items.3.is_complete', true)
                ->where('homeAccountCompletion.items.4.key', 'connected_discord')
                ->where('homeAccountCompletion.items.4.is_complete', true)
                ->where('homeAccountCompletion.items.5.key', 'notification_preferences_reviewed')
                ->where('homeAccountCompletion.items.5.is_complete', false)
                ->where('homeAccountCompletion.should_celebrate_completion', false)
            )
        );
});

it('shows the next run blob for a manual roster assignment', function () {
    $user = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
    ]);
    $group = Group::factory()->open()->create();
    $type = ActivityType::factory()->create([
        'slug' => 'map-night',
    ]);
    $version = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $type->id,
        'name' => ['en' => 'Map Night'],
        'layout_schema' => [
            'groups' => [
                [
                    'key' => 'party-a',
                    'label' => ['en' => 'Party A'],
                    'size' => 1,
                ],
            ],
        ],
        'slot_schema' => [],
        'progress_schema' => ['milestones' => []],
        'bench_size' => 0,
    ]);
    $type->update([
        'current_published_version_id' => $version->id,
    ]);
    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $version->id,
        'status' => Activity::STATUS_ASSIGNED,
        'title' => 'Treasure Friday',
        'starts_at' => now()->addHours(3),
    ]);
    $slot = $activity->slots()->firstOrFail();
    $slot->update([
        'assigned_character_id' => $character->id,
        'assigned_by_user_id' => $group->owner_id,
    ]);
    ActivitySlotAssignment::create([
        'activity_id' => $activity->id,
        'group_id' => $group->id,
        'activity_slot_id' => $slot->id,
        'character_id' => $character->id,
        'application_id' => null,
        'field_values_snapshot' => [],
        'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
        'assigned_at' => now(),
        'assigned_by_user_id' => $group->owner_id,
    ]);

    $this->actingAs($user);

    $this->get(route('profile.show', ['user' => $user->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile/Show')
            ->missing('homeBannerDetails')
            ->missing('homeActivityOverview')
            ->loadDeferredProps('home-banner-details', fn (Assert $page) => $page
                ->where('homeBannerDetails.next_run.activity_id', $activity->id)
                ->where('homeBannerDetails.next_run.activity_title', 'Treasure Friday')
                ->where('homeBannerDetails.next_run.activity_type_name.en', 'Map Night')
                ->where('homeBannerDetails.next_run.group.slug', $group->slug)
            )
            ->loadDeferredProps('home-activity-overview', fn (Assert $page) => $page
                ->has('homeActivityOverview.runs', 1)
                ->where('homeActivityOverview.runs.0.activity_id', $activity->id)
                ->where('homeActivityOverview.runs.0.status_key', 'confirmed')
            )
        );
});

it('shows recent notifications in the home activity overview', function () {
    $user = User::factory()->create();
    $olderEvent = NotificationEvent::query()->create([
        'type' => 'system.announcement',
        'category' => 'system_notices',
        'title_key' => 'notifications.system.announcement.title',
        'body_key' => 'notifications.system.announcement.body',
    ]);
    $newerEvent = NotificationEvent::query()->create([
        'type' => 'applications.new_for_review',
        'category' => 'applications',
        'title_key' => 'notifications.applications.new_for_review.title',
        'body_key' => 'notifications.applications.new_for_review.body',
        'message_params' => [
            'count' => 1,
            'activity' => 'Friday Prog',
        ],
    ]);

    UserNotification::query()->create([
        'notification_event_id' => $olderEvent->id,
        'user_id' => $user->id,
    ])->forceFill([
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ])->save();

    $newerNotification = UserNotification::query()->create([
        'notification_event_id' => $newerEvent->id,
        'user_id' => $user->id,
    ]);

    $this->actingAs($user);

    $this->get(route('profile.show', ['user' => $user->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile/Show')
            ->missing('homeActivityOverview')
            ->loadDeferredProps('home-activity-overview', fn (Assert $page) => $page
                ->has('homeActivityOverview.notifications', 2)
                ->where('homeActivityOverview.notifications.0.id', "user:{$newerNotification->id}")
                ->where('homeActivityOverview.notifications.0.type', 'applications.new_for_review')
                ->where('homeActivityOverview.notifications.0.category', 'applications')
                ->where('homeActivityOverview.notifications.0.message_params.activity', 'Friday Prog')
            )
        );
});

it('does not use future run timestamps for recent group history', function () {
    $user = User::factory()->create();
    $pastRunUpdatedAt = now()->subDays(2);
    $futureRunUpdatedAt = now()->addMonths(3);
    $group = Group::factory()->open()->create([
        'updated_at' => now()->subDays(10),
    ]);
    $group->memberships()->create([
        'user_id' => $user->id,
        'role' => GroupMembership::ROLE_MEMBER,
        'joined_at' => now()->subDays(10),
    ]);

    $type = ActivityType::factory()->create([
        'slug' => 'future-proof-history',
    ]);
    $version = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $type->id,
        'name' => ['en' => 'Future Proof History'],
        'layout_schema' => [
            'groups' => [
                [
                    'key' => 'party-a',
                    'label' => ['en' => 'Party A'],
                    'size' => 1,
                ],
            ],
        ],
        'slot_schema' => [],
        'progress_schema' => ['milestones' => []],
        'bench_size' => 0,
    ]);

    Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $user->id,
        'status' => Activity::STATUS_SCHEDULED,
        'starts_at' => now()->addWeeks(2),
        'created_at' => $pastRunUpdatedAt,
        'updated_at' => $pastRunUpdatedAt,
    ]);

    Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $user->id,
        'status' => Activity::STATUS_SCHEDULED,
        'starts_at' => now()->addMonths(3),
        'created_at' => $futureRunUpdatedAt,
        'updated_at' => $futureRunUpdatedAt,
    ]);

    $this->actingAs($user);

    $this->get(route('profile.show', ['user' => $user->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile/Show')
            ->missing('homeActivityOverview')
            ->loadDeferredProps('home-activity-overview', fn (Assert $page) => $page
                ->has('homeActivityOverview.groups', 1)
                ->where('homeActivityOverview.groups.0.id', $group->id)
                ->where('homeActivityOverview.groups.0.last_activity_key', 'run_created')
                ->where('homeActivityOverview.groups.0.last_activity_at', $pastRunUpdatedAt->toIso8601String())
            )
        );
});

it('shows recent groups only when the user participated in their history', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
    ]);
    $otherCharacter = Character::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    $joinedGroup = Group::factory()->open()->create([
        'name' => 'Joined Group',
    ]);
    $joinedGroup->memberships()->create([
        'user_id' => $user->id,
        'role' => GroupMembership::ROLE_MEMBER,
        'joined_at' => now()->subDays(5),
    ]);

    $otherHistoryGroup = Group::factory()->open()->create([
        'name' => 'Other History Group',
    ]);
    $otherHistoryGroup->memberships()->create([
        'user_id' => $user->id,
        'role' => GroupMembership::ROLE_MEMBER,
        'joined_at' => now()->subDays(5),
    ]);

    $type = ActivityType::factory()->create([
        'slug' => 'participation-history',
    ]);
    $version = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $type->id,
        'name' => ['en' => 'Participation History'],
        'layout_schema' => [
            'groups' => [
                [
                    'key' => 'party-a',
                    'label' => ['en' => 'Party A'],
                    'size' => 1,
                ],
            ],
        ],
        'slot_schema' => [],
        'progress_schema' => ['milestones' => []],
        'bench_size' => 0,
    ]);

    $joinedActivity = Activity::factory()->create([
        'group_id' => $joinedGroup->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $joinedGroup->owner_id,
        'status' => Activity::STATUS_ASSIGNED,
        'starts_at' => now()->subDay(),
    ]);
    $joinedSlot = $joinedActivity->slots()->firstOrFail();
    $joinedSlot->update([
        'assigned_character_id' => $character->id,
        'assigned_by_user_id' => $joinedGroup->owner_id,
    ]);
    ActivitySlotAssignment::create([
        'activity_id' => $joinedActivity->id,
        'group_id' => $joinedGroup->id,
        'activity_slot_id' => $joinedSlot->id,
        'character_id' => $character->id,
        'application_id' => null,
        'field_values_snapshot' => [],
        'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
        'assigned_at' => now()->subHour(),
        'assigned_by_user_id' => $joinedGroup->owner_id,
    ]);

    $otherActivity = Activity::factory()->create([
        'group_id' => $otherHistoryGroup->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $otherUser->id,
        'status' => Activity::STATUS_ASSIGNED,
        'starts_at' => now()->subDay(),
    ]);
    ActivitySlotAssignment::create([
        'activity_id' => $otherActivity->id,
        'group_id' => $otherHistoryGroup->id,
        'activity_slot_id' => $otherActivity->slots()->value('id'),
        'character_id' => $otherCharacter->id,
        'application_id' => null,
        'field_values_snapshot' => [],
        'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
        'assigned_at' => now()->subMinutes(10),
        'assigned_by_user_id' => $otherUser->id,
    ]);

    $this->actingAs($user);

    $this->get(route('profile.show', ['user' => $user->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile/Show')
            ->missing('homeActivityOverview')
            ->loadDeferredProps('home-activity-overview', fn (Assert $page) => $page
                ->has('homeActivityOverview.groups', 1)
                ->where('homeActivityOverview.groups.0.id', $joinedGroup->id)
                ->where('homeActivityOverview.groups.0.last_activity_key', 'run_joined')
            )
        );
});

it('shows the last run recap for a completed manual roster assignment', function () {
    $user = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
    ]);
    $displayClass = CharacterClass::create([
        'name' => 'Astrologian',
        'shorthand' => 'AST',
        'role' => 'healer',
        'icon_url' => '/role-icons/healer.png',
    ]);
    $phantomJob = PhantomJob::create([
        'name' => 'Phantom Geomancer',
        'max_level' => 100,
        'icon_url' => '/seed-data/phantom-jobs/icons/phantom-geomancer.png',
        'transparent_icon_url' => '/seed-data/phantom-jobs/transparent-icons/phantom-geomancer.png',
    ]);
    $group = Group::factory()->open()->create();
    $type = ActivityType::factory()->create([
        'slug' => 'forked-tower',
    ]);
    $version = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $type->id,
        'name' => ['en' => 'Forked Tower'],
        'small_image_url' => '/prereqimages/forked-tower.png',
        'layout_schema' => [
            'groups' => [
                [
                    'key' => 'party-a',
                    'label' => ['en' => 'Party A'],
                    'size' => 1,
                ],
            ],
        ],
        'slot_schema' => [],
        'progress_schema' => [
            'milestones' => [
                ['key' => 'demon-tablet', 'label' => ['en' => 'Demon Tablet'], 'order' => 1],
            ],
        ],
        'prog_points' => [
            ['key' => 'demon-tablet', 'label' => ['en' => 'Demon Tablet'], 'order' => 1],
        ],
        'bench_size' => 0,
    ]);
    $type->update([
        'current_published_version_id' => $version->id,
    ]);
    $activity = Activity::factory()->complete()->create([
        'group_id' => $group->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $version->id,
        'status' => Activity::STATUS_COMPLETE,
        'title' => 'Chaotic Climb',
        'starts_at' => now()->subDay(),
        'completed_at' => now()->subDay()->addHours(2),
        'furthest_progress_key' => 'demon-tablet',
        'furthest_progress_percent' => 67,
    ]);
    $slot = $activity->slots()->firstOrFail();
    $slot->update([
        'assigned_character_id' => $character->id,
        'assigned_by_user_id' => $group->owner_id,
    ]);

    ActivitySlotAssignment::create([
        'activity_id' => $activity->id,
        'group_id' => $group->id,
        'activity_slot_id' => $slot->id,
        'character_id' => $character->id,
        'application_id' => null,
        'assignment_source' => ActivitySlotAssignment::SOURCE_MANUAL,
        'field_values_snapshot' => [
            'character_class' => [
                'id' => $displayClass->id,
                'name' => 'Astrologian',
                'shorthand' => 'AST',
                'role' => 'healer',
            ],
            'phantom_job' => [
                'id' => $phantomJob->id,
                'name' => 'Phantom Geomancer',
            ],
        ],
        'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
        'assigned_at' => now()->subDay(),
        'assigned_by_user_id' => $group->owner_id,
    ]);

    $this->actingAs($user);

    $this->get(route('profile.show', ['user' => $user->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile/Show')
            ->missing('homeBannerDetails')
            ->loadDeferredProps('home-banner-details', fn (Assert $page) => $page
                ->where('homeBannerDetails.last_run.activity_title', 'Chaotic Climb')
                ->where('homeBannerDetails.last_run.activity_type_name.en', 'Forked Tower')
                ->where('homeBannerDetails.last_run.activity_icon_url', '/prereqimages/forked-tower.png')
                ->where('homeBannerDetails.last_run.progress', 67)
                ->where('homeBannerDetails.last_run.progress_label.en', 'Demon Tablet')
                ->where('homeBannerDetails.last_run.class_name', 'Astrologian')
                ->where('homeBannerDetails.last_run.class_icon_url', '/role-icons/healer.png')
                ->where('homeBannerDetails.last_run.phantom_job_name', 'Phantom Geomancer')
                ->where('homeBannerDetails.last_run.phantom_job_icon_url', '/seed-data/phantom-jobs/transparent-icons/phantom-geomancer.png')
            )
        );
});

it('updates home profile customization', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $displayClass = CharacterClass::create([
        'name' => 'Scholar',
        'shorthand' => 'SCH',
        'role' => 'healer',
    ]);

    $this->actingAs($user);

    $response = $this->post(route('profile.update', ['user' => $user->id]), [
        '_method' => 'put',
        'display_character_class_id' => $displayClass->id,
        'description' => 'Ready when the queue pops.',
        'background_image' => UploadedFile::fake()->image('home-banner.jpg', 1200, 500),
    ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('user_home_profiles', [
        'user_id' => $user->id,
        'display_character_class_id' => $displayClass->id,
        'description' => 'Ready when the queue pops.',
    ]);

    $homeProfile = $user->fresh()->homeProfile;

    $this->assertStringStartsWith('/storage/home-profiles/', $homeProfile->background_image_url);
    expect($homeProfile->background_image_url)->toEndWith('.webp');

    $storedBackgroundPath = str_replace('/storage/', '', $homeProfile->background_image_url);
    Storage::disk('public')->assertExists($storedBackgroundPath);

    $backgroundImageInfo = getimagesizefromstring(Storage::disk('public')->get($storedBackgroundPath));

    expect($backgroundImageInfo['mime'] ?? null)->toBe('image/webp');
});

it('resets home profile background image', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    Storage::disk('public')->put('home-profiles/existing.jpg', 'fake image');

    $user->homeProfile()->create([
        'description' => 'Keep this bio.',
        'background_image_url' => '/storage/home-profiles/existing.jpg',
    ]);

    $this->actingAs($user);

    $response = $this->post(route('profile.update', ['user' => $user->id]), [
        '_method' => 'put',
        'description' => 'Keep this bio.',
        'reset_background_image' => '1',
    ]);

    $response->assertRedirect();

    expect($user->fresh()->homeProfile->background_image_url)->toBeNull();
    Storage::disk('public')->assertMissing('home-profiles/existing.jpg');
});

it('deletes the old home profile background image when replacing it', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    Storage::disk('public')->put('home-profiles/existing.jpg', 'fake image');

    $user->homeProfile()->create([
        'description' => 'Keep this bio.',
        'background_image_url' => '/storage/home-profiles/existing.jpg',
    ]);

    $this->actingAs($user);

    $response = $this->post(route('profile.update', ['user' => $user->id]), [
        '_method' => 'put',
        'description' => 'Keep this bio.',
        'background_image' => UploadedFile::fake()->image('replacement.jpg', 1200, 500),
    ]);

    $response->assertRedirect();

    $homeProfile = $user->fresh()->homeProfile;

    expect($homeProfile->background_image_url)->not->toBe('/storage/home-profiles/existing.jpg');
    $this->assertStringStartsWith('/storage/home-profiles/', $homeProfile->background_image_url);
    expect($homeProfile->background_image_url)->toEndWith('.webp');
    Storage::disk('public')->assertMissing('home-profiles/existing.jpg');
});

it('rejects home profile biography longer than 255 characters', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = $this->post(route('profile.update', ['user' => $user->id]), [
        '_method' => 'put',
        'description' => str_repeat('a', 256),
    ]);

    $response->assertSessionHasErrors('description');

    $this->assertDatabaseMissing('user_home_profiles', [
        'user_id' => $user->id,
    ]);
});

it('does not expose a home banner display job without a primary character', function () {
    $user = User::factory()->create([
        'name' => 'No Character User',
    ]);
    $displayClass = CharacterClass::create([
        'name' => 'Scholar',
        'shorthand' => 'SCH',
        'role' => 'healer',
    ]);
    $user->homeProfile()->create([
        'display_character_class_id' => $displayClass->id,
    ]);

    $this->actingAs($user);

    $this->get(route('profile.show', ['user' => $user->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile/Show')
            ->where('homeBanner.character.id', null)
            ->where('homeBanner.character.name', 'No Character User')
            ->where('homeBanner.character.world', null)
            ->where('homeBanner.character.datacenter', null)
            ->where('homeBanner.character.display_job', null)
            ->where('homeBanner.character.display_job_level', null)
            ->where('profile.home_profile.display_job.name', 'Scholar')
        );
});

it('marks notification preferences reviewed when the settings page is opened', function () {
    $user = User::factory()->create([
        'notification_preferences_reviewed_at' => null,
    ]);

    $this->actingAs($user);

    $this->get(route('settings'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Settings/Index')
            ->where('flash.success', ['notification_preferences_reviewed'])
        );

    expect($user->fresh()->notification_preferences_reviewed_at)->not->toBeNull();
});

it('counts discord account setup only after the discord app is installed', function () {
    $user = User::factory()->create([
        'notification_preferences_reviewed_at' => now(),
    ]);

    Character::factory()->primary()->create([
        'user_id' => $user->id,
    ]);

    $user->socialAccounts()->create([
        'provider' => 'discord',
        'provider_user_id' => 'discord-login-only',
        'provider_name' => 'Discord Login User',
    ]);

    DiscordUserIntegration::query()->create([
        'user_id' => $user->id,
        'discord_user_id' => 'discord-app-missing',
        'username' => 'Discord Login User',
        'user_app_installed_at' => null,
    ]);

    Group::factory()->open()->create([
        'owner_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('profile.show', ['user' => $user->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile/Show')
            ->loadDeferredProps('home-account-completion', fn (Assert $page) => $page
                ->where('homeAccountCompletion.completed_count', 5)
                ->where('homeAccountCompletion.total_count', 6)
                ->where('homeAccountCompletion.items.4.key', 'connected_discord')
                ->where('homeAccountCompletion.items.4.is_complete', false)
            )
        );
});

it('marks account completion celebration only once when all steps are complete', function () {
    $user = User::factory()->create([
        'notification_preferences_reviewed_at' => now(),
        'account_completion_celebrated_at' => null,
    ]);

    Character::factory()->primary()->create([
        'user_id' => $user->id,
    ]);

    DiscordUserIntegration::query()->create([
        'user_id' => $user->id,
        'discord_user_id' => 'discord-1',
        'username' => 'Discord User',
        'user_app_installed_at' => now(),
    ]);

    Group::factory()->open()->create([
        'owner_id' => $user->id,
    ]);

    $this->actingAs($user);

    $this->get(route('profile.show', ['user' => $user->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile/Show')
            ->missing('homeAccountCompletion')
            ->loadDeferredProps('home-account-completion', fn (Assert $page) => $page
                ->where('homeAccountCompletion.percent', 100)
                ->where('homeAccountCompletion.completed_count', 6)
                ->where('homeAccountCompletion.total_count', 6)
                ->where('homeAccountCompletion.should_celebrate_completion', true)
            )
        );

    expect($user->fresh()->account_completion_celebrated_at)->not->toBeNull();

    $this->get(route('profile.show', ['user' => $user->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile/Show')
            ->loadDeferredProps('home-account-completion', fn (Assert $page) => $page
                ->where('homeAccountCompletion.percent', 100)
                ->where('homeAccountCompletion.should_celebrate_completion', false)
            )
        );
});
