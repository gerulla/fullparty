<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
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
    $group = Group::factory()->public()->create([
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
        'status' => Activity::STATUS_PLANNED,
        'needs_application' => true,
        'is_public' => true,
    ]);
}

it('shows the authenticated users applications newest first', function () {
    $activity = createAccountApplicationsActivity();
    $secondActivity = createAccountApplicationsActivity();
    $user = User::factory()->create();
    $character = Character::factory()->primary()->create([
        'user_id' => $user->id,
    ]);

    ActivityApplication::factory()->create([
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
            ->where('activeApplications.0.id', $latest->id)
            ->where('activeApplications.0.can_edit', true)
            ->where('activeApplications.0.can_cancel', true)
            ->where('historicalApplications', []));
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

it('does not allow users to withdraw applications that are no longer pending', function () {
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

    $this->actingAs($user);

    $response = $this->delete(route('account.applications.destroy', [
        'application' => $application->id,
    ]));

    $response->assertSessionHasErrors(['application']);
    expect(ActivityApplication::query()->whereKey($application->id)->exists())->toBeTrue();
});

it('shows withdrawn applications in history', function () {
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
            ->where('activeApplications', [])
            ->where('historicalApplications.0.id', $withdrawn->id)
            ->where('historicalApplications.0.status', ActivityApplication::STATUS_WITHDRAWN));
});

it('includes moderator review reasons in application history', function () {
    $activity = createAccountApplicationsActivity();
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
            ->where('historicalApplications.0.id', $declined->id)
            ->where('historicalApplications.0.review_reason', 'The roster is already final for this run.'));
});
