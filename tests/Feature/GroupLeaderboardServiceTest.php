<?php

use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use App\Models\Character;
use App\Models\Group;
use App\Services\Groups\GroupLeaderboardService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

afterEach(fn () => CarbonImmutable::setTestNow());

it('rejects nonpositive leaderboard batch sizes', function (int $size) {
    expect(fn () => app(GroupLeaderboardService::class)->batchSize($size))
        ->toThrow(InvalidArgumentException::class);
})->with([0, -1]);

it('preserves completed roster precedence, history attendance and rankings across batches', function () {
    CarbonImmutable::setTestNow('2026-05-26 12:00:00');
    $group = Group::factory()->create();
    $alice = Character::factory()->create(['name' => 'Alice']);
    $bob = Character::factory()->create(['name' => 'Bob']);
    $excluded = Character::factory()->create(['name' => 'Excluded']);
    $runs = collect(['2026-05-25 12:00:00', '2026-03-01 12:00:00', '2025-10-01 12:00:00'])
        ->map(fn ($date) => Activity::factory()->create([
            'group_id' => $group->id, 'status' => Activity::STATUS_COMPLETE, 'starts_at' => $date,
        ]));
    $slots = $runs->map(fn ($run) => $run->slots()->orderBy('id')->firstOrFail());
    $assign = function ($run, $slot, $character, $status = ActivitySlotAssignment::STATUS_CHECKED_IN) use ($group) {
        return ActivitySlotAssignment::query()->create([
            'group_id' => $group->id, 'activity_id' => $run->id, 'activity_slot_id' => $slot->id,
            'character_id' => $character->id, 'attendance_status' => $status,
            'assigned_at' => $run->starts_at ?? '2026-05-25 12:00:00', 'ended_at' => now(),
        ]);
    };

    // Any current non-bench roster excludes the entire run's assignment history.
    $slots[0]->update(['assigned_character_id' => $alice->id, 'is_host' => true, 'is_raid_leader' => true]);
    $assign($runs[0], $slots[0], $excluded);
    ActivitySlot::factory()->create([
        'activity_id' => $runs[0]->id, 'slot_key' => 'duplicate',
        'assigned_character_id' => $alice->id, 'is_host' => true, 'is_raid_leader' => true,
    ]);
    $bench = ActivitySlot::factory()->create([
        'activity_id' => $runs[0]->id, 'slot_key' => 'bench-extra', 'group_key' => 'bench',
        'assigned_character_id' => $excluded->id, 'is_host' => true, 'is_raid_leader' => true,
    ]);
    $assign($runs[0], $bench, $excluded);

    // History is inserted out of activity order, with duplicate and excluded attendance rows.
    $assign($runs[2], $slots[2], $bob, ActivitySlotAssignment::STATUS_LATE);
    $assign($runs[1], $slots[1], $bob, ActivitySlotAssignment::STATUS_ASSIGNED);
    $assign($runs[1], $slots[1], $bob);
    $assign($runs[1], $slots[1], $excluded, ActivitySlotAssignment::STATUS_MISSING);

    // Legacy completion flag and null start date still count, using the slot timestamp.
    $legacy = Activity::factory()->create(['group_id' => $group->id, 'status' => Activity::STATUS_ASSIGNED]);
    $legacy->forceFill(['is_completed' => true, 'starts_at' => null])->save();
    $legacy->slots()->first()->forceFill([
        'assigned_character_id' => $alice->id, 'is_host' => true, 'is_raid_leader' => true,
        'updated_at' => '2026-05-26 11:00:00',
    ])->save();
    foreach ([['group_id' => $group->id, 'status' => Activity::STATUS_ASSIGNED], ['status' => Activity::STATUS_COMPLETE]] as $attributes) {
        Activity::factory()->create($attributes)->slots()->first()->update([
            'assigned_character_id' => $excluded->id, 'is_host' => true, 'is_raid_leader' => true,
        ]);
    }

    $service = app(GroupLeaderboardService::class);
    $expected = $service->batchSize(100)->calculate($group);
    expect($expected['summary'])->toBe([
        'total_participations' => 4, 'ranked_participants' => 2,
        'raid_leader_participations' => 2, 'host_participations' => 2, 'completed_hosted_runs' => 2,
    ])->and(array_column($expected['rankings']['overall'], 'count'))->toBe([2, 2])
        ->and(array_column(array_column($expected['rankings']['overall'], 'character'), 'name'))->toBe(['Alice', 'Bob'])
        ->and($expected['rankings']['overall'][0]['latest_activity_at'])->toBe('2026-05-26T11:00:00+00:00')
        ->and($expected['rankings']['host_success'][0]['hosted_runs'])->toBe(2);

    foreach ([1, 2, 25] as $size) {
        $configured = $service->batchSize($size);
        expect($configured->calculate($group))->toBe($expected);
        foreach (['overall', 'raid_leaders', 'hosts'] as $category) {
            foreach (['all_time', 'past_6_months', 'past_30_days'] as $period) {
                expect($configured->ranking($group, $category, $period, 'all'))
                    ->toBe($service->batchSize(100)->ranking($group, $category, $period, 'all'));
            }
        }
    }
    expect(array_column($service->ranking($group, 'overall', 'past_6_months', 'all'), 'count'))->toBe([2, 1])
        ->and(array_column($service->ranking($group, 'overall', 'past_30_days', 'all'), 'count'))->toBe([2]);
});

it('keeps the configured leaderboard batch size local to its instance for both calculations', function () {
    $group = Group::factory()->create();
    Activity::factory()->count(3)->create(['group_id' => $group->id, 'status' => Activity::STATUS_COMPLETE]);
    $service = app(GroupLeaderboardService::class);
    $configured = $service->batchSize(1);

    DB::enableQueryLog();
    try {
        $configured->calculate($group);
        $calculationQueries = collect(DB::getQueryLog())->pluck('query');
        DB::flushQueryLog();
        $configured->ranking($group, 'overall');
        $rankingQueries = collect(DB::getQueryLog())->pluck('query');
        DB::flushQueryLog();
        $service->calculate($group);
        $defaultQueries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    foreach ([$calculationQueries, $rankingQueries] as $queries) {
        expect($queries->contains(fn ($sql) => str_contains($sql, 'from "activities"') && str_ends_with($sql, 'limit 1')))->toBeTrue();
    }
    expect($defaultQueries->contains(fn ($sql) => str_contains($sql, 'from "activities"') && str_ends_with($sql, 'limit 25')))->toBeTrue();
});
