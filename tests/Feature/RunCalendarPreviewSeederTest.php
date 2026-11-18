<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlotAssignment;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\Group;
use App\Models\UserNotification;
use App\Services\Groups\ActivitySlotAttendanceService;
use Database\Seeders\PreviewRunProgPointSeeder;
use Database\Seeders\RunCalendarPreviewSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('tops up six months without duplicating fixtures and archives only unfinished past runs consistently', function () {
    $this->travelTo(now()->setDate(2026, 11, 17)->setTime(12, 0));
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $character = Character::factory()->primary()->create(['name' => 'Giki Chomusuke']);
    $group = Group::factory()->open()->withMember($character->user)->create();
    Character::factory()->primary()->create(['user_id' => $group->owner_id]);
    $type = ActivityType::factory()->create(['slug' => 'forked-tower']);
    $version = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $type->id,
        'layout_schema' => ['groups' => [['key' => 'a', 'label' => ['en' => 'A'], 'size' => 2]]],
        'slot_schema' => [], 'application_schema' => [],
        'prog_points' => [
            ['key' => 'first', 'label' => ['en' => 'First']],
            ['key' => 'second', 'label' => ['en' => 'Second']],
            ['key' => 'last', 'label' => ['en' => 'Last']],
        ],
    ]);
    $type->update(['current_published_version_id' => $version->id]);
    $factory = Activity::factory()->state([
        'group_id' => $group->id, 'organized_by_user_id' => $group->owner_id,
        'activity_type_id' => $type->id, 'activity_type_version_id' => $version->id,
        'status' => Activity::STATUS_SCHEDULED, 'starts_at' => now()->subDays(2),
    ]);
    $past = $factory->withRandomAssignments(1, 2)->count(8)->create();
    foreach ($past as $activity) {
        ActivityApplication::factory()->create(['activity_id' => $activity->id, 'user_id' => $character->user_id, 'selected_character_id' => $character->id]);
        app(ActivitySlotAttendanceService::class)->ensureActiveAssignments($activity);
    }
    $complete = $factory->complete()->create();
    $cancelled = $factory->create(['status' => Activity::STATUS_CANCELLED]);
    $existingFuture = $factory->create(['starts_at' => now()->addDays(2)]);
    $preserved = collect([$complete, $cancelled, $existingFuture])->mapWithKeys(fn ($run) => [$run->id => $run->fresh()->getRawOriginal()]);

    $this->seed(RunCalendarPreviewSeeder::class);

    expect(Activity::where('starts_at', '<', now())->whereNotIn('status', Activity::ARCHIVED_STATUSES)->exists())->toBeFalse();
    foreach ($past->fresh() as $activity) {
        expect($activity->is_completed)->toBe($activity->status === Activity::STATUS_COMPLETE);
        if ($activity->is_completed) {
            expect($activity->completed_at->betweenIncluded($activity->starts_at, now()))->toBeTrue();
        } else {
            expect($activity->completed_at)->toBeNull()
                ->and($activity->applications()->whereIn('status', ActivityApplication::ACTIVE_STATUSES)->exists())->toBeFalse()
                ->and($activity->slotAssignments()->whereNull('ended_at')->exists())->toBeFalse();
        }
    }
    foreach ($preserved as $id => $attributes) {
        expect(Activity::findOrFail($id)->getRawOriginal())->toBe($attributes);
    }
    $fixtures = Activity::whereNotNull('settings->preview_seed')->get();
    $targets = $fixtures->pluck('target_prog_point_key', 'id');
    expect($targets->unique()->sort()->values()->all())->toBe(['first', 'last', 'second']);
    expect($fixtures)->not->toBeEmpty()
        ->and($fixtures->every(fn ($run) => $run->starts_at->betweenIncluded(now(), now()->addMonthsNoOverflow(6))))->toBeTrue()
        ->and($fixtures->max('starts_at')->gt(now()->addMonths(5)))->toBeTrue()
        ->and(ActivityApplication::whereIn('activity_id', $fixtures->modelKeys())->where('user_id', $character->user_id)->exists())->toBeTrue()
        ->and(ActivitySlotAssignment::whereIn('activity_id', $fixtures->modelKeys())->exists())->toBeTrue();
    $counts = [Activity::count(), ActivityApplication::count(), ActivitySlotAssignment::count()];
    $this->seed(RunCalendarPreviewSeeder::class);
    expect([Activity::count(), ActivityApplication::count(), ActivitySlotAssignment::count()])->toBe($counts)
        ->and(UserNotification::count())->toBe(0);

    // Existing previews keep their pinned definitions after a newer version is published.
    $latest = ActivityTypeVersion::factory()->for($type)->create(['version' => 2, 'prog_points' => []]);
    $type->update(['current_published_version_id' => $latest->id]);
    Activity::whereKey($fixtures->modelKeys())->update(['target_prog_point_key' => 'first']);
    $this->seed(PreviewRunProgPointSeeder::class);
    expect(Activity::whereKey($fixtures->modelKeys())->pluck('target_prog_point_key', 'id')->all())->toEqual($targets->all());
    $this->seed(PreviewRunProgPointSeeder::class);
    expect(Activity::whereKey($fixtures->modelKeys())->pluck('target_prog_point_key', 'id')->all())->toEqual($targets->all())
        ->and($existingFuture->fresh()->getRawOriginal())->toBe($preserved[$existingFuture->id]);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});
