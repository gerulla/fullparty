<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivityPartyFinderInfo;
use App\Models\Character;
use App\Models\Group;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Notifications\NotificationTopic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('lets moderators publish persistent party finder info to placed run users', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $group = Group::factory()->open()->create(['owner_id' => $owner->id]);
    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'status' => Activity::STATUS_ASSIGNED,
        'starts_at' => now()->addDay(),
        'title' => 'Forked Tower Reclear',
    ]);

    $approvedUser = User::factory()->create([
        'run_and_reminder_notifications' => true,
        'email_notifications' => true,
    ]);
    $approvedCharacter = Character::factory()->primary()->create(['user_id' => $approvedUser->id]);
    ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $approvedUser->id,
        'selected_character_id' => $approvedCharacter->id,
        'status' => ActivityApplication::STATUS_APPROVED,
    ]);

    $pendingUser = User::factory()->create([
        'run_and_reminder_notifications' => true,
        'email_notifications' => true,
    ]);
    $pendingCharacter = Character::factory()->primary()->create(['user_id' => $pendingUser->id]);
    ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $pendingUser->id,
        'selected_character_id' => $pendingCharacter->id,
        'status' => ActivityApplication::STATUS_PENDING,
    ]);

    $this->actingAs($approvedUser)
        ->get(route('account.applications'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('featuredApplication.activity.party_finder_info', null));

    $response = $this->actingAs($owner)->postJson(route('groups.dashboard.activities.party-finder-info.store', [
        'group' => $group,
        'activity' => $activity,
    ]), [
        'character_name' => 'Giki Chomusuke',
        'world' => 'Lich',
        'password' => '0042',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.character_name', 'Giki Chomusuke')
        ->assertJsonPath('data.world', 'Lich')
        ->assertJsonPath('data.password', '0042');

    $info = ActivityPartyFinderInfo::query()->sole();
    $rawPassword = DB::table('activity_party_finder_info')->value('password');
    $event = NotificationEvent::query()->where('type', 'runs.party_finder_published')->sole();

    expect($info->password)->toBe('0042')
        ->and($rawPassword)->not->toBe('0042')
        ->and($event->topic)->toBe(NotificationTopic::RUNS_REMINDERS)
        ->and($event->message_params)->toMatchArray([
            'character' => 'Giki Chomusuke',
            'world' => 'Lich',
            'password' => '0042',
        ])
        ->and($event->payload['party_finder']['password'])->toBe('0042')
        ->and(UserNotification::query()->where('user_id', $approvedUser->id)->count())->toBe(1)
        ->and(UserNotification::query()->where('user_id', $pendingUser->id)->count())->toBe(0)
        ->and(NotificationDelivery::query()->where('user_id', $approvedUser->id)->count())->toBe(2)
        ->and(NotificationDelivery::query()->where('user_id', $pendingUser->id)->count())->toBe(0);

    $this->actingAs($owner)
        ->getJson(route('groups.dashboard.activities.management-data', [
            'group' => $group,
            'activity' => $activity,
        ]))
        ->assertOk()
        ->assertJsonPath('activity.party_finder_info.password', '0042');

    $this->actingAs($approvedUser)
        ->get(route('account.applications'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('featuredApplication.activity.party_finder_info.password', '0042')
            ->where('featuredApplication.activity.party_finder_info.character_name', 'Giki Chomusuke'));

    $this->actingAs($pendingUser)
        ->get(route('account.applications'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('featuredApplication.activity.party_finder_info', null));
});

it('rejects party finder publishing by users without management access', function () {
    $group = Group::factory()->open()->create();
    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'status' => Activity::STATUS_ASSIGNED,
    ]);
    $member = User::factory()->create();
    $group->memberships()->create([
        'user_id' => $member->id,
        'role' => 'member',
        'joined_at' => now(),
    ]);

    $this->actingAs($member)->postJson(route('groups.dashboard.activities.party-finder-info.store', [
        'group' => $group,
        'activity' => $activity,
    ]), [
        'character_name' => 'Giki Chomusuke',
        'world' => 'Lich',
        'password' => '0042',
    ])->assertForbidden();

    expect(ActivityPartyFinderInfo::query()->count())->toBe(0);
});
