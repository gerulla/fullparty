<?php

use App\DTOs\LodestoneCharacterSearchResult;
use App\Events\ActivityManagementUpdated;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use App\Models\ActivityTypeVersion;
use App\Models\AuditLog;
use App\Models\Character;
use App\Models\Group;
use App\Models\User;
use App\Services\Groups\ActivitySlotAssignmentService;
use App\Services\Groups\ActivitySlotFieldDefinitionBuilder;
use App\Services\Lodestone\LodestoneCharacterSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Event::fake([ActivityManagementUpdated::class]);
    $version = ActivityTypeVersion::factory()->create([
        'layout_schema' => ['groups' => [['key' => 'party', 'label' => ['en' => 'Party'], 'size' => 2]]],
        'slot_schema' => [['key' => 'position', 'type' => 'single_select', 'source' => 'static_options',
            'label' => ['en' => 'Position'], 'options' => [['key' => 'north', 'label' => ['en' => 'North']]]]],
        'application_schema' => [], 'bench_size' => 1,
    ]);
    $this->run = Activity::factory()->create([
        'activity_type_version_id' => $version->id, 'status' => Activity::STATUS_SCHEDULED,
        'is_public' => true, 'needs_application' => false, 'allow_guest_applications' => true,
    ]);
    $this->slot = $this->run->slots()->where('group_key', 'party')->firstOrFail();
    $this->input = [
        'guest_applicant' => ['lodestone_id' => '47431834', 'name' => 'Guest Adventurer', 'world' => 'Lich', 'datacenter' => 'Light'],
        'field_values' => ['position' => 'north'], 'expected_slot_state_token' => activity_slot_state_token($this->slot),
    ];
});

function guestAssignmentUrl(Activity $run, ActivitySlot|string $target, string $action = 'store'): string
{
    return route('groups.activities.guest-self-assignments.'.$action, [
        'group' => $run->group, 'activity' => $run,
        $target instanceof ActivitySlot ? 'slot' : 'accessToken' => $target,
    ]);
}

it('directly assigns a guest with a private token and no approval queue', function (bool $bench) {
    if ($bench) {
        $this->slot = ActivitySlot::factory()->create(['activity_id' => $this->run->id, 'group_key' => 'bench', 'slot_kind' => ActivitySlot::SLOT_KIND_BENCH]);
        $this->input['expected_slot_state_token'] = activity_slot_state_token($this->slot);
        $this->input['field_values'] = [];
    }
    $response = $this->postJson(guestAssignmentUrl($this->run, $this->slot), $this->input)->assertCreated();
    $registration = ActivityApplication::query()->sole();
    expect($registration->is_self_assignment)->toBeTrue()
        ->and($registration->user_id)->toBeNull()
        ->and($registration->selectedCharacter->user_id)->toBeNull()
        ->and($registration->status)->toBe($bench ? ActivityApplication::STATUS_ON_BENCH : ActivityApplication::STATUS_APPROVED)
        ->and($registration->guest_access_token)->toHaveLength(40)
        ->and(ActivityApplication::where('status', ActivityApplication::STATUS_PENDING)->count())->toBe(0);
    $assignment = ActivitySlotAssignment::query()->sole();
    expect($assignment->application_id)->toBe($registration->id)->and($assignment->assigned_by_user_id)->toBeNull();
    $audit = AuditLog::where('action', 'group.activity.roster.assigned')->sole();
    expect($audit->actor_user_id)->toBeNull()->and(json_encode($audit->metadata))->not->toContain($registration->guest_access_token);
    Event::assertDispatched(ActivityManagementUpdated::class, fn ($event) => $event->patch['updated_slots'][0]['id'] === $this->slot->id
        && ! str_contains(json_encode($event->patch), $registration->guest_access_token));
    $this->getJson($response->json('manage_url'))->assertOk()->assertJsonPath('can_withdraw', true)
        ->assertJsonMissingPath('guest_access_token');
})->with([false, true]);

it('rejects unavailable runs and mismatched groups without creating guest records', function (array $changes) {
    $this->run->update($changes);
    $this->postJson(guestAssignmentUrl($this->run, $this->slot), $this->input)->assertNotFound();
    expect(ActivityApplication::count())->toBe(0)->and(Character::where('lodestone_id', '47431834')->exists())->toBeFalse();
})->with([
    [['allow_guest_applications' => false]], [['is_public' => false]], [['needs_application' => true]],
    [['status' => Activity::STATUS_DRAFT]], [['status' => Activity::STATUS_COMPLETE]], [['status' => Activity::STATUS_CANCELLED]],
]);

it('rejects authenticated guest impersonation and mismatched route models', function () {
    $other = Activity::factory()->create();
    $this->postJson(guestAssignmentUrl($this->run, $other->slots()->first()), $this->input)->assertNotFound();
    $this->postJson(route('groups.activities.guest-self-assignments.store', [
        'group' => Group::factory()->create(), 'activity' => $this->run, 'slot' => $this->slot,
    ]), $this->input)->assertNotFound();
    $this->actingAs(User::factory()->create())->postJson(guestAssignmentUrl($this->run, $this->slot), $this->input)->assertNotFound();
    expect(ActivityApplication::count())->toBe(0);
});

it('blocks verified and claimed characters without changing their owner', function (string $field) {
    $character = Character::factory()->create(['lodestone_id' => '47431834', 'verified_at' => null, 'claimed_at' => null, $field => now()]);
    $original = $character->only(['user_id', 'name', 'world']);
    $this->postJson(guestAssignmentUrl($this->run, $this->slot), $this->input)
        ->assertUnprocessable()->assertJsonValidationErrors('guest_applicant.lodestone_id');
    expect($character->fresh()->only(array_keys($original)))->toBe($original)->and(ActivityApplication::count())->toBe(0);
})->with(['verified_at', 'claimed_at']);

it('rolls back invalid field choices and rejects stale or occupied slots', function () {
    $input = $this->input;
    $input['field_values']['position'] = 'invalid';
    $this->postJson(guestAssignmentUrl($this->run, $this->slot), $input)->assertUnprocessable()->assertJsonValidationErrors('field_values.position');
    expect(ActivityApplication::count())->toBe(0)->and(Character::where('lodestone_id', '47431834')->exists())->toBeFalse();
    $input['expected_slot_state_token'] = 'stale';
    $this->postJson(guestAssignmentUrl($this->run, $this->slot), $input)->assertConflict();
    $this->postJson(guestAssignmentUrl($this->run, $this->slot), $this->input)->assertCreated();
    $this->postJson(guestAssignmentUrl($this->run, $this->slot), $this->input)->assertConflict();
    $this->slot->refresh()->unsetRelations();
    $input = $this->input;
    $input['expected_slot_state_token'] = activity_slot_state_token($this->slot);
    $this->postJson(guestAssignmentUrl($this->run, $this->slot), $input)->assertUnprocessable();
    expect(ActivityApplication::count())->toBe(1)->and(ActivitySlotAssignment::count())->toBe(1);
});

it('rejects duplicate character registrations across different slots', function () {
    $this->postJson(guestAssignmentUrl($this->run, $this->slot), $this->input)->assertCreated();
    $second = $this->run->slots()->where('group_key', 'party')->whereKeyNot($this->slot->id)->firstOrFail();
    $this->input['expected_slot_state_token'] = activity_slot_state_token($second);
    $this->postJson(guestAssignmentUrl($this->run, $second), $this->input)->assertUnprocessable();
    expect(ActivityApplication::count())->toBe(1)->and($second->fresh()->assigned_character_id)->toBeNull();
});

it('withdraws only the token-owned registration after a host moves it and invalidates the token', function () {
    $token = $this->postJson(guestAssignmentUrl($this->run, $this->slot), $this->input)->assertCreated()->json('guest_access_token');
    $registration = ActivityApplication::query()->sole();
    $second = $this->run->slots()->where('group_key', 'party')->whereKeyNot($this->slot->id)->firstOrFail();
    app(ActivitySlotAssignmentService::class)->assignFromApplication($second, $registration,
        ['position' => 'north'], collect(app(ActivitySlotFieldDefinitionBuilder::class)->build($this->run->activityTypeVersion, $this->run->group_id))->keyBy('key')->all(),
        $this->run->group->owner_id, $this->slot->fresh());
    $other = Activity::factory()->create(['status' => Activity::STATUS_SCHEDULED, 'is_public' => true, 'allow_guest_applications' => true]);
    $this->getJson(guestAssignmentUrl($other, $token, 'show'))->assertNotFound();
    $this->deleteJson(guestAssignmentUrl($this->run, str_repeat('x', 40), 'destroy'))->assertNotFound();
    $this->deleteJson(guestAssignmentUrl($this->run, $token, 'destroy'))->assertOk();
    expect($second->fresh()->assigned_character_id)->toBeNull()
        ->and($registration->fresh()->status)->toBe(ActivityApplication::STATUS_WITHDRAWN)
        ->and($registration->fresh()->guest_access_token)->toBeNull()
        ->and(ActivitySlotAssignment::whereNull('ended_at')->count())->toBe(0);
    $this->getJson(guestAssignmentUrl($this->run, $token, 'show'))->assertNotFound();
    $this->deleteJson(guestAssignmentUrl($this->run, $token, 'destroy'))->assertNotFound();
});

it('keeps completed registrations read-only', function () {
    $token = $this->postJson(guestAssignmentUrl($this->run, $this->slot), $this->input)->assertCreated()->json('guest_access_token');
    $this->run->update(['status' => Activity::STATUS_COMPLETE]);
    $this->getJson(guestAssignmentUrl($this->run, $token, 'show'))->assertOk()->assertJsonPath('can_withdraw', false);
    $this->deleteJson(guestAssignmentUrl($this->run, $token, 'destroy'))->assertUnprocessable();
    expect($this->slot->fresh()->assigned_character_id)->not->toBeNull();
});

it('does not let an old guest registration remove a subsequent manual assignment', function () {
    $token = $this->postJson(guestAssignmentUrl($this->run, $this->slot), $this->input)->assertCreated()->json('guest_access_token');
    $registration = ActivityApplication::query()->sole();
    // Simulate a later placement that no longer belongs to this registration.
    ActivitySlotAssignment::query()->sole()->update(['application_id' => null, 'assignment_source' => ActivitySlotAssignment::SOURCE_MANUAL]);
    $this->deleteJson(guestAssignmentUrl($this->run, $token, 'destroy'))->assertOk();
    expect($this->slot->fresh()->assigned_character_id)->toBe($registration->selected_character_id)
        ->and(ActivitySlotAssignment::whereNull('ended_at')->sole()->application_id)->toBeNull();
});

it('preserves unverified account links and prevents a token from managing an account-owned registration', function () {
    $character = Character::factory()->create(['lodestone_id' => '47431834', 'verified_at' => null, 'claimed_at' => null]);
    $ownerId = $character->user_id;
    $token = $this->postJson(guestAssignmentUrl($this->run, $this->slot), $this->input)->assertCreated()->json('guest_access_token');
    expect($character->fresh()->user_id)->toBe($ownerId)->and($character->fresh()->verified_at)->toBeNull();
    ActivityApplication::query()->sole()->update(['user_id' => $ownerId]);
    $this->getJson(guestAssignmentUrl($this->run, $token, 'show'))->assertNotFound();
    $this->deleteJson(guestAssignmentUrl($this->run, $token, 'destroy'))->assertNotFound();
    expect($this->slot->fresh()->assigned_character_id)->toBe($character->id);
});

it('allows Lodestone searches for guest self-assignment while filtering protected characters', function () {
    Character::factory()->create(['lodestone_id' => '22', 'verified_at' => now()]);
    $search = Mockery::mock(LodestoneCharacterSearchService::class);
    $search->shouldReceive('availableWorlds')->once()->andReturn(['Lich']);
    $search->shouldReceive('search')->once()->with('Guest Adventurer', 'Lich')->andReturn([
        new LodestoneCharacterSearchResult('11', 'Guest Adventurer', 'Lich', 'Light', null, 'https://eu.finalfantasyxiv.com/lodestone/character/11/'),
        new LodestoneCharacterSearchResult('22', 'Protected Adventurer', 'Lich', 'Light', null, 'https://eu.finalfantasyxiv.com/lodestone/character/11/'),
    ]);
    $this->app->instance(LodestoneCharacterSearchService::class, $search);
    $this->getJson(route('groups.activities.application.search-characters', [$this->run->group, $this->run, 'name' => 'Guest Adventurer', 'world' => 'Lich']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.lodestone_id', '11');
});
