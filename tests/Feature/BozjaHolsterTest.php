<?php

use App\Models\BozjaHolster;
use App\Models\BozjaItem;
use App\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores localized holsters with quantified Bozja items', function () {
    $holster = BozjaHolster::query()->create([
        'name' => [
            'en' => 'Delubrum setup',
            'ja' => 'グンヒルド構成',
        ],
        'notes' => 'Keep one action slot open.',
        'guide' => "## Usage\n\nUse the banner before the opener.",
    ]);
    $essence = BozjaItem::query()->create([
        'key' => 'test-essence',
        'category' => 'essences',
        'name' => ['en' => 'Test Essence'],
        'classification' => 'essence',
        'cache_weight' => 4,
    ]);
    $action = BozjaItem::query()->create([
        'key' => 'lost-test',
        'category' => 'lost_actions',
        'name' => ['en' => 'Lost Test'],
        'classification' => 'lost_action',
        'cache_weight' => 3,
    ]);

    $holster->items()->attach([
        $essence->id => ['quantity' => 2],
        $action->id => ['quantity' => 5],
    ]);

    $holster->load('items');

    expect($holster->max_capacity)->toBe(BozjaHolster::DEFAULT_MAX_CAPACITY)
        ->and($holster->type)->toBe(BozjaHolster::TYPE_PREPOP)
        ->and($holster->localizedName('ja'))->toBe('グンヒルド構成')
        ->and($holster->capacity_used)->toBe(23)
        ->and($holster->items)->toHaveCount(2)
        ->and($holster->items->firstWhere('id', $essence->id)?->pivot->quantity)->toBe(2)
        ->and($holster->guide)->toContain('## Usage');
});

it('allows a holster to have no localized name', function () {
    $holster = BozjaHolster::query()->create();

    expect($holster->localizedName())->toBeNull()
        ->and($holster->max_capacity)->toBe(BozjaHolster::DEFAULT_MAX_CAPACITY)
        ->and($holster->capacity_used)->toBe(0);
});

it('does not allow a maximum capacity above 99', function () {
    expect(fn () => BozjaHolster::query()->create(['max_capacity' => 100]))
        ->toThrow(InvalidArgumentException::class);
});

it('includes notes capacity and quantified items in live selection options without guides', function () {
    $group = Group::factory()->create();
    $prepop = BozjaHolster::create(['group_id' => $group->id, 'name' => ['en' => 'Starter'], 'notes' => 'Prepare this first']);
    $refill = BozjaHolster::create(['group_id' => $group->id, 'type' => 'refill', 'parent_holster_id' => $prepop->id]);
    $item = BozjaItem::create(['key' => 'selection-item', 'category' => 'lost_actions', 'name' => ['en' => 'Test Action'], 'classification' => 'lost_action', 'cache_weight' => 5]);
    $refill->items()->attach($item, ['quantity' => 2]);
    $options = collect(BozjaHolster::schemaOptionsForGroup($group->id))->keyBy('key');
    expect($options[(string) $prepop->id]['meta']['notes'])->toBe('Prepare this first')
        ->and($options[(string) $refill->id]['meta']['capacity_used'])->toBe(10)
        ->and($options[(string) $refill->id]['meta']['max_capacity'])->toBe(99)
        ->and($options[(string) $refill->id]['meta']['items'][0]['quantity'])->toBe(2)
        ->and($options[(string) $refill->id]['meta']['items'][0]['cache_weight'])->toBe(5)
        ->and($options[(string) $refill->id]['meta'])->not->toHaveKey('guide');
    $prepop->update(['group_id' => Group::factory()->create()->id]);
    expect(BozjaHolster::schemaOptionsForGroup($group->id))->toBe([]);
});
