<?php

use App\Models\CalculatorAction;
use App\Models\CalculatorBuff;
use App\Models\CalculatorPotion;
use App\Models\CalculatorTrait;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('imports the tracked calculator data', function () {
    $this->artisan('calculator:import-data')
        ->expectsOutput('Imported 1233 calculator actions, 429 calculator traits, 466 calculator buffs, and 20 calculator potions.')
        ->assertSuccessful();

    expect(CalculatorAction::query()->count())->toBe(1233)
        ->and(CalculatorTrait::query()->count())->toBe(429)
        ->and(CalculatorBuff::query()->count())->toBe(466)
        ->and(CalculatorPotion::query()->count())->toBe(20);

    $heavySwing = CalculatorAction::query()
        ->where('source_id', 31)
        ->where('job_abbreviation', 'WAR')
        ->sole();

    expect($heavySwing->name)->toBe('Heavy Swing')
        ->and($heavySwing->key)->toBe('ability:tank:21:31')
        ->and($heavySwing->icon_url)->toBe('/CalculatorData/Tank/Warrior/Abilities/Heavy%20Swing/icon.png')
        ->and($heavySwing->action_category_name)->toBe('Weaponskill')
        ->and($heavySwing->timing_recast_seconds)->toBe(2.5)
        ->and($heavySwing->targeting_hostile)->toBeTrue()
        ->and($heavySwing->metadata_is_player_action)->toBeTrue()
        ->and($heavySwing->name_translations['ja'])->not->toBeEmpty();

    $tankMastery = CalculatorTrait::query()
        ->where('source_id', 318)
        ->where('job_abbreviation', 'WAR')
        ->sole();

    expect($tankMastery->name)->toBe('Tank Mastery')
        ->and($tankMastery->key)->toBe('trait:tank:21:318')
        ->and($tankMastery->icon_url)->toBe('/CalculatorData/Tank/Warrior/Traits/Tank%20Mastery/icon.png')
        ->and($tankMastery->value)->toBe(-20)
        ->and($tankMastery->localizedName('fr'))->not->toBeEmpty();

    $technicalFinish = CalculatorBuff::query()
        ->where('source_id', 2050)
        ->sole();

    expect($technicalFinish->name)->toBe('Technical Finish')
        ->and($technicalFinish->key)->toBe('combat_status:2050')
        ->and($technicalFinish->classification)->toBe('buff')
        ->and($technicalFinish->parameter_modifier)->toBe(-10)
        ->and($technicalFinish->can_remove_manually)->toBeTrue()
        ->and($technicalFinish->source_abilities)->not->toBeEmpty()
        ->and($technicalFinish->localizedName('ja'))->not->toBeEmpty();

    $dexterityPotion = CalculatorPotion::query()
        ->where('source_id', 44158)
        ->sole();

    expect($dexterityPotion->name)->toBe('Grade 1 Gemdraught of Dexterity')
        ->and($dexterityPotion->key)->toBe('combat_potion:44158')
        ->and($dexterityPotion->icon_url)->toBe('/CalculatorData/Potions/Grade%201%20Gemdraught%20of%20Dexterity/icon.png')
        ->and($dexterityPotion->item_level)->toBe(690)
        ->and($dexterityPotion->use_duration_seconds)->toBe(30)
        ->and($dexterityPotion->primary_stat_name)->toBe('Dexterity')
        ->and($dexterityPotion->primary_stat_normal_value)->toBe(8)
        ->and($dexterityPotion->primary_stat_high_quality_cap)->toBe(351)
        ->and($dexterityPotion->localizedPrimaryStatName('fr'))->not->toBeEmpty();
});

it('keeps missing calculator records inactive instead of deleting them', function () {
    CalculatorAction::query()->create([
        'key' => 'ability:stale:999:999',
        'source_path' => 'CalculatorData/Stale/info.json',
        'source_hash' => str_repeat('0', 64),
        'source_id' => 999,
        'kind' => 'ability',
        'name' => 'Stale Action',
        'effects' => [],
        'role' => 'Stale',
        'job_id' => 999,
        'job_name' => 'Stale',
        'job_abbreviation' => 'STL',
        'unlock_level' => 1,
        'source_payload' => [],
        'is_active' => true,
    ]);

    CalculatorBuff::query()->create([
        'key' => 'combat_status:999999',
        'source_path' => 'CalculatorData/Stale/Buff/info.json',
        'source_hash' => str_repeat('1', 64),
        'source_id' => 999999,
        'kind' => 'combat_status',
        'name' => 'Stale Buff',
        'effects' => [],
        'classification' => 'buff',
        'max_stacks' => 0,
        'party_list_priority' => 0,
        'source_abilities' => [],
        'source_payload' => [],
        'is_active' => true,
    ]);

    CalculatorPotion::query()->create([
        'key' => 'combat_potion:999999',
        'source_path' => 'CalculatorData/Stale/Potion/info.json',
        'source_hash' => str_repeat('2', 64),
        'source_id' => 999999,
        'kind' => 'combat_potion',
        'name' => 'Stale Potion',
        'effects' => [],
        'use_raw_data' => [],
        'use_raw_data_high_quality' => [],
        'stats' => [],
        'source_payload' => [],
        'is_active' => true,
    ]);

    $this->artisan('calculator:import-data')->assertSuccessful();

    expect(CalculatorAction::query()->where('key', 'ability:stale:999:999')->sole()->is_active)
        ->toBeFalse()
        ->and(CalculatorBuff::query()->where('key', 'combat_status:999999')->sole()->is_active)
        ->toBeFalse()
        ->and(CalculatorPotion::query()->where('key', 'combat_potion:999999')->sole()->is_active)
        ->toBeFalse();
});

it('serves calculator catalog and details from imported database rows', function () {
    $this->artisan('calculator:import-data')->assertSuccessful();
    $admin = User::factory()->admin()->create();

    $catalog = $this->actingAs($admin)
        ->get(route('calculator.catalog', ['locale' => 'en']))
        ->assertOk()
        ->json();

    $warrior = collect($catalog['jobs'])
        ->firstWhere('abbreviation', 'WAR');
    $heavySwing = CalculatorAction::query()
        ->where('source_id', 31)
        ->where('job_abbreviation', 'WAR')
        ->sole();
    $catalogHeavySwing = collect($warrior['actions'])
        ->firstWhere('sourceId', 31);

    expect($warrior['classIconPath'])->toBe('/CalculatorData/Tank/Warrior/class-icon.png')
        ->and($catalogHeavySwing['id'])->toBe($heavySwing->id)
        ->and($catalogHeavySwing['detailUrl'])->toBe('/actions/'.$heavySwing->id)
        ->and($catalogHeavySwing['actionCategory'])->toBe('Weaponskill');

    $this->actingAs($admin)
        ->get(route('calculator.actions.show', [
            'calculatorAction' => $heavySwing,
            'locale' => 'fr',
        ]))
        ->assertOk()
        ->assertJsonPath('id', $heavySwing->id)
        ->assertJsonPath('source_id', 31)
        ->assertJsonPath('action_category.name', 'Weaponskill')
        ->assertJsonPath('timing.recast_seconds', 2.5)
        ->assertJsonPath('range.target_yalms', -1)
        ->assertJsonPath('job.abbreviation', 'WAR');

    $tankMastery = CalculatorTrait::query()
        ->where('source_id', 318)
        ->where('job_abbreviation', 'WAR')
        ->sole();

    $this->actingAs($admin)
        ->get(route('calculator.traits.show', [
            'calculatorTrait' => $tankMastery,
            'locale' => 'ja',
        ]))
        ->assertOk()
        ->assertJsonPath('id', $tankMastery->id)
        ->assertJsonPath('source_id', 318)
        ->assertJsonPath('job.abbreviation', 'WAR')
        ->assertJsonPath('value', -20);
});
