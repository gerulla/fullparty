<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\Group;
use App\Models\User;
use App\Services\Dashboard\V2HomeDataService;
use App\Services\Runs\RunParticipationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(now()->startOfSecond());
    $this->viewer = User::factory()->create();
    $this->group = Group::factory()->open()->withMember($this->viewer)->create();
    $this->run = Activity::factory()->create([
        'group_id' => $this->group->id,
        'activity_type_version_id' => ActivityTypeVersion::factory()->create(['layout_schema' => []])->id,
        'status' => Activity::STATUS_SCHEDULED,
        'starts_at' => now()->addDay(),
        'is_public' => true,
    ]);
    $this->application = ActivityApplication::factory()->create([
        'activity_id' => $this->run->id,
        'user_id' => $this->viewer->id,
        'status' => ActivityApplication::STATUS_APPROVED,
    ]);
    $this->assign = function (string $kind = 'roster', string $attendance = 'assigned', bool $ended = false): ActivitySlotAssignment {
        $slot = ActivitySlot::factory()->create([
            'activity_id' => $this->run->id,
            'slot_kind' => $kind,
            'slot_key' => (string) Str::uuid(),
            'assigned_character_id' => $ended ? null : $this->application->selected_character_id,
        ]);

        return ActivitySlotAssignment::create([
            'activity_id' => $this->run->id,
            'group_id' => $this->group->id,
            'activity_slot_id' => $slot->id,
            'character_id' => $this->application->selected_character_id,
            'application_id' => $this->application->id,
            'attendance_status' => $attendance,
            'assigned_at' => now()->subHour(),
            'ended_at' => $ended ? now()->subMinute() : null,
        ]);
    };
    $this->statuses = fn () => app(RunParticipationService::class)
        ->statusesFor(Activity::whereKey($this->run->id)->get(), $this->viewer->id);
});

it('uses the same placement badge on upcoming home runs, home group runs and the group dashboard', function (string $kind, string $expected) {
    ($this->assign)($kind);
    $home = app(V2HomeDataService::class);
    expect($home->upcomingRuns($this->viewer)[0]['participation_status'])->toBe($expected)
        ->and($home->groupRuns($this->viewer)[0]['runs'][0]['participation_status'])->toBe($expected)
        ->and($home->applications($this->viewer)[0]['display_status'])->toBe($expected);

    $this->actingAs($this->viewer)->get(route('groups.dashboard', $this->group))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('group.calendar_activities.0.participation_status', $expected)
        ->where('group.applied_activities.0.participation_status', $expected)
        ->missing('group.calendar_activities.0.slotAssignments')
        ->missing('group.calendar_activities.0.applications'));
})->with(['roster' => ['roster', 'assigned'], 'bench' => ['bench', 'on_bench'], 'fill-in' => ['fill_in', 'filled_in']]);

it('preserves decisions, attendance and cancellation precedence on cards', function (string $applicationStatus, string $kind, string $attendance, bool $ended, bool $past, string $runStatus, string $expected) {
    $this->application->update(['status' => $applicationStatus]);
    $this->run->update(['status' => $runStatus, 'starts_at' => $past ? now()->subHour() : now()->addDay()]);
    ($this->assign)($kind, $attendance, $ended);

    expect(($this->statuses)()[$this->run->id])->toBe($expected);
})->with([
    'removed placement' => ['approved', 'roster', 'assigned', true, false, 'scheduled', 'approved'],
    'declined with stale placement' => ['declined', 'roster', 'assigned', false, false, 'scheduled', 'declined'],
    'withdrawn with stale placement' => ['withdrawn', 'roster', 'assigned', false, false, 'scheduled', 'withdrawn'],
    'checked in' => ['approved', 'roster', 'checked_in', false, true, 'ongoing', 'checked_in'],
    'late' => ['approved', 'roster', 'late', false, true, 'ongoing', 'late'],
    'missing and removed' => ['approved', 'roster', 'missing', true, true, 'ongoing', 'missing'],
    'filled in' => ['approved', 'fill_in', 'checked_in', false, true, 'ongoing', 'filled_in'],
    'late fill in' => ['approved', 'fill_in', 'late', false, true, 'ongoing', 'late'],
    'cancelled run' => ['approved', 'roster', 'checked_in', false, true, 'cancelled', 'cancelled'],
]);

it('prefers the current application over withdrawn attempts and never uses another account or guest application', function () {
    $this->application->update(['status' => ActivityApplication::STATUS_WITHDRAWN]);
    $current = ActivityApplication::factory()->create([
        'activity_id' => $this->run->id, 'user_id' => $this->viewer->id, 'status' => ActivityApplication::STATUS_PENDING,
    ]);
    ActivityApplication::factory()->create([
        'activity_id' => $this->run->id, 'user_id' => $this->viewer->id, 'status' => ActivityApplication::STATUS_WITHDRAWN,
    ]);
    ActivityApplication::factory()->create(['activity_id' => $this->run->id, 'user_id' => null]);
    ActivityApplication::factory()->create(['activity_id' => $this->run->id]);
    expect(($this->statuses)()[$this->run->id])->toBe('pending');
    $current->update(['status' => ActivityApplication::STATUS_WITHDRAWN]);
    expect(($this->statuses)()[$this->run->id])->toBe('withdrawn');

    $runs = Activity::whereKey($this->run->id)->get();
    expect(app(RunParticipationService::class)->statusesFor($runs, User::factory()->create()->id))->toBe([])
        ->and(app(RunParticipationService::class)->statusesFor($runs, null))->toBe([]);
});

it('loads only the latest assignment per character and ignores other characters and transferred ownership', function () {
    $this->run->update(['starts_at' => now()->subHour(), 'status' => Activity::STATUS_ONGOING]);
    ($this->assign)(attendance: 'missing', ended: true);
    $current = ($this->assign)(attendance: 'checked_in');
    $current->update(['assigned_at' => now()->subMinutes(10)]);
    $other = ($this->assign)(attendance: 'late');
    $otherCharacter = Character::factory()->create(['user_id' => $this->viewer->id]);
    $other->update(['character_id' => $otherCharacter->id, 'assigned_at' => now()]);
    $other->slot->update(['assigned_character_id' => $otherCharacter->id]);
    $runs = Activity::whereKey($this->run->id)->get();
    expect(app(RunParticipationService::class)->statusesFor($runs, $this->viewer->id)[$this->run->id])->toBe('checked_in')
        ->and($runs->first()->slotAssignments)->toHaveCount(1);

    $this->application->selectedCharacter->update(['user_id' => User::factory()->create()->id]);
    expect(($this->statuses)()[$this->run->id])->toBe('approved');
});

it('loads statuses for multiple runs together without mixing placements or querying per card', function () {
    ($this->assign)();
    $expected = [$this->run->id => 'assigned'];
    foreach (['pending', 'approved', 'on_bench', 'withdrawn'] as $status) {
        $run = Activity::factory()->create([
            'group_id' => $this->group->id,
            'activity_type_version_id' => $this->run->activity_type_version_id,
            'starts_at' => now()->addDay(),
            'status' => Activity::STATUS_SCHEDULED,
        ]);
        ActivityApplication::factory()->create([
            'activity_id' => $run->id, 'user_id' => $this->viewer->id, 'status' => $status,
            'selected_character_id' => $this->application->selected_character_id,
        ]);
        $expected[$run->id] = $status;
    }
    $runs = Activity::whereKey(array_keys($expected))->get();
    DB::enableQueryLog();
    try {
        DB::flushQueryLog();
        $statuses = app(RunParticipationService::class)->statusesFor($runs, $this->viewer->id);
        expect($statuses)->toHaveCount(count($expected))
            ->and(DB::getQueryLog())->toHaveCount(4);
        foreach ($expected as $activityId => $status) {
            expect($statuses[$activityId])->toBe($status);
        }
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});
