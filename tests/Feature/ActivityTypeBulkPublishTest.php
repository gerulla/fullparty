<?php

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function bulkPublishDraft(): array
{
    return [
        'draft_application_schema' => [],
        'draft_progress_schema' => ['milestones' => []],
        'draft_bench_size' => 0,
    ];
}

it('publishes every saved draft once across pages and filters without changing existing runs', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $types = ActivityType::factory()->count(14)->withPublishedVersion()->create(bulkPublishDraft());
    $first = $types->first();
    $originalVersion = $first->currentPublishedVersion;
    ActivityTypeVersion::factory()->for($first)->create(['version' => 7]);
    $run = Activity::factory()->create([
        'activity_type_id' => $first->id,
        'activity_type_version_id' => $originalVersion->id,
    ]);
    $first->update(['draft_name' => ['en' => 'Saved revision', 'de' => 'Gespeicherte Überarbeitung']]);
    $inactive = ActivityType::factory()->withPublishedVersion()->create([...bulkPublishDraft(), 'is_active' => false]);
    $unpublished = ActivityType::factory()->create(bulkPublishDraft());

    $this->actingAs($admin)->get(route('admin.activity-types.index', ['search' => 'Saved revision']))
        ->assertInertia(fn (Assert $page) => $page->has('activityTypes.data', 1)->where('totalActivityTypes', 16));

    $this->actingAs($admin)
        ->from(route('admin.activity-types.index', ['search' => 'Saved revision']))
        ->post(route('admin.activity-types.publish-all'), ['search' => 'Saved revision', 'page' => 2])
        ->assertRedirect()
        ->assertSessionHas('success', 'activity_types_published')
        ->assertSessionHas('flash_data.published_count', 16);

    foreach ($types as $type) {
        $type->refresh();
        $version = $type->currentPublishedVersion;
        expect($version->version)->toBe($type->id === $first->id ? 8 : 2)
            ->and($version->name)->toBe($type->draft_name)
            ->and($version->published_by_user_id)->toBe($admin->id);
    }

    expect($inactive->fresh()->currentPublishedVersion->version)->toBe(2)
        ->and($inactive->fresh()->is_active)->toBeFalse()
        ->and($unpublished->fresh()->currentPublishedVersion->version)->toBe(1)
        ->and($run->fresh()->activity_type_version_id)->toBe($originalVersion->id)
        ->and($originalVersion->fresh()->name)->not->toBe($first->fresh()->draft_name)
        ->and(ActivityTypeVersion::count())->toBe(32)
        ->and(AuditLog::where('action', 'admin.activity_type.published')->where('actor_user_id', $admin->id)->count())->toBe(16);
});

it('rejects bulk publishing for non-admins and guests', function () {
    $type = ActivityType::factory()->withPublishedVersion()->create(bulkPublishDraft());
    $this->post(route('admin.activity-types.publish-all'))->assertRedirect();
    $this->actingAs(User::factory()->create(['is_admin' => false]))
        ->post(route('admin.activity-types.publish-all'))->assertForbidden();

    expect($type->versions()->count())->toBe(1)
        ->and(AuditLog::where('action', 'admin.activity_type.published')->count())->toBe(0);
});

it('validates the complete batch before creating any versions or image snapshots', function () {
    Storage::fake('public');
    Storage::disk('public')->put('activity-types/original.png', 'image');
    $type = ActivityType::factory()->withPublishedVersion()->create([
        ...bulkPublishDraft(),
        'draft_small_image_url' => Storage::disk('public')->url('activity-types/original.png'),
    ]);
    ActivityType::factory()->create([
        ...bulkPublishDraft(),
        'draft_name' => ['en' => 'Invalid draft'],
        'draft_layout_schema' => ['groups' => []],
    ]);
    $versionId = $type->current_published_version_id;

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->post(route('admin.activity-types.publish-all'))
        ->assertSessionHasErrors(['publish_all'])
        ->assertSessionHas('errors', fn ($errors) => str_contains($errors->first('publish_all'), 'Invalid draft'));

    expect(ActivityTypeVersion::count())->toBe(1)
        ->and($type->fresh()->current_published_version_id)->toBe($versionId)
        ->and(Storage::disk('public')->allFiles('activity-types'))->toBe(['activity-types/original.png'])
        ->and(AuditLog::where('action', 'admin.activity_type.published')->count())->toBe(0);
});

it('rolls back the whole batch and removes snapshot copies if publishing fails midway', function () {
    Storage::fake('public');
    Storage::disk('public')->put('activity-types/original.png', 'image');
    $types = ActivityType::factory()->count(2)->withPublishedVersion()->create([
        ...bulkPublishDraft(),
        'draft_small_image_url' => Storage::disk('public')->url('activity-types/original.png'),
    ]);
    $originalVersions = $types->pluck('current_published_version_id', 'id')->all();
    $logger = new AuditLogger;
    $calls = 0;
    $this->mock(AuditLogger::class)->shouldReceive('log')->twice()->andReturnUsing(function (...$arguments) use ($logger, &$calls) {
        if (++$calls === 2) {
            throw new RuntimeException('Simulated audit failure');
        }

        return $logger->log(...$arguments);
    });
    $this->withoutExceptionHandling()->actingAs(User::factory()->create(['is_admin' => true]));

    expect(fn () => $this->post(route('admin.activity-types.publish-all')))->toThrow(RuntimeException::class, 'Simulated audit failure');

    expect(ActivityTypeVersion::count())->toBe(2)
        ->and(ActivityType::pluck('current_published_version_id', 'id')->all())->toBe($originalVersions)
        ->and(Storage::disk('public')->allFiles('activity-types'))->toBe(['activity-types/original.png'])
        ->and(AuditLog::where('action', 'admin.activity_type.published')->count())->toBe(0);
});

it('handles an empty activity type list', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->post(route('admin.activity-types.publish-all'))
        ->assertSessionHas('flash_data.published_count', 0);

    expect(ActivityTypeVersion::count())->toBe(0);
});
