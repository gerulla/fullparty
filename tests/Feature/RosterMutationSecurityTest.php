<?php

use App\Events\ActivityManagementUpdated;
use App\Http\Middleware\SerializeActivityRosterMutation;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\NotificationDelivery;
use App\Services\Groups\ActivityApplicationWithdrawalService;
use App\Services\Groups\ActivitySlotAssignmentService;
use App\Services\Groups\ActivitySlotAttendanceService;
use App\Services\Notifications\DiscordNotificationDeliveryService;
use App\Services\Notifications\NotificationDeliveryDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

uses(RefreshDatabase::class);

function createRosterSecurityFixture(): array
{
    $version = ActivityTypeVersion::factory()->create([
        'layout_schema' => ['groups' => [['key' => 'party-a', 'label' => ['en' => 'Party A'], 'size' => 2]]],
        'slot_schema' => [],
        'application_schema' => [],
        'bench_size' => 0,
    ]);
    $activity = Activity::factory()->create([
        'activity_type_version_id' => $version->id,
        'status' => Activity::STATUS_SCHEDULED,
        'starts_at' => now()->addDay(),
    ]);

    return [$activity, ...$activity->slots()->orderBy('id')->get()->all()];
}

it('rejects a stale service-level slot snapshot without overwriting the newer occupant', function () {
    [$activity, $slot] = createRosterSecurityFixture();
    $first = Character::factory()->create();
    $second = Character::factory()->create();
    $staleSlot = $slot->fresh(['activity', 'fieldValues', 'assignments']);
    $service = app(ActivitySlotAssignmentService::class);
    $service->assignManualCharacter($slot, $first, [], [], $activity->organized_by_user_id);

    expect(fn () => $service->assignManualCharacter($staleSlot, $second, [], [], $activity->organized_by_user_id))
        ->toThrow(ConflictHttpException::class);
    expect($slot->fresh()->assigned_character_id)->toBe($first->id)
        ->and($activity->slotAssignments()->whereNull('ended_at')->count())->toBe(1);
});

it('rejects assigning the same character to another slot after a competing assignment', function () {
    [$activity, $firstSlot, $secondSlot] = createRosterSecurityFixture();
    $character = Character::factory()->create();
    $service = app(ActivitySlotAssignmentService::class);
    $service->assignManualCharacter($firstSlot, $character, [], [], $activity->organized_by_user_id);

    expect(fn () => $service->assignManualCharacter($secondSlot, $character, [], [], $activity->organized_by_user_id))
        ->toThrow(ValidationException::class);
    expect($secondSlot->fresh()->assigned_character_id)->toBeNull()
        ->and($activity->slotAssignments()->whereNull('ended_at')->count())->toBe(1);
});

it('does not assign an application withdrawn after the caller loaded it', function () {
    [$activity, $slot] = createRosterSecurityFixture();
    $character = Character::factory()->create();
    $application = ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'selected_character_id' => $character->id,
        'status' => ActivityApplication::STATUS_PENDING,
    ]);
    $application->fresh()->update(['status' => ActivityApplication::STATUS_WITHDRAWN]);

    expect(fn () => app(ActivitySlotAssignmentService::class)->assignFromApplication(
        $slot, $application, [], [], $activity->organized_by_user_id,
    ))->toThrow(ValidationException::class);
    expect($slot->fresh()->assigned_character_id)->toBeNull()
        ->and($activity->slotAssignments()->count())->toBe(0);
});

it('reloads assignment history instead of recreating it from a stale activity relation', function () {
    [$activity, $slot] = createRosterSecurityFixture();
    $character = Character::factory()->create();
    $slot->update(['assigned_character_id' => $character->id]);
    $staleActivity = $activity->fresh(['slots', 'slotAssignments', 'applications']);
    $service = app(ActivitySlotAttendanceService::class);

    $service->ensureActiveAssignments($activity);
    $service->ensureActiveAssignments($staleActivity);

    expect($activity->slotAssignments()->whereNull('ended_at')->count())->toBe(1);
});

it('rejects stale attendance mutations instead of changing a replacement player', function (string $method) {
    [$activity, $slot] = createRosterSecurityFixture();
    $first = Character::factory()->create();
    $second = Character::factory()->create();
    $slot->update(['assigned_character_id' => $first->id]);
    $staleSlot = $slot->fresh(['activity', 'fieldValues', 'assignments']);
    $slot->update(['assigned_character_id' => $second->id]);

    expect(fn () => app(ActivitySlotAttendanceService::class)->{$method}($staleSlot, $activity->organized_by_user_id))
        ->toThrow(ConflictHttpException::class);
    expect($slot->fresh()->assigned_character_id)->toBe($second->id)
        ->and($activity->slotAssignments()->count())->toBe(0);
})->with(['checkInSlot', 'markLateSlot', 'markMissing']);

it('withdraws the current application character without clearing its former slot occupant', function () {
    [$activity, $firstSlot, $secondSlot] = createRosterSecurityFixture();
    $formerCharacter = Character::factory()->create();
    $currentCharacter = Character::factory()->create();
    $application = ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'selected_character_id' => $formerCharacter->id,
        'status' => ActivityApplication::STATUS_APPROVED,
    ]);
    $firstSlot->update(['assigned_character_id' => $formerCharacter->id]);
    $secondSlot->update(['assigned_character_id' => $currentCharacter->id]);
    $application->fresh()->update(['selected_character_id' => $currentCharacter->id]);

    app(ActivityApplicationWithdrawalService::class)->withdraw($application, $activity->group->owner);

    expect($firstSlot->fresh()->assigned_character_id)->toBe($formerCharacter->id)
        ->and($secondSlot->fresh()->assigned_character_id)->toBeNull()
        ->and($application->fresh()->status)->toBe(ActivityApplication::STATUS_WITHDRAWN);
});

it('does not grant an empty slots legacy host and leader flags to a manual assignee', function () {
    [$activity, $slot] = createRosterSecurityFixture();
    $slot->update(['is_host' => true, 'is_raid_leader' => true]);
    $character = Character::factory()->create();

    app(ActivitySlotAssignmentService::class)->assignManualCharacter(
        $slot, $character, [], [], $activity->organized_by_user_id,
    );

    expect($slot->fresh()->is_host)->toBeFalse()
        ->and($slot->fresh()->is_raid_leader)->toBeFalse();
});

it('repairs only empty slots when applying the designation cleanup migration', function () {
    [, $emptySlot, $occupiedSlot] = createRosterSecurityFixture();
    $emptySlot->update(['is_host' => true, 'is_raid_leader' => true]);
    $occupiedSlot->update([
        'assigned_character_id' => Character::factory()->create()->id,
        'is_host' => true,
        'is_raid_leader' => true,
    ]);

    $migration = require database_path('migrations/2026_09_08_000001_clear_empty_roster_slot_designations.php');
    $migration->up();
    $migration->up();

    expect($emptySlot->fresh()->is_host)->toBeFalse()
        ->and($emptySlot->fresh()->is_raid_leader)->toBeFalse()
        ->and($occupiedSlot->fresh()->is_host)->toBeTrue()
        ->and($occupiedSlot->fresh()->is_raid_leader)->toBeTrue();
});

it('refreshes route-bound slots and rolls back rendered error responses including validation redirects', function (bool $redirect) {
    [$activity, $slot] = createRosterSecurityFixture();
    $request = Request::create('/roster-test', 'POST');
    $route = new Route('POST', '/roster-test', fn () => null);
    $route->bind($request);
    $route->setParameter('activity', $activity);
    $route->setParameter('slot', $slot);
    $request->setRouteResolver(fn () => $route);
    $character = Character::factory()->create();
    $slot->fresh()->update(['assigned_character_id' => $character->id]);
    $originalTitle = $activity->title;

    $response = app(SerializeActivityRosterMutation::class)->handle($request, function () use ($activity, $slot, $character, $redirect) {
        expect($slot->assigned_character_id)->toBe($character->id)
            ->and(DB::transactionLevel())->toBeGreaterThan(1);
        $activity->update(['title' => 'Must roll back']);

        return $redirect
            ? redirect('/')->withException(ValidationException::withMessages(['slot' => 'Invalid']))
            : response()->json(['message' => 'Invalid'], 422);
    });

    expect($response->getStatusCode())->toBe($redirect ? 302 : 422)
        ->and($activity->fresh()->title)->toBe($originalTitle);
})->with([false, true]);

it('does not broadcast roster changes from a rolled-back transaction', function () {
    Event::fake([ActivityManagementUpdated::class]);

    expect(fn () => DB::transaction(function (): void {
        ActivityManagementUpdated::dispatch(1, 1, ['reload' => true]);
        throw new RuntimeException('Abort mutation');
    }))->toThrow(RuntimeException::class);

    Event::assertNotDispatched(ActivityManagementUpdated::class);
    DB::transaction(fn () => ActivityManagementUpdated::dispatch(1, 1, ['reload' => true]));
    Event::assertDispatchedTimes(ActivityManagementUpdated::class, 1);
});

it('defers Discord delivery until commit and discards delivery on rollback', function () {
    $discord = Mockery::mock(DiscordNotificationDeliveryService::class);
    $delivery = new NotificationDelivery(['channel' => 'discord']);
    $discord->shouldReceive('send')->once()->with($delivery);
    $dispatcher = new NotificationDeliveryDispatcher($discord);

    expect(fn () => DB::transaction(function () use ($dispatcher, $delivery): void {
        $dispatcher->dispatch($delivery);
        throw new RuntimeException('Abort notification');
    }))->toThrow(RuntimeException::class);
    DB::transaction(fn () => $dispatcher->dispatch($delivery));
});
