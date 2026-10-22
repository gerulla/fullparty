<?php

use App\Models\Group;
use App\Models\User;
use App\Services\Groups\PopularGroupTags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('ranks tags by distinct visible groups and merges case and whitespace differences', function () {
    $owner = User::factory()->create();
    Group::factory()->for($owner, 'owner')->create(['tags' => [' Weekend Raids ', 'WEEKEND RAIDS', 'Blind Prog', 'English', 'Voice Preferred', 'Progression', 'Reclears', 'Beginner Friendly', '', null, str_repeat('x', 51)]]);
    Group::factory()->for($owner, 'owner')->create(['tags' => ['weekend raids', 'Late Night']]);
    Group::factory()->for($owner, 'owner')->create(['tags' => ['late night']]);
    Group::factory()->for($owner, 'owner')->hidden()->create(['tags' => ['Hidden Tag', 'Blind Prog']]);

    expect(app(PopularGroupTags::class)->suggestions())->toBe(['Late Night', 'Weekend Raids', 'Blind Prog']);
});

it('provides only the eight most used tags to the creation page', function () {
    $owner = User::factory()->create();
    $tags = array_map(fn (int $index) => sprintf('Tag %02d', $index), range(1, 10));
    Group::factory()->for($owner, 'owner')->create(['tags' => [...$tags, 'English', 'Midcore', 'Maps']]);
    Group::factory()->for($owner, 'owner')->create(['tags' => ['Tag 10', 'Tag 09']]);

    $this->actingAs($owner)->get(route('groups.create', ['locale' => 'en']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('V2/Groups/Create')
            ->where('popularTags', ['Tag 09', 'Tag 10', 'Tag 01', 'Tag 02', 'Tag 03', 'Tag 04', 'Tag 05', 'Tag 06']));
});

it('returns no suggestions when groups have no usable tags', function () {
    Group::factory()->create(['tags' => []]);
    Group::factory()->create(['tags' => null]);

    expect(app(PopularGroupTags::class)->suggestions())->toBe([]);
});
