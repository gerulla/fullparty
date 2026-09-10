<?php

use App\Events\ActivityManagementUpdated;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\AuditLog;
use App\Models\Character;
use App\Models\Group;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Services\Groups\ActivitySlotAssignmentService;
use App\Services\Groups\ActivitySlotDesignationService;
use App\Services\Groups\ActivitySlotSerializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Event::fake([ActivityManagementUpdated::class]);
    $this->owner = User::factory()->create();
    Character::factory()->primary()->create(['user_id' => $this->owner->id]);
    $this->group = Group::factory()->create(['owner_id' => $this->owner->id]);
    $this->type = ActivityType::factory()->create(['slug' => 'delubrum-reginae-savage']);
    $version = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $this->type->id,
        'layout_schema' => ['groups' => [['key' => 'party-a', 'label' => ['en' => 'Party A'], 'size' => 2]]],
        'slot_schema' => [], 'application_schema' => [], 'bench_size' => 0,
    ]);
    $this->activity = Activity::factory()->create([
        'group_id' => $this->group->id, 'activity_type_id' => $this->type->id,
        'activity_type_version_id' => $version->id, 'organized_by_user_id' => $this->owner->id,
        'status' => Activity::STATUS_ASSIGNED,
    ]);
    $this->slot = $this->activity->slots()->orderBy('id')->firstOrFail();
    $this->character = Character::factory()->primary()->create();
    $this->slot->update(['assigned_character_id' => $this->character->id, 'assigned_by_user_id' => $this->owner->id]);
    $this->url = route('groups.dashboard.activities.slot-designations.store', [$this->group, $this->activity, $this->slot]);
    $this->toggle = fn ($designation, $token = null) => $this->actingAs($this->owner)->postJson($this->url, [
        'designation' => $designation,
        'expected_slot_state_token' => $token ?? activity_slot_state_token($this->slot->fresh()),
    ]);
});

it('restricts specialist designations by activity type on both the endpoint and slot payload', function ($slug, $designation, $allowed) {
    $this->type->update(['slug' => $slug]);
    $response = ($this->toggle)($designation);
    if ($allowed) {
        $response->assertOk()->assertJsonPath('slot.is_'.$designation, true);
        expect($response->json('slot.available_designations'))->toContain($designation);
    } else {
        $response->assertUnprocessable()->assertJsonValidationErrors('designation');
        expect($this->slot->fresh()->{'is_'.$designation})->toBeFalse();
    }
    $payload = app(ActivitySlotSerializer::class)->serialize($this->slot->fresh());
    expect(in_array($designation, $payload['available_designations'], true))->toBe($allowed);
})->with([
    ['delubrum-reginae-savage', 'duelist', true],
    ['delubrum-reginae-savage', 'trapper', true],
    ['the-baldesion-arsenal', 'trapper', true],
    ['baldesion-arsenal', 'trapper', true],
    ['the-baldesion-arsenal', 'darter', true],
    ['baldesion-arsenal', 'darter', true],
    ['delubrum-reginae-savage', 'darter', false],
    ['other-activity', 'darter', false],
    ['the-baldesion-arsenal', 'duelist', false],
    ['delubrum-reginae', 'duelist', false],
    ['other-activity', 'trapper', false],
    ['other-activity', 'duelist', false],
]);

it('toggles specialist marks with audit notifications and realtime updates', function ($slug, $designation) {
    $this->type->update(['slug' => $slug]);
    ($this->toggle)($designation)->assertOk();
    expect(AuditLog::where('action', 'group.activity.roster.'.$designation.'_marked')->count())->toBe(1)
        ->and(NotificationEvent::where('type', 'assignments.designation_assigned')->count())->toBe(1);
    expect(NotificationEvent::where('type', 'assignments.designation_assigned')->first()->payload['designation_key'])->toBe($designation)
        ->and(NotificationEvent::where('type', 'assignments.designation_assigned')->first()->payload['designation_label'])->toBe(ucfirst($designation));
    Event::assertDispatched(ActivityManagementUpdated::class, fn ($event) => ($event->patch['updated_slots'][0]['is_'.$designation] ?? false) === true);
    ($this->toggle)($designation)->assertOk()->assertJsonPath('slot.is_'.$designation, false);
    expect(AuditLog::where('action', 'group.activity.roster.'.$designation.'_cleared')->count())->toBe(1)
        ->and(NotificationEvent::where('type', 'assignments.designation_removed')->count())->toBe(1);
})->with([
    ['delubrum-reginae-savage', 'duelist'],
    ['delubrum-reginae-savage', 'trapper'],
    ['the-baldesion-arsenal', 'darter'],
]);

it('keeps specialist marks independent of leadership marks', function ($slug, $specialist) {
    $this->type->update(['slug' => $slug]);
    $this->slot->update(['is_host' => true]);
    ($this->toggle)($specialist)->assertOk()->assertJsonPath('slot.is_host', true);
    ($this->toggle)('trapper')->assertOk()->assertJsonPath('slot.is_'.$specialist, true);
    ($this->toggle)('raid_leader')->assertOk()
        ->assertJsonPath('slot.is_host', false)->assertJsonPath('slot.is_'.$specialist, true)->assertJsonPath('slot.is_trapper', true);
})->with([['delubrum-reginae-savage', 'duelist'], ['the-baldesion-arsenal', 'darter']]);

it('does not allow specialist marks on empty bench or fill-in slots', function ($kind, $occupied) {
    $this->slot->update(['slot_kind' => $kind, 'assigned_character_id' => $occupied ? $this->character->id : null]);
    ($this->toggle)('trapper')->assertUnprocessable()->assertJsonValidationErrors('slot');
})->with([['roster', false], ['bench', true], ['fill_in', true]]);

it('does not allow darter marks on empty bench or fill-in slots', function ($kind, $occupied) {
    $this->type->update(['slug' => 'the-baldesion-arsenal']);
    $this->slot->update(['slot_kind' => $kind, 'assigned_character_id' => $occupied ? $this->character->id : null]);
    ($this->toggle)('darter')->assertUnprocessable()->assertJsonValidationErrors('slot');
})->with([['roster', false], ['bench', true], ['fill_in', true]]);

it('rejects unauthorized and archived requests', function ($slug, $specialist) {
    $this->type->update(['slug' => $slug]);
    $outsider = User::factory()->create();
    Character::factory()->primary()->create(['user_id' => $outsider->id]);
    $this->actingAs($outsider)->postJson($this->url, [
        'designation' => $specialist, 'expected_slot_state_token' => activity_slot_state_token($this->slot->fresh()),
    ])->assertNotFound();
    $this->activity->update(['status' => Activity::STATUS_COMPLETE]);
    ($this->toggle)($specialist)->assertForbidden();
})->with([['delubrum-reginae-savage', 'duelist'], ['the-baldesion-arsenal', 'darter']]);

it('rejects stale slot tokens after a specialist designation changes', function ($slug, $specialist) {
    $this->type->update(['slug' => $slug]);
    $token = activity_slot_state_token($this->slot->fresh());
    ($this->toggle)($specialist, $token)->assertOk();
    ($this->toggle)('trapper', $token)->assertConflict();
})->with([['delubrum-reginae-savage', 'duelist'], ['the-baldesion-arsenal', 'darter']]);

it('clears specialist marks on invalid slots and when replacing an assignee', function ($slug, $specialist) {
    $this->type->update(['slug' => $slug]);
    $column = 'is_'.$specialist;
    $this->slot->update([$column => true, 'is_trapper' => true]);
    $application = ActivityApplication::factory()->create(['activity_id' => $this->activity->id]);
    app(ActivitySlotAssignmentService::class)->assignFromApplication(
        $this->slot->fresh(), $application, [], [], $this->owner->id,
    );
    expect($this->slot->fresh()->{$column})->toBeFalse()->and($this->slot->fresh()->is_trapper)->toBeFalse();
    $this->slot->update([$column => true, 'is_trapper' => true, 'assigned_character_id' => null]);
    app(ActivitySlotDesignationService::class)->clearInvalidDesignations([$this->slot->fresh()], $this->owner);
    expect($this->slot->fresh()->{$column})->toBeFalse()->and($this->slot->fresh()->is_trapper)->toBeFalse();
})->with([['delubrum-reginae-savage', 'duelist'], ['the-baldesion-arsenal', 'darter']]);

it('moves specialist marks with a swapped assignee', function ($slug, $specialist) {
    $this->type->update(['slug' => $slug]);
    $column = 'is_'.$specialist;
    $target = $this->activity->slots()->whereKeyNot($this->slot->id)->firstOrFail();
    $this->slot->update([$column => true, 'is_trapper' => true]);
    $this->actingAs($this->owner)->postJson(route('groups.dashboard.activities.slot-swaps.store', [$this->group, $this->activity]), [
        'source_slot_id' => $this->slot->id, 'target_slot_id' => $target->id,
        'expected_source_slot_state_token' => activity_slot_state_token($this->slot->fresh()),
        'expected_target_slot_state_token' => activity_slot_state_token($target->fresh()),
    ])->assertOk();
    expect($this->slot->fresh()->{$column})->toBeFalse()->and($this->slot->fresh()->is_trapper)->toBeFalse()
        ->and($target->fresh()->{$column})->toBeTrue()->and($target->fresh()->is_trapper)->toBeTrue();
})->with([['delubrum-reginae-savage', 'duelist'], ['the-baldesion-arsenal', 'darter']]);
