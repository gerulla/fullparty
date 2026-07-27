<?php

use App\Models\RaidPlan;
use App\Models\RaidPlanMechanic;
use App\Services\Planner\RaidPlanMechanicService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('stores ordered fixed mechanics with versioned timeline data', function () {
    $raidPlan = RaidPlan::factory()->create();
    $service = app(RaidPlanMechanicService::class);

    $first = $service->create($raidPlan, [
        'name' => 'Opening Raidwide',
        'duration_ms' => 12_500,
        'timeline' => [
            'objects' => [],
            'tracks' => [],
        ],
    ]);
    $second = $service->create($raidPlan, [
        'name' => 'First Towers',
    ]);

    expect($first->type)->toBe(RaidPlanMechanic::TYPE_FIXED)
        ->and($first->sort_order)->toBe(0)
        ->and($first->duration_ms)->toBe(12_500)
        ->and($first->timeline)->toBe([
            'objects' => [],
            'tracks' => [],
        ])
        ->and($first->timeline_schema_version)
        ->toBe(RaidPlanMechanic::CURRENT_TIMELINE_SCHEMA_VERSION)
        ->and($second->sort_order)->toBe(1)
        ->and($raidPlan->rootMechanics()->pluck('name')->all())
        ->toBe(['Opening Raidwide', 'First Towers']);
});

it('stores random mechanic options as ordered fixed children', function () {
    $raidPlan = RaidPlan::factory()->create();
    $service = app(RaidPlanMechanicService::class);
    $randomSet = $service->create($raidPlan, [
        'name' => 'Black Hole Tethers',
        'type' => RaidPlanMechanic::TYPE_RANDOM_SET,
        'duration_ms' => 10_000,
        'timeline' => ['ignored' => true],
    ]);
    $secondPattern = $service->create($raidPlan, [
        'name' => 'East and West',
        'parent_id' => $randomSet->id,
        'sort_order' => 1,
        'selection_weight' => 2,
    ]);
    $firstPattern = $service->create($raidPlan, [
        'name' => 'North and South',
        'parent_id' => $randomSet->id,
        'sort_order' => 0,
    ]);

    expect($randomSet->duration_ms)->toBe(0)
        ->and($randomSet->timeline)->toBe([])
        ->and($secondPattern->selection_weight)->toBe(2)
        ->and($randomSet->children()->pluck('name')->all())
        ->toBe(['North and South', 'East and West']);
});

it('rejects invalid random mechanic structures', function () {
    $service = app(RaidPlanMechanicService::class);
    $raidPlan = RaidPlan::factory()->create();
    $otherRaidPlan = RaidPlan::factory()->create();
    $fixedParent = RaidPlanMechanic::factory()->for($raidPlan)->create();
    $foreignRandomSet = RaidPlanMechanic::factory()
        ->for($otherRaidPlan)
        ->randomSet()
        ->create();
    $randomSet = RaidPlanMechanic::factory()
        ->for($raidPlan)
        ->randomSet()
        ->create();

    expect(fn () => $service->create($raidPlan, [
        'name' => 'Invalid Parent',
        'parent_id' => $fixedParent->id,
    ]))->toThrow(ValidationException::class)
        ->and(fn () => $service->create($raidPlan, [
            'name' => 'Foreign Parent',
            'parent_id' => $foreignRandomSet->id,
        ]))->toThrow(ValidationException::class)
        ->and(fn () => $service->create($raidPlan, [
            'name' => 'Nested Random Set',
            'type' => RaidPlanMechanic::TYPE_RANDOM_SET,
            'parent_id' => $randomSet->id,
        ]))->toThrow(ValidationException::class);
});

it('removes mechanics with their raid plan', function () {
    $raidPlan = RaidPlan::factory()->create();
    $randomSet = RaidPlanMechanic::factory()
        ->for($raidPlan)
        ->randomSet()
        ->create();
    RaidPlanMechanic::factory()
        ->for($raidPlan)
        ->for($randomSet, 'parent')
        ->create();

    $raidPlan->forceDelete();

    expect(RaidPlanMechanic::query()->count())->toBe(0);
});
