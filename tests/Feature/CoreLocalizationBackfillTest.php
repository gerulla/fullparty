<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\Group;
use App\Support\SeedData\ActivityLocalizationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('backfills official names and defaults without republishing or replacing custom translations', function () {
    $name = array_fill_keys(['en', 'de', 'fr', 'ja'], 'Forked Tower of Blood');
    $name['de'] = 'Unser eigener Turmname';
    $type = ActivityType::factory()->withPublishedVersion()->create([
        'draft_name' => $name,
        'draft_progress_schema' => ['milestones' => [[
            'key' => 'demon_tablet', 'label' => ['en' => 'Demon Tablet'],
            'fflogs_encounter_id' => 1234, 'order' => 1,
        ]]],
    ]);
    $version = $type->currentPublishedVersion;
    $group = Group::factory()->create(['membership_application_schema' => [[
        'id' => 'are_you_a_gamer', 'type' => 'toggle', 'name' => ['en' => 'Are you a gamer?'],
        'description' => [], 'required' => true, 'options' => [],
    ]]]);
    $activity = Activity::factory()->create([
        'group_id' => $group->id, 'activity_type_id' => $type->id, 'activity_type_version_id' => $version->id,
    ]);
    $slot = ActivitySlot::factory()->for($activity)->create([
        'slot_kind' => ActivitySlot::SLOT_KIND_FILL_IN, 'slot_key' => 'fill-in-7',
        'slot_label' => array_fill_keys(['en', 'de', 'fr', 'ja'], 'Fill in 7'), 'position_in_group' => 7,
    ]);
    $originals = [$type->refresh()->getRawOriginal(), $version->refresh()->getRawOriginal(), $slot->refresh()->getRawOriginal()];

    $this->artisan('localization:backfill', ['--dry-run' => true])->assertSuccessful();
    expect($type->fresh()->getRawOriginal())->toBe($originals[0]);
    expect($version->fresh()->getRawOriginal())->toBe($originals[1]);
    expect($slot->fresh()->getRawOriginal())->toBe($originals[2]);

    $this->artisan('localization:backfill')->assertSuccessful();
    $type->refresh();
    $version->refresh();
    expect($type->draft_name)->toBe([
        'en' => 'The Forked Tower: Blood', 'de' => 'Unser eigener Turmname',
        'fr' => 'La Tour fourchue de la Force', 'ja' => 'フォークタワー：力の塔',
    ]);
    expect($version->name)->toBe($type->draft_name);
    expect($type->current_published_version_id)->toBe($version->id);
    expect(ActivityTypeVersion::where('activity_type_id', $type->id)->count())->toBe(1);
    expect($version->progress_schema['milestones'][0])->toMatchArray([
        'key' => 'demon_tablet', 'fflogs_encounter_id' => 1234, 'order' => 1,
    ]);
    expect($version->progress_schema['milestones'][0]['label']['fr'])->toBe('Muraille démonique');
    expect($type->getRawOriginal('updated_at'))->toBe($originals[0]['updated_at']);
    expect($version->getRawOriginal('updated_at'))->toBe($originals[1]['updated_at']);
    expect($slot->fresh()->slot_label['ja'])->toBe('補充 7');
    expect($slot->fresh()->getRawOriginal('updated_at'))->toBe($originals[2]['updated_at']);
    expect($group->fresh()->membership_application_schema[0]['name']['fr'])->toBe('Jouez-vous aux jeux vidéo ?');
    $after = $version->getRawOriginal();
    $this->artisan('localization:backfill')->assertSuccessful();
    expect($version->fresh()->getRawOriginal())->toBe($after);
});

it('localizes new seeds recursively and leaves unknown content and IDs untouched', function () {
    $catalog = app(ActivityLocalizationCatalog::class);
    $schema = [
        'id' => 123, 'key' => 'Forked Tower of Blood',
        'name' => ['en' => 'Forked Tower of Blood', 'fr' => 'Copied old wording'],
        'custom' => ['en' => 'Our own guide', 'fr' => 'Notre guide'],
    ];
    $localized = $catalog->localize($schema, freshSeed: true);
    expect($localized['name']['fr'])->toBe('La Tour fourchue de la Force');
    expect($localized['id'])->toBe(123);
    expect($localized['key'])->toBe('Forked Tower of Blood');
    expect($localized['custom'])->toBe($schema['custom']);
});

it('localizes the default cancellation reason without changing moderator wording', function () {
    app()->setLocale('ja');
    $application = new ActivityApplication(['status' => 'cancelled', 'review_reason' => 'Run cancelled.']);
    expect($application->localizedReviewReason())->toBe('募集が中止されました。');
    expect($application->review_reason)->toBe('Run cancelled.');
    $application->review_reason = 'We will reschedule for Friday.';
    expect($application->localizedReviewReason())->toBe('We will reschedule for Friday.');
});
