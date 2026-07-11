<?php

use App\Models\Activity;
use App\Models\ActivityTypeVersion;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exports an accessible upcoming run as an ics calendar event', function () {
    $group = Group::factory()->open()->create([
        'name' => 'Calendar Test Group',
    ]);
    $group->features()->update(['calendar_sync_enabled' => true]);
    $version = ActivityTypeVersion::factory()->create([
        'name' => ['en' => 'Forked Tower of Blood'],
        'prog_points' => [
            ['key' => 'demon-tablet', 'label' => ['en' => 'Demon Tablet']],
        ],
    ]);
    $startsAt = now()->addDay()->startOfHour();
    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $version->activity_type_id,
        'activity_type_version_id' => $version->id,
        'title' => null,
        'notes' => 'Bring food and pots.',
        'status' => Activity::STATUS_UPCOMING,
        'starts_at' => $startsAt,
        'duration_hours' => 3,
        'target_prog_point_key' => 'demon-tablet',
        'is_public' => true,
    ]);

    $response = $this->get(route('groups.activities.calendar', [
        'group' => $group,
        'activity' => $activity,
    ]));

    $response
        ->assertOk()
        ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
        ->assertHeader('Content-Disposition', 'attachment; filename="fullparty-run-'.$activity->id.'.ics"');

    $calendar = str_replace("\r\n ", '', $response->getContent());

    expect($calendar)
        ->toContain('BEGIN:VCALENDAR')
        ->toContain('SUMMARY:Forked Tower of Blood')
        ->toContain('DTSTART:'.$startsAt->utc()->format('Ymd\THis\Z'))
        ->toContain('DTEND:'.$startsAt->addHours(3)->utc()->format('Ymd\THis\Z'))
        ->toContain('Group: Calendar Test Group')
        ->toContain('Progress point: Demon Tablet')
        ->toContain('Bring food and pots.')
        ->toContain('END:VCALENDAR');
});

it('does not export inaccessible or archived runs', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->hidden()->create([
        'owner_id' => $owner->id,
    ]);
    $group->features()->update(['calendar_sync_enabled' => true]);
    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'status' => Activity::STATUS_UPCOMING,
        'starts_at' => now()->addDay(),
    ]);
    $route = route('groups.activities.calendar', [
        'group' => $group,
        'activity' => $activity,
    ]);

    $this->get($route)->assertNotFound();
    $this->actingAs($owner)->get($route)->assertOk();

    $activity->update(['status' => Activity::STATUS_CANCELLED]);

    $this->actingAs($owner)->get($route)->assertNotFound();
});

it('does not export runs when calendar sync is disabled for the group', function () {
    $group = Group::factory()->open()->create();
    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'status' => Activity::STATUS_UPCOMING,
        'starts_at' => now()->addDay(),
    ]);

    $this->get(route('groups.activities.calendar', [
        'group' => $group,
        'activity' => $activity,
    ]))->assertNotFound();
});
