<?php

use App\Models\Group;
use App\Models\User;
use App\Rules\NotReservedGroupTag;
use App\Services\Groups\GroupExtraTagPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

it('reserves translated field labels regardless of the current language', function (string $locale) {
    app()->setLocale('en');
    $tags = [
        __('groups.index.create_modal.fields.experience_expectation.options.beginner_friendly', [], $locale),
        __('groups.index.create_modal.fields.primary_focuses.options.reclears', [], $locale),
        __('groups.common.voice_expectations.preferred', [], $locale),
        __('groups.common.language_names.en', [], $locale),
    ];
    $policy = app(GroupExtraTagPolicy::class);

    foreach ($tags as $tag) {
        expect($policy->reservedKeys())->toContain($policy->normalizedKey($tag));
    }

    expect(__('groups.common.validation.tag_covered_by_field', [], $locale))
        ->not->toBe('groups.common.validation.tag_covered_by_field');
})->with(['en', 'de', 'fr', 'ja']);

it('allows distinct extra details without blocking words inside longer tags', function () {
    $validator = Validator::make([
        'tags' => ['Blind Prog', 'Weekend Raids', 'English Learners', 'Beginner Raid Nights'],
    ], ['tags.*' => ['string', app(NotReservedGroupTag::class)]]);

    expect($validator->passes())->toBeTrue();
});

it('enforces tag filtering when updating discovery settings too', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->for($owner, 'owner')->create(['tags' => ['Weekend Raids']]);

    $this->actingAs($owner)
        ->put(route('groups.dashboard.discovery-settings.update', $group), ['tags' => ['Voice Preferred']])
        ->assertSessionHasErrors(['tags.0' => __('groups.common.validation.tag_covered_by_field')]);

    expect($group->fresh()->tags)->toBe(['Weekend Raids']);
});
