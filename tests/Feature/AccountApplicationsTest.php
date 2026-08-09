<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivityManagementWarning;
use App\Models\ActivitySlotAssignment;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\AuditLog;
use App\Models\Character;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function createAccountApplicationsActivity(): Activity
{
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
        'application_schema' => [
            [
                'key' => 'experience',
                'label' => ['en' => 'Experience'],
                'type' => 'textarea',
                'required' => true,
            ],
        ],
    ]);

    $type->update([
        'current_published_version_id' => $version->id,
    ]);

    return Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_SCHEDULED,
        'needs_application' => true,
        'is_public' => true,
    ]);
}

it('shows the authenticated users applications by upcoming run time', function () {
    $activity = createAccountApplicationsActivity();
    $secondActivity = createAccountApplicationsActivity();
    $activity->update(['starts_at' => now()->addDays(3)]);
    $secondActivity->update(['starts_at' => now()->addDay()]);
    $user = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
    ]);

    $later = ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
        'submitted_at' => now()->subDay(),
    ]);

    $latest = ActivityApplication::factory()->create([
        'activity_id' => $secondActivity->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
        'submitted_at' => now(),
    ]);

    $this->actingAs($user);

    $response = $this->get(route('account.applications'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Account/MyApplications')
            ->where('featuredApplication.id', $latest->id)
            ->where('featuredApplication.can_edit', true)
            ->where('featuredApplication.can_withdraw', true)
            ->where('activeApplications.0.id', $later->id)
            ->where('cancelledApplications', [])
            ->where('hasHistoricalApplications', false));
});

it('allows users to withdraw their own pending application', function () {
    $activity = createAccountApplicationsActivity();
    $user = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
    ]);

    $application = ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
        'status' => ActivityApplication::STATUS_PENDING,
    ]);

    $this->actingAs($user);

    $response = $this->delete(route('account.applications.destroy', [
        'application' => $application->id,
    ]));

    $response->assertRedirect(route('account.applications'));
    $application->refresh();

    expect($application->status)->toBe(ActivityApplication::STATUS_WITHDRAWN)
        ->and($application->reviewed_at)->not->toBeNull();

    $auditLog = AuditLog::query()->where('action', 'group.activity.application.withdrawn')->sole();

    expect($auditLog->actor_user_id)->toBe($user->id)
        ->and($auditLog->subject_type)->toBe(User::class)
        ->and($auditLog->subject_id)->toBe($user->id)
        ->and($auditLog->metadata['application_status'])->toBe(ActivityApplication::STATUS_WITHDRAWN);
});

it('allows users to withdraw approved applications and removes them from the roster', function () {
    $activity = createAccountApplicationsActivity();
    $user = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
    ]);

    $application = ActivityApplication::factory()->approved()->create([
        'activity_id' => $activity->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
    ]);

    $slot = $activity->slots()->firstOrFail();
    $slot->update([
        'assigned_character_id' => $character->id,
        'assigned_by_user_id' => $activity->group->owner_id,
    ]);

    $this->actingAs($user);

    $response = $this->delete(route('account.applications.destroy', [
        'application' => $application->id,
    ]));

    $response->assertRedirect(route('account.applications'));

    expect($application->fresh()->status)->toBe(ActivityApplication::STATUS_WITHDRAWN)
        ->and($slot->fresh()->assigned_character_id)->toBeNull()
        ->and(ActivityManagementWarning::query()->doesntExist())->toBeTrue();
});

it('warns moderators when an assigned Party Lead withdraws', function () {
    $activity = createAccountApplicationsActivity();
    $owner = $activity->group->owner;
    $user = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
        'name' => 'Departing Lead',
    ]);

    $application = ActivityApplication::factory()->approved()->create([
        'activity_id' => $activity->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
    ]);

    $slot = $activity->slots()->firstOrFail();
    $slot->update([
        'assigned_character_id' => $character->id,
        'assigned_by_user_id' => $owner->id,
        'is_raid_leader' => true,
    ]);

    $this->actingAs($user)
        ->delete(route('account.applications.destroy', [
            'application' => $application->id,
        ]))
        ->assertRedirect(route('account.applications'));

    $warning = ActivityManagementWarning::query()->sole();

    expect($warning->activity_id)->toBe($activity->id)
        ->and($warning->type)->toBe(ActivityManagementWarning::TYPE_RAID_LEADER_WITHDRAWN)
        ->and($warning->severity)->toBe(ActivityManagementWarning::SEVERITY_ERROR)
        ->and($warning->payload['character_name'])->toBe('Departing Lead')
        ->and($warning->payload['slot_id'])->toBe($slot->id)
        ->and($warning->dismissed_at)->toBeNull();

    $this->actingAs($owner)
        ->getJson(route('groups.dashboard.activities.management-data', [
            'group' => $activity->group->slug,
            'activity' => $activity->id,
        ]))
        ->assertOk()
        ->assertJsonPath('activity.management_warnings.0.id', $warning->id)
        ->assertJsonPath('activity.management_warnings.0.type', ActivityManagementWarning::TYPE_RAID_LEADER_WITHDRAWN)
        ->assertJsonPath('activity.management_warnings.0.payload.character_name', 'Departing Lead');

    $this->actingAs($owner)
        ->deleteJson(route('groups.dashboard.activities.management-warnings.destroy', [
            'group' => $activity->group->slug,
            'activity' => $activity->id,
            'managementWarning' => $warning->id,
        ]))
        ->assertOk()
        ->assertJsonPath('data.id', $warning->id);

    expect($warning->fresh()->dismissed_by_user_id)->toBe($owner->id)
        ->and($warning->fresh()->dismissed_at)->not->toBeNull();

    $this->getJson(route('groups.dashboard.activities.management-data', [
        'group' => $activity->group->slug,
        'activity' => $activity->id,
    ]))
        ->assertOk()
        ->assertJsonPath('activity.management_warnings', []);
});

it('does not allow users to withdraw declined applications', function () {
    $activity = createAccountApplicationsActivity();
    $user = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
    ]);

    $application = ActivityApplication::factory()->declined($activity->group->owner)->create([
        'activity_id' => $activity->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
    ]);

    $this->actingAs($user);

    $response = $this->delete(route('account.applications.destroy', [
        'application' => $application->id,
    ]));

    $response->assertSessionHasErrors(['application']);
    expect($application->fresh()->status)->toBe(ActivityApplication::STATUS_DECLINED);
});

it('shows withdrawn future applications as cancelled until the run time passes', function () {
    $activity = createAccountApplicationsActivity();
    $user = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
    ]);

    $withdrawn = ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
        'status' => ActivityApplication::STATUS_WITHDRAWN,
        'submitted_at' => now()->subHour(),
    ]);

    $this->actingAs($user);

    $response = $this->get(route('account.applications'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Account/MyApplications')
            ->where('featuredApplication', null)
            ->where('activeApplications', [])
            ->where('cancelledApplications.0.id', $withdrawn->id)
            ->where('cancelledApplications.0.status', ActivityApplication::STATUS_WITHDRAWN)
            ->where('hasHistoricalApplications', false));
});

it('includes moderator review reasons in application history', function () {
    $activity = createAccountApplicationsActivity();
    $activity->update(['starts_at' => now()->subHour()]);
    $user = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
    ]);

    $declined = ActivityApplication::factory()->declined($activity->group->owner)->create([
        'activity_id' => $activity->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
        'review_reason' => 'The roster is already final for this run.',
    ]);

    $this->actingAs($user);

    $response = $this->get(route('account.applications'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Account/MyApplications')
            ->where('hasHistoricalApplications', true)
            ->where('cancelledApplications', []));

    $this->getJson(route('account.applications.history'))
        ->assertOk()
        ->assertJsonPath('data.0.id', $declined->id)
        ->assertJsonPath('data.0.review_reason', 'The roster is already final for this run.');
});

it('paginates application history with roster snapshots when present', function () {
    $activity = createAccountApplicationsActivity();
    $activity->update(['starts_at' => now()->subDay()]);
    $user = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
    ]);

    $application = ActivityApplication::factory()->approved()->create([
        'activity_id' => $activity->id,
        'user_id' => $user->id,
        'selected_character_id' => $character->id,
    ]);
    $slot = $activity->slots()->firstOrFail();

    ActivitySlotAssignment::query()->create([
        'activity_id' => $activity->id,
        'group_id' => $activity->group_id,
        'activity_slot_id' => $slot->id,
        'character_id' => $character->id,
        'application_id' => $application->id,
        'field_values_snapshot' => [
            'character_class' => [
                'name' => 'White Mage',
                'shorthand' => 'WHM',
                'role' => 'healer',
            ],
            'phantom_job' => [
                'name' => 'Phantom Bard',
            ],
            'raid_position' => [
                'key' => 'h1',
                'label' => ['en' => 'H1'],
            ],
        ],
        'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
        'assigned_at' => now()->subDay(),
        'assigned_by_user_id' => $activity->group->owner_id,
    ]);

    $this->actingAs($user);

    $this->getJson(route('account.applications.history', ['per_page' => 10]))
        ->assertOk()
        ->assertJsonPath('meta.per_page', 10)
        ->assertJsonPath('data.0.id', $application->id)
        ->assertJsonPath('data.0.assignment.character_class.name', 'White Mage')
        ->assertJsonPath('data.0.assignment.phantom_job.name', 'Phantom Bard')
        ->assertJsonPath('data.0.assignment.raid_position.label.en', 'H1');
});
