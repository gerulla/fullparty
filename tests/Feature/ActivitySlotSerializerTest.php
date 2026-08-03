<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\CharacterClass;
use App\Models\PhantomJob;
use App\Services\Groups\ActivitySlotBench;
use App\Services\Groups\ActivitySlotSerializer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('serializes slot field values in activity type schema order', function () {
    $activityType = ActivityType::factory()->create();
    $activityTypeVersion = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $activityType->id,
        'slot_schema' => [
            [
                'key' => 'character_class',
                'label' => ['en' => 'Character Class'],
                'type' => 'single_select',
                'source' => 'character_classes',
            ],
            [
                'key' => 'phantom_job',
                'label' => ['en' => 'Phantom Job'],
                'type' => 'single_select',
                'source' => 'phantom_jobs',
            ],
        ],
    ]);
    $activityType->update([
        'current_published_version_id' => $activityTypeVersion->id,
    ]);

    $activity = Activity::factory()->create([
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityTypeVersion->id,
    ]);
    $slot = $activity->slots()->firstOrFail();

    $slot->fieldValues()->delete();
    $slot->fieldValues()->create([
        'field_key' => 'phantom_job',
        'field_label' => ['en' => 'Phantom Job'],
        'field_type' => 'single_select',
        'source' => 'phantom_jobs',
        'value' => null,
    ]);
    $slot->fieldValues()->create([
        'field_key' => 'character_class',
        'field_label' => ['en' => 'Character Class'],
        'field_type' => 'single_select',
        'source' => 'character_classes',
        'value' => null,
    ]);

    $slot->load(['activity.activityTypeVersion', 'assignedCharacter', 'compositionHints', 'fieldValues', 'assignments']);

    $serializedSlot = app(ActivitySlotSerializer::class)->serialize($slot);

    expect($serializedSlot['field_values']->pluck('field_key')->all())
        ->toBe(['character_class', 'phantom_job']);
});

it('serializes application review warning state for stale assigned slots', function () {
    $activity = Activity::factory()->create();
    $application = ActivityApplication::factory()->approved()->create([
        'activity_id' => $activity->id,
    ]);
    $slot = $activity->slots()->firstOrFail();
    $slot->update([
        'assigned_character_id' => $application->selected_character_id,
        'application_review_required_application_id' => $application->id,
        'application_review_required_at' => now(),
    ]);

    $slot->load(['activity.activityTypeVersion', 'assignedCharacter', 'compositionHints', 'fieldValues', 'assignments']);

    $serializedSlot = app(ActivitySlotSerializer::class)->serialize($slot);

    expect($serializedSlot['application_review_required'])->toBeTrue()
        ->and($serializedSlot['application_review_required_application_id'])->toBe($application->id)
        ->and($serializedSlot['application_review_required_at'])->not->toBeNull();
});

it('serializes dynamic application matches for assigned roster slots', function () {
    $characterClass = CharacterClass::query()->create([
        'name' => 'Astrologian',
        'shorthand' => 'AST',
        'role' => 'healer',
    ]);
    $assignedPhantomJob = PhantomJob::query()->create([
        'name' => 'Phantom Geomancer',
        'max_level' => 20,
    ]);
    $preferredPhantomJob = PhantomJob::query()->create([
        'name' => 'Phantom Time Mage',
        'max_level' => 20,
    ]);
    $activityType = ActivityType::factory()->create();
    $activityTypeVersion = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $activityType->id,
        'layout_schema' => [
            'groups' => [[
                'key' => 'party-a',
                'label' => ['en' => 'Party A'],
                'size' => 1,
            ]],
        ],
        'slot_schema' => [
            [
                'key' => 'character_class',
                'label' => ['en' => 'Character Class'],
                'type' => 'single_select',
                'source' => 'character_classes',
            ],
            [
                'key' => 'phantom_job',
                'label' => ['en' => 'Phantom Job'],
                'type' => 'single_select',
                'source' => 'phantom_jobs',
            ],
        ],
        'application_schema' => [
            [
                'key' => 'preferred_party',
                'label' => ['en' => 'Preferred Party'],
                'type' => 'multi_select',
                'source' => 'static_options',
                'options' => [
                    ['key' => 'party-a', 'label' => ['en' => 'Party A']],
                    ['key' => 'party-b', 'label' => ['en' => 'Party B']],
                ],
            ],
            [
                'key' => 'preferred_character_class',
                'label' => ['en' => 'Preferred Class'],
                'type' => 'multi_select',
                'source' => 'character_classes',
            ],
            [
                'key' => 'preferred_phantom_job',
                'label' => ['en' => 'Preferred Phantom Job'],
                'type' => 'multi_select',
                'source' => 'phantom_jobs',
            ],
        ],
    ]);
    $activityType->update(['current_published_version_id' => $activityTypeVersion->id]);

    $activity = Activity::factory()->create([
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityTypeVersion->id,
    ]);
    $application = ActivityApplication::factory()->approved()->create([
        'activity_id' => $activity->id,
    ]);
    $application->answers()->delete();
    $application->answers()->createMany([
        [
            'question_key' => 'preferred_party',
            'question_label' => ['en' => 'Preferred Party'],
            'question_type' => 'multi_select',
            'source' => 'static_options',
            'value' => ['party-a'],
        ],
        [
            'question_key' => 'preferred_character_class',
            'question_label' => ['en' => 'Preferred Class'],
            'question_type' => 'multi_select',
            'source' => 'character_classes',
            'value' => [(string) $characterClass->id],
        ],
        [
            'question_key' => 'preferred_phantom_job',
            'question_label' => ['en' => 'Preferred Phantom Job'],
            'question_type' => 'multi_select',
            'source' => 'phantom_jobs',
            'value' => [(string) $preferredPhantomJob->id],
        ],
    ]);

    $slot = $activity->slots()->firstOrFail();
    $slot->update(['assigned_character_id' => $application->selected_character_id]);
    $slot->fieldValues()->where('field_key', 'character_class')->update(['value' => (string) $characterClass->id]);
    $slot->fieldValues()->where('field_key', 'phantom_job')->update(['value' => (string) $assignedPhantomJob->id]);
    ActivitySlotAssignment::query()->create([
        'activity_id' => $activity->id,
        'group_id' => $activity->group_id,
        'activity_slot_id' => $slot->id,
        'character_id' => $application->selected_character_id,
        'application_id' => $application->id,
        'assignment_source' => ActivitySlotAssignment::SOURCE_APPLICATION,
        'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
        'assigned_at' => now(),
    ]);

    $slot->load([
        'activity.activityTypeVersion',
        'assignedCharacter',
        'compositionHints',
        'fieldValues',
        'assignments.application.answers',
    ]);

    $matches = app(ActivitySlotSerializer::class)->serialize($slot)['application_matches'];

    expect(collect($matches)->pluck('abbreviation')->all())->toBe(['P', 'C', 'PJ'])
        ->and(collect($matches)->pluck('matches')->all())->toBe([true, true, false]);
});

it('treats bench placement as a party match when the application allows standby', function () {
    $activityType = ActivityType::factory()->create();
    $activityTypeVersion = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $activityType->id,
        'layout_schema' => [
            'groups' => [[
                'key' => 'party-a',
                'label' => ['en' => 'Party A'],
                'size' => 1,
            ]],
        ],
        'slot_schema' => [],
        'application_schema' => [
            [
                'key' => 'preferred_party',
                'label' => ['en' => 'Preferred Party'],
                'type' => 'multi_select',
                'source' => 'static_options',
                'options' => [
                    ['key' => 'party-a', 'label' => ['en' => 'Party A']],
                    ['key' => 'party-b', 'label' => ['en' => 'Party B']],
                ],
            ],
            [
                'key' => 'can_be_on_standby',
                'label' => ['en' => 'Can be on standby?'],
                'type' => 'checkbox',
            ],
        ],
    ]);
    $activityType->update(['current_published_version_id' => $activityTypeVersion->id]);

    $activity = Activity::factory()->create([
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityTypeVersion->id,
    ]);
    $application = ActivityApplication::factory()->approved()->create([
        'activity_id' => $activity->id,
    ]);
    $application->answers()->delete();
    $application->answers()->createMany([
        [
            'question_key' => 'preferred_party',
            'question_label' => ['en' => 'Preferred Party'],
            'question_type' => 'multi_select',
            'source' => 'static_options',
            'value' => ['party-a'],
        ],
        [
            'question_key' => 'can_be_on_standby',
            'question_label' => ['en' => 'Can be on standby?'],
            'question_type' => 'checkbox',
            'source' => null,
            'value' => true,
        ],
    ]);

    $benchSlot = $activity->slots()->create([
        'slot_kind' => ActivitySlot::SLOT_KIND_BENCH,
        'group_key' => ActivitySlotBench::GROUP_KEY,
        'group_label' => ['en' => 'Bench'],
        'slot_key' => 'bench-slot-1',
        'slot_label' => ['en' => 'Bench 1'],
        'position_in_group' => 1,
        'sort_order' => 99,
        'assigned_character_id' => $application->selected_character_id,
    ]);
    ActivitySlotAssignment::query()->create([
        'activity_id' => $activity->id,
        'group_id' => $activity->group_id,
        'activity_slot_id' => $benchSlot->id,
        'character_id' => $application->selected_character_id,
        'application_id' => $application->id,
        'assignment_source' => ActivitySlotAssignment::SOURCE_APPLICATION,
        'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
        'assigned_at' => now(),
    ]);

    $benchSlot->load([
        'activity.activityTypeVersion',
        'assignedCharacter',
        'compositionHints',
        'fieldValues',
        'assignments.application.answers',
    ]);

    $partyMatch = collect(app(ActivitySlotSerializer::class)->serialize($benchSlot)['application_matches'])
        ->firstWhere('abbreviation', 'P');

    expect($partyMatch)->not->toBeNull()
        ->and($partyMatch['matches'])->toBeTrue();
});
