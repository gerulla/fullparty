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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()->instance('env', 'local');
    Http::preventStrayRequests();
    $this->travelTo(now()->startOfSecond());
    $this->user = User::factory()->create();
    $this->character = Character::factory()->primary()->create(['user_id' => $this->user->id]);
    $this->group = Group::factory()->create();
    $this->version = ActivityTypeVersion::factory()->create([
        'layout_schema' => [], 'application_schema' => [], 'progress_schema' => [], 'prog_points' => [],
    ]);
    $this->application = function (array $runAttributes = [], array $applicationAttributes = []): ActivityApplication {
        $run = Activity::factory()->create([
            'group_id' => $this->group->id,
            'activity_type_version_id' => $this->version->id,
            'status' => Activity::STATUS_SCHEDULED,
            'starts_at' => now()->addDay(),
            ...$runAttributes,
        ]);

        return ActivityApplication::factory()->create([
            'user_id' => $this->user->id,
            'selected_character_id' => $this->character->id,
            'activity_id' => $run->id,
            'status' => ActivityApplication::STATUS_APPROVED,
            ...$applicationAttributes,
        ]);
    };
    $this->assignment = function (ActivityApplication $application, string $attendance = 'assigned', string $kind = 'roster', bool $ended = false): ActivitySlotAssignment {
        $slot = ActivitySlot::factory()->create([
            'activity_id' => $application->activity_id,
            'slot_key' => (string) Str::uuid(),
            'slot_kind' => $kind,
            'group_key' => $kind === 'roster' ? 'party-a' : $kind,
            'assigned_character_id' => $ended ? null : $application->selected_character_id,
        ]);

        return ActivitySlotAssignment::create([
            'activity_id' => $application->activity_id,
            'group_id' => $application->activity->group_id,
            'activity_slot_id' => $slot->id,
            'character_id' => $application->selected_character_id,
            'application_id' => $application->id,
            'attendance_status' => $attendance,
            'assigned_at' => now()->subDays(3),
            'ended_at' => $ended ? now()->subDay() : null,
        ]);
    };
});

it('limits and sorts four runs per character by run time, including unverified characters', function (bool $history) {
    $characters = collect([$this->character,
        Character::factory()->unverified()->create(['user_id' => $this->user->id]),
    ]);
    $expected = [];
    foreach ($characters as $character) {
        foreach ([6, 2, 5, 1, 4, 3] as $days) {
            $application = ($this->application)([
                'starts_at' => $history ? now()->subDays($days) : now()->addDays($days),
            ], [
                'selected_character_id' => $character->id,
                'created_at' => now()->subDays(10 - $days),
                'edited_at' => now(),
            ]);
            if ($days <= 4) {
                $expected[$character->id][$days] = $application->id;
            }
        }
    }

    $data = collect($history
        ? app(V2HomeDataService::class)->applicationHistory($this->user)
        : app(V2HomeDataService::class)->applications($this->user));
    expect($data)->toHaveCount(8);
    foreach ($characters as $character) {
        ksort($expected[$character->id]);
        expect($data->where('selected_character_id', $character->id)->pluck('id')->all())
            ->toBe(array_values($expected[$character->id]));
    }
})->with(['upcoming' => false, 'history' => true]);

it('loads past runs lazily using the start time boundary even if completion has not been recorded', function () {
    $past = ($this->application)(['starts_at' => now()->subSecond(), 'status' => Activity::STATUS_ONGOING]);
    $atBoundary = ($this->application)(['starts_at' => now()]);
    ($this->application)(['starts_at' => null]);
    ($this->application)(['starts_at' => now()->subDays(2), 'status' => Activity::STATUS_DRAFT]);

    $this->actingAs($this->user)->get(route('dashboard', ['locale' => 'en']))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('applicationHistory')
            ->loadDeferredProps('v2-application-history', fn (Assert $page) => $page
                ->has('applicationHistory', 1)
                ->where('applicationHistory.0.id', $past->id)
                ->missing('applications')
                ->missing('applicationHistory.0.guest_access_token')
                ->missing('applicationHistory.0.run.secret_key')));
    expect(array_column(app(V2HomeDataService::class)->applications($this->user), 'id'))->toBe([$atBoundary->id]);
});

it('excludes other accounts, transferred characters and banned groups before applying the limit', function (bool $history) {
    $when = $history ? now()->subDay() : now()->addDay();
    $mine = ($this->application)(['starts_at' => $when]);
    $other = User::factory()->create();
    $otherCharacter = Character::factory()->create(['user_id' => $other->id]);
    ($this->application)(['starts_at' => $when], ['user_id' => $other->id, 'selected_character_id' => $otherCharacter->id]);
    ($this->application)(['starts_at' => $when], ['selected_character_id' => $otherCharacter->id]);
    $bannedGroup = Group::factory()->create();
    $bannedGroup->bans()->create(['user_id' => $this->user->id, 'banned_by_user_id' => $bannedGroup->owner_id]);
    ($this->application)(['starts_at' => $when, 'group_id' => $bannedGroup->id]);

    $data = $history ? app(V2HomeDataService::class)->applicationHistory($this->user) : app(V2HomeDataService::class)->applications($this->user);
    expect(array_column($data, 'id'))->toBe([$mine->id]);
})->with(['upcoming' => false, 'history' => true]);

it('keeps upcoming application decisions distinct from active roster placement', function (string $status, ?string $kind, string $expected) {
    $application = ($this->application)([], ['status' => $status]);
    if ($kind !== null) {
        ($this->assignment)($application, kind: $kind);
    }
    $row = app(V2HomeDataService::class)->applications($this->user)[0];
    expect($row['status'])->toBe($status)->and($row['display_status'])->toBe($expected);
})->with([
    'approved without a placement' => ['approved', null, 'approved'],
    'assigned to roster' => ['approved', 'roster', 'assigned'],
    'assigned to bench' => ['on_bench', 'bench', 'on_bench'],
    'pending' => ['pending', null, 'pending'],
    'declined despite an old slot' => ['declined', 'roster', 'declined'],
    'withdrawn' => ['withdrawn', null, 'withdrawn'],
]);

it('shows recorded historical participation without assuming every approved applicant attended', function (string $attendance, string $kind, bool $ended, string $expected) {
    $application = ($this->application)(['starts_at' => now()->subDays(2), 'status' => Activity::STATUS_COMPLETE]);
    ($this->assignment)($application, $attendance, $kind, $ended);
    expect(app(V2HomeDataService::class)->applicationHistory($this->user)[0]['display_status'])->toBe($expected);
})->with([
    'checked in' => ['checked_in', 'roster', false, 'checked_in'],
    'late' => ['late', 'roster', false, 'late'],
    'missing after removal' => ['missing', 'roster', true, 'missing'],
    'filled in' => ['checked_in', 'fill_in', false, 'filled_in'],
    'late fill in' => ['late', 'fill_in', false, 'late'],
    'missing fill in' => ['missing', 'fill_in', true, 'missing'],
    'standby' => ['assigned', 'bench', false, 'on_bench'],
    'no attendance recorded' => ['assigned', 'roster', false, 'assigned'],
    'removed without attendance' => ['assigned', 'roster', true, 'approved'],
]);

it('uses the latest assignment for the selected character rather than earlier missing or another characters attendance', function () {
    $application = ($this->application)(['starts_at' => now()->subDays(2)]);
    ($this->assignment)($application, 'missing', ended: true);
    $current = ($this->assignment)($application, 'checked_in');
    $current->update(['assigned_at' => now()->subDays(2)]);
    $otherCharacter = Character::factory()->create(['user_id' => $this->user->id]);
    $other = ($this->assignment)($application, 'late');
    $other->update(['character_id' => $otherCharacter->id, 'assigned_at' => now()->subDay()]);
    $other->slot->update(['assigned_character_id' => $otherCharacter->id]);

    expect(app(V2HomeDataService::class)->applicationHistory($this->user)[0]['display_status'])->toBe('checked_in');
});

it('shows cancellation instead of stale placement or attendance', function () {
    $application = ($this->application)(['starts_at' => now()->subDay(), 'status' => Activity::STATUS_CANCELLED]);
    ($this->assignment)($application, 'missing', ended: true);
    expect(app(V2HomeDataService::class)->applicationHistory($this->user)[0]['display_status'])->toBe('cancelled');
});
