<?php

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
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
