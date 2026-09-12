<?php

use App\Models\BozjaHolster;
use App\Models\Group;
use App\Services\Groups\ApplicantQueue\ApplicationAnswerPresenter;
use App\Services\Groups\BozjaHolsterPairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('accepts standalone pre-pops only when they have no active same-group refills', function (string $refillState) {
    $group = Group::factory()->create();
    $prepop = BozjaHolster::create(['group_id' => $group->id, 'name' => ['en' => 'Standalone tank']]);
    if ($refillState !== 'none') {
        BozjaHolster::create([
            'group_id' => $refillState === 'foreign' ? Group::factory()->create()->id : $group->id,
            'type' => 'refill', 'parent_holster_id' => $prepop->id, 'is_active' => $refillState !== 'inactive',
        ]);
    }
    $service = app(BozjaHolsterPairService::class);
    $value = [['prepop_id' => (string) $prepop->id, 'refill_id' => '']];
    $options = BozjaHolster::schemaOptionsForGroup($group->id);
    if ($refillState === 'active') {
        expect(fn () => $service->validateApplicationPairs($value, $group->id, 'answers.holsters'))->toThrow(ValidationException::class);
        expect($service->pairIsAvailableInOptions(['prepop_id' => $prepop->id, 'refill_id' => null], $options))->toBeFalse();
    } else {
        $expected = [['prepop_id' => $prepop->id, 'refill_id' => null]];
        expect($service->validateApplicationPairs($value, $group->id, 'answers.holsters'))->toBe($expected)
            ->and($service->filterRememberedPairs($expected, $group->id))->toBe($expected)
            ->and($service->pairIsAvailableInOptions($expected[0], $options))->toBeTrue();
    }
})->with(['none', 'inactive', 'foreign', 'active']);

it('rejects unavailable standalone pre-pops and duplicate selections', function (string $case) {
    $group = Group::factory()->create();
    $prepop = BozjaHolster::create([
        'group_id' => $case === 'foreign' ? Group::factory()->create()->id : $group->id,
        'type' => $case === 'refill' ? 'refill' : 'prepop', 'is_active' => $case !== 'inactive',
    ]);
    $pair = ['prepop_id' => $prepop->id, 'refill_id' => null];
    $value = $case === 'duplicate' ? [$pair, $pair] : [$pair];
    expect(fn () => app(BozjaHolsterPairService::class)->validateApplicationPairs($value, $group->id, 'answers.holsters'))
        ->toThrow(ValidationException::class);
})->with(['inactive', 'foreign', 'refill', 'duplicate']);

it('does not treat a malformed refill identifier as a standalone selection', function (mixed $refill) {
    expect(app(BozjaHolsterPairService::class)->normalizePair(['prepop_id' => 1, 'refill_id' => $refill]))->toBeNull();
})->with([0, -1, 'wrong', false, ['bad']]);

it('displays standalone selections in the applicant queue without a missing refill label', function () {
    $prepop = BozjaHolster::create(['name' => ['en' => 'Standalone tank']]);
    $items = app(ApplicationAnswerPresenter::class)->presentDisplayItems('bozja_holsters', [['prepop_id' => $prepop->id, 'refill_id' => null]]);
    expect($items)->toHaveCount(1)->and($items[0])->toMatchArray([
        'label' => 'Standalone tank', 'prepop_id' => $prepop->id, 'refill_id' => null, 'refill_label' => null,
    ]);
});
