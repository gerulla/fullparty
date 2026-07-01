<?php

use App\Events\ActivityManagementUpdated;
use App\Models\Activity;
use App\Services\Groups\ActivityManagementRealtimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('broadcasts small activity management patches unchanged', function () {
    $activity = Activity::factory()->create();

    Event::fake([ActivityManagementUpdated::class]);

    app(ActivityManagementRealtimeService::class)->broadcastPatch($activity, [
        'pending_application_count' => 2,
    ]);

    Event::assertDispatched(
        ActivityManagementUpdated::class,
        fn (ActivityManagementUpdated $event): bool => $event->groupId === $activity->group_id
            && $event->activityId === $activity->id
            && $event->patch === ['pending_application_count' => 2]
    );
});

it('broadcasts a reload patch when an activity management patch is too large', function () {
    $activity = Activity::factory()->create();

    Event::fake([ActivityManagementUpdated::class]);

    app(ActivityManagementRealtimeService::class)->broadcastPatch($activity, [
        'updated_slots' => [
            [
                'id' => 1,
                'notes' => str_repeat('x', 10000),
            ],
        ],
    ]);

    Event::assertDispatched(
        ActivityManagementUpdated::class,
        fn (ActivityManagementUpdated $event): bool => $event->groupId === $activity->group_id
            && $event->activityId === $activity->id
            && $event->patch === [
                'type' => 'reload',
                'reason' => 'payload_too_large',
            ]
    );
});
