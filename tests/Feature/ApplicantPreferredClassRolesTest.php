<?php

use App\Models\ActivityTypeVersion;
use App\Models\CharacterClass;
use App\Services\Groups\ApplicantQueue\ApplicationAnswerPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tanks = collect(['Paladin', 'Warrior', 'Dark Knight', 'Gunbreaker'])->map(
        fn ($name) => CharacterClass::create(['name' => $name, 'shorthand' => substr($name, 0, 3), 'role' => 'tank']),
    );
    $this->healer = CharacterClass::create(['name' => 'White Mage', 'shorthand' => 'WHM', 'role' => 'healer']);
    $this->version = new ActivityTypeVersion(['application_schema' => [
        ['key' => 'preferred_classes', 'type' => 'multi_select', 'source' => 'character_classes'],
    ]]);
    $this->present = fn ($values) => app(ApplicationAnswerPresenter::class)->present((object) [
        'question_key' => 'preferred_classes', 'source' => 'character_classes', 'value' => $values,
    ], $this->version);
});

it('recognizes complete roles using every class in the catalog and preserves individual choices', function () {
    $ids = $this->tanks->pluck('id')->map(fn ($id) => (string) $id)->push((string) $this->healer->id)->all();
    $answer = ($this->present)($ids);
    expect($answer['complete_roles'])->toEqualCanonicalizing(['tank', 'healer']);
    expect($answer['display_items'])->toHaveCount(5);
    expect($answer['raw_value'])->toBe($ids);
});

it('does not collapse incomplete roles even with duplicate or invalid class ids', function () {
    $ids = $this->tanks->take(3)->pluck('id')->push($this->tanks->first()->id)->push(99999)->all();
    expect(($this->present)($ids)['complete_roles'])->toBe([]);
});

it('does not treat Any or empty choices as all classes', function ($values) {
    expect(($this->present)($values)['complete_roles'])->toBe([]);
})->with([['any'], [[]], [null]]);

it('includes newly configured classes when checking a complete role', function () {
    CharacterClass::create(['name' => 'Additional Tank', 'shorthand' => 'NEW', 'role' => 'tank']);
    expect(($this->present)($this->tanks->pluck('id')->all())['complete_roles'])->toBe([]);
});
