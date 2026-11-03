<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivityTypeVersion;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\NotificationTopic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

it('scopes hosted runs and existing application alerts to the current staff member and group', function () {
    $this->travelTo(now()->setDate(2026, 11, 3)->setTime(12, 0));
    $host = User::factory()->create();
    $group = Group::factory()->open()->create();
    $membership = $group->memberships()->create([
        'user_id' => $host->id,
        'role' => GroupMembership::ROLE_MODERATOR,
        'joined_at' => now(),
    ]);
    $factory = Activity::factory()->state([
        'group_id' => $group->id,
        'organized_by_user_id' => $host->id,
        'status' => Activity::STATUS_SCHEDULED,
        'starts_at' => now()->addDays(10),
    ]);
    $future = $factory->create();
    $ongoing = $factory->create(['status' => Activity::STATUS_ONGOING, 'starts_at' => now()->subHour()]);
    $othersRun = $factory->create(['organized_by_user_id' => $group->owner_id]);
    $otherGroupRun = $factory->create(['group_id' => Group::factory()->create()->id]);
    $draft = $factory->create(['status' => Activity::STATUS_DRAFT, 'starts_at' => null]);
    foreach ([Activity::STATUS_COMPLETE, Activity::STATUS_CANCELLED] as $status) {
        $factory->create(['status' => $status]);
    }
    $unfinished = $factory->create(['starts_at' => now()->subDay()]);
    $factory->create(['is_completed' => true]);

    $createUpdate = function (Activity $run, User $recipient, string $type, ?int $groupId = null): UserNotification {
        $event = NotificationEvent::query()->create([
            'type' => $type,
            'category' => NotificationCategory::APPLICATIONS,
            'topic' => $type === 'applications.new_for_review'
                ? NotificationTopic::APPLICATIONS_REVIEW : NotificationTopic::APPLICATIONS_HOST_UPDATES,
            'group_id' => $groupId ?? $run->group_id,
            'title_key' => 'notifications.applications.updated.title',
            'body_key' => 'notifications.applications.updated.body',
            'message_params' => ['activity' => $run->title, 'character' => 'Test Applicant'],
            'payload' => ['activity_id' => $run->id],
        ]);

        return UserNotification::query()->create([
            'notification_event_id' => $event->id,
            'user_id' => $recipient->id,
        ]);
    };
    $submitted = $createUpdate($future, $host, 'applications.new_for_review');
    $submitted->forceFill(['aggregate_count' => 10, 'aggregate_key' => "applications.new_for_review.activity.{$future->id}"])->save();
    $updated = $createUpdate($ongoing, $host, 'applications.updated');
    $withdrawn = $createUpdate($future, $host, 'applications.withdrawn_for_review');
    $createUpdate($othersRun, $host, 'applications.updated');
    $createUpdate($otherGroupRun, $host, 'applications.updated');
    $createUpdate($future, $group->owner, 'applications.updated');
    $createUpdate($future, $host, 'applications.updated', $otherGroupRun->group_id);
    $totalNotifications = UserNotification::query()->count();

    $this->actingAs($host)->get(route('groups.dashboard', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('group.applied_activities', 0)
            ->has('group.hosted_activities', 4)
            ->where('group.hosted_activities.0.id', $unfinished->id)
            ->where('group.hosted_activities.1.id', $ongoing->id)
            ->where('group.hosted_activities.2.id', $future->id)
            ->where('group.hosted_activities.3.id', $draft->id)
            ->where('group.hosted_activities.2.links.view', route('groups.dashboard.activities.show', [$group, $future], false))
            ->has('group.hosted_run_notifications', 3)
            ->where('group.hosted_run_notifications.0.id', $withdrawn->id)
            ->where('group.hosted_run_notifications.1.id', $updated->id)
            ->where('group.hosted_run_notifications.2.id', $submitted->id)
            ->where('group.hosted_run_notifications.2.aggregate_count', 10)
            ->where('group.hosted_run_notifications.2.is_unread', true)
        );

    expect(UserNotification::query()->count())->toBe($totalNotifications)
        ->and($submitted->fresh()->read_at)->toBeNull();

    $membership->update(['role' => GroupMembership::ROLE_MEMBER]);
    $this->get(route('groups.dashboard', $group))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('group.hosted_activities', 0)
            ->has('group.hosted_run_notifications', 0)
        );
});

it('provides the full calendar window across timezone boundaries while preserving run visibility', function () {
    $this->travelTo(now()->setDate(2026, 11, 2)->setTime(0, 30));
    $group = Group::factory()->open()->create();
    $member = User::factory()->create();
    $group->memberships()->create([
        'user_id' => $member->id,
        'role' => GroupMembership::ROLE_MEMBER,
        'joined_at' => now(),
    ]);

    $activityFactory = Activity::factory()->state([
        'group_id' => $group->id,
        'status' => Activity::STATUS_SCHEDULED,
    ]);
    $previousDay = $activityFactory->create(['starts_at' => '2026-11-01 23:30:00']);
    $today = $activityFactory->create(['starts_at' => '2026-11-02 12:00:00']);
    $lastDay = $activityFactory->private()->create(['starts_at' => '2026-11-09 06:00:00']);
    $activityFactory->create(['starts_at' => '2026-10-31 23:59:00']);
    $activityFactory->create(['starts_at' => '2026-11-10 00:00:00']);
    $activityFactory->create(['starts_at' => null]);
    $activityFactory->create(['starts_at' => '2026-11-03 12:00:00', 'status' => Activity::STATUS_DRAFT]);

    $this->actingAs($member)->get(route('groups.dashboard', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Groups/CommunityDashboard')
            ->where('group.permissions.can_leave', true)
            ->where('group.permissions.can_toggle_notifications', true)
            ->has('group.calendar_activities', 3)
            ->where('group.calendar_activities.0.id', $previousDay->id)
            ->where('group.calendar_activities.1.id', $today->id)
            ->where('group.calendar_activities.2.id', $lastDay->id)
            ->has('group.current_week_activities', 1)
            ->where('group.upcoming_activities.0.id', $today->id)
            ->where('group.upcoming_activities.0.applicant_avatars', [])
        );

    $outsider = User::factory()->create();
    $this->actingAs($outsider)->get(route('groups.dashboard', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('group.permissions.can_leave', false)
            ->where('group.permissions.can_toggle_notifications', false)
            ->has('group.calendar_activities', 2)
            ->where('group.calendar_activities.0.id', $previousDay->id)
            ->where('group.calendar_activities.1.id', $today->id)
        );
});

it('provides at most three active applicant previews per run without confusing them with the viewers application', function () {
    $viewer = User::factory()->create();
    $group = Group::factory()->open()->withMember($viewer)->create();
    $expectedAvatars = [];
    foreach ([0, 1] as $offset) {
        $run = Activity::factory()->create([
            'group_id' => $group->id,
            'status' => Activity::STATUS_SCHEDULED,
            'starts_at' => now()->addDay()->addHours($offset),
            'needs_application' => true,
        ]);
        foreach (['withdrawn', 'declined', 'cancelled', 'pending', 'approved', 'on_bench', 'pending', 'approved'] as $index => $status) {
            $application = ActivityApplication::factory()->create([
                'activity_id' => $run->id,
                'status' => $status,
                'created_at' => now()->subMinutes(10 - $index),
            ]);
            $avatar = "https://example.test/avatar-{$application->id}.png";
            $application->update(['applicant_avatar_url' => $avatar]);
            if (in_array($index, [3, 4, 5], true)) {
                $expectedAvatars[$run->id][] = $avatar;
            }
        }
    }

    $this->actingAs($viewer)->get(route('groups.dashboard', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('group.applied_activities', 0)
            ->has('group.calendar_activities', 2)
            ->where('group.calendar_activities', function ($runs) use ($expectedAvatars) {
                foreach ($runs as $run) {
                    expect($run['application_count'])->toBe(5)
                        ->and($run['applicant_avatars'])->toBe($expectedAvatars[$run['id']])
                        ->and($run['has_existing_application'])->toBeFalse()
                        ->and($run['participation_status'])->toBeNull()
                        ->and($run['can_apply'])->toBeTrue()
                        ->and($run)->not->toHaveKey('applications');
                }

                return true;
            })
        );
});

it('keeps private profiles and guests anonymous in group run card previews', function (array $privacy) {
    $group = Group::factory()->open()->create();
    $run = Activity::factory()->create([
        'group_id' => $group->id,
        'status' => Activity::STATUS_SCHEDULED,
        'starts_at' => now()->addDay(),
        'is_public' => true,
    ]);
    $applicant = $privacy ? User::factory()->create($privacy) : null;
    $application = ActivityApplication::factory()->create([
        'activity_id' => $run->id,
        'user_id' => $applicant?->id,
    ]);
    $application->update(['applicant_avatar_url' => 'https://example.test/private-avatar.png']);

    $this->get(route('groups.dashboard', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('group.applied_activities', 0)
            ->where('group.calendar_activities.0.application_count', 1)
            ->where('group.calendar_activities.0.applicant_avatars', [null])
            ->where('group.calendar_activities.0.has_existing_application', false)
            ->where('group.calendar_activities.0.participation_status', null)
            ->missing('group.calendar_activities.0.applications')
        );
})->with([
    'private profile' => [['public_profile' => false, 'public_characters' => true]],
    'private characters' => [['public_profile' => true, 'public_characters' => false]],
    'guest' => [[]],
]);

it('tracks the viewers application independently of the three preview applicants', function (string $status) {
    $viewer = User::factory()->create();
    $group = Group::factory()->open()->withMember($viewer)->create();
    $run = Activity::factory()->create([
        'group_id' => $group->id,
        'status' => Activity::STATUS_SCHEDULED,
        'starts_at' => now()->addDay(),
        'needs_application' => true,
    ]);
    ActivityApplication::factory()->count(3)->create([
        'activity_id' => $run->id,
        'status' => ActivityApplication::STATUS_PENDING,
        'created_at' => now()->subHour(),
    ]);
    ActivityApplication::factory()->create([
        'activity_id' => $run->id,
        'user_id' => $viewer->id,
        'status' => $status,
    ]);

    $this->actingAs($viewer)->get(route('groups.dashboard', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('group.applied_activities', in_array($status, ActivityApplication::ACTIVE_STATUSES, true) ? 1 : 0)
            ->has('group.calendar_activities.0.applicant_avatars', 3)
            ->where('group.calendar_activities.0.has_existing_application', $status !== ActivityApplication::STATUS_WITHDRAWN)
            ->where('group.calendar_activities.0.can_apply', $status === ActivityApplication::STATUS_WITHDRAWN)
            ->where('group.calendar_activities.0.participation_status', $status)
        );
})->with(ActivityApplication::STATUSES);

it('lists only the viewers upcoming published participations without the calendar or group preview limits', function () {
    $this->travelTo(now()->setDate(2026, 11, 2)->setTime(12, 0));
    $viewer = User::factory()->create();
    $group = Group::factory()->open()->create(['owner_id' => $viewer->id]);
    $factory = Activity::factory()->state([
        'group_id' => $group->id,
        'activity_type_version_id' => ActivityTypeVersion::factory()->create(['layout_schema' => [], 'application_schema' => []])->id,
        'status' => Activity::STATUS_SCHEDULED,
        'starts_at' => now()->addDay(),
    ]);
    $otherViewer = User::factory()->create();
    foreach ($factory->count(9)->create() as $activity) {
        ActivityApplication::factory()->create(['activity_id' => $activity->id, 'user_id' => $otherViewer->id]);
    }
    $later = $factory->create(['starts_at' => now()->addDays(20), 'status' => Activity::STATUS_ASSIGNED]);
    $earlier = $factory->create(['starts_at' => now()->addDays(10), 'status' => Activity::STATUS_UPCOMING]);
    $excluded = collect([
        $factory->create(['status' => Activity::STATUS_DRAFT]),
        $factory->create(['status' => Activity::STATUS_ONGOING]),
        $factory->create(['status' => Activity::STATUS_COMPLETE]),
        $factory->create(['status' => Activity::STATUS_CANCELLED]),
        $factory->create(['is_completed' => true]),
        $factory->create(['starts_at' => now()->subHour()]),
        $factory->create(['starts_at' => null]),
        $factory->create(['group_id' => Group::factory()->create()->id]),
    ]);
    foreach ($excluded->push($later, $earlier) as $activity) {
        ActivityApplication::factory()->create([
            'activity_id' => $activity->id, 'user_id' => $viewer->id, 'status' => ActivityApplication::STATUS_PENDING,
        ]);
    }

    $this->actingAs($viewer)->get(route('groups.dashboard', $group))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('group.upcoming_activities', 8)
        ->has('group.applied_activities', 2)
        ->where('group.applied_activities.0.id', $earlier->id)
        ->where('group.applied_activities.1.id', $later->id)
        ->where('group.applied_activities.0.participation_status', ActivityApplication::STATUS_PENDING)
        ->missing('group.applied_activities.0.applications'));
});
