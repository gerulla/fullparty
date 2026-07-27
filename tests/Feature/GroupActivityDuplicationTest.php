<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use App\Models\ActivityTypeVersion;
use App\Models\AuditLog;
use App\Models\Character;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\NotificationEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('duplicates a run and rebuilds selected roster assignments without operational state', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create(['owner_id' => $owner->id]);
    $version = ActivityTypeVersion::factory()->create([
        'bench_size' => 1,
        'progress_schema' => [
            'milestones' => [
                ['key' => 'first-boss', 'label' => ['en' => 'First Boss'], 'order' => 1],
            ],
        ],
    ]);
    $sourceStartsAt = CarbonImmutable::now('UTC')->subDay()->setTime(18, 0);
    $targetStartsAt = CarbonImmutable::now('UTC')->addDay()->setTime(19, 30);
    $source = Activity::factory()->complete()->create([
        'group_id' => $group->id,
        'activity_type_id' => $version->activity_type_id,
        'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_COMPLETE,
        'title' => 'Static Reclear',
        'description' => 'Legacy description',
        'notes' => 'Bring food and pots.',
        'starts_at' => $sourceStartsAt,
        'duration_hours' => 3.5,
        'datacenter' => 'Chaos',
        'intensity' => Activity::INTENSITY_HARDCORE,
        'min_item_level' => 760,
        'beginner_friendly' => false,
        'run_style' => Activity::RUN_STYLE_RECLEAR,
        'target_prog_point_key' => 'clear',
        'is_public' => false,
        'needs_application' => true,
        'allow_guest_applications' => false,
        'settings' => [
            Activity::SETTING_CANCELLATION_REASON => 'Old cancellation reason',
            'starting_soon_sent_at' => now()->toIso8601String(),
        ],
        'progress_entry_mode' => 'manual',
        'progress_link_url' => 'https://example.test/progress',
        'progress_notes' => 'Old result',
        'furthest_progress_key' => 'first-boss',
        'furthest_progress_percent' => 75,
        'is_completed' => true,
        'completed_at' => now(),
        'progress_recorded_by_user_id' => $owner->id,
        'progress_recorded_at' => now(),
    ]);

    $mainCharacter = Character::factory()->create();
    $benchCharacter = Character::factory()->create();
    $fillInCharacter = Character::factory()->create();
    $mainSlot = $source->slots()->where('slot_kind', ActivitySlot::SLOT_KIND_ROSTER)->firstOrFail();
    $benchSlot = ActivitySlot::factory()->create([
        'activity_id' => $source->id,
        'slot_kind' => ActivitySlot::SLOT_KIND_BENCH,
        'group_key' => 'bench',
        'group_label' => ['en' => 'Bench'],
        'slot_key' => 'bench-slot-1',
        'slot_label' => ['en' => 'Bench 1'],
        'position_in_group' => 1,
        'sort_order' => 9,
    ]);

    $mainSlot->update([
        'assigned_character_id' => $mainCharacter->id,
        'assigned_by_user_id' => $owner->id,
        'is_host' => true,
        'is_raid_leader' => true,
    ]);
    $mainSlot->fieldValues()->where('field_key', 'character_class')->firstOrFail()->update([
        'value' => ['id' => 33, 'name' => 'Astrologian', 'shorthand' => 'AST'],
    ]);
    ActivitySlotAssignment::query()->create([
        'activity_id' => $source->id,
        'group_id' => $group->id,
        'activity_slot_id' => $mainSlot->id,
        'character_id' => $mainCharacter->id,
        'assignment_source' => ActivitySlotAssignment::SOURCE_APPLICATION,
        'field_values_snapshot' => ['character_class' => ['id' => 33, 'shorthand' => 'AST']],
        'attendance_status' => ActivitySlotAssignment::STATUS_CHECKED_IN,
        'assigned_at' => now()->subDay(),
        'assigned_by_user_id' => $owner->id,
        'checked_in_at' => now()->subDay(),
        'checked_in_by_user_id' => $owner->id,
    ]);

    $benchSlot->update([
        'assigned_character_id' => $benchCharacter->id,
        'assigned_by_user_id' => $owner->id,
    ]);

    $fillInSlot = ActivitySlot::factory()->create([
        'activity_id' => $source->id,
        'slot_kind' => ActivitySlot::SLOT_KIND_FILL_IN,
        'group_key' => 'fill-ins',
        'group_label' => ['en' => 'Fill-ins'],
        'filled_group_key' => 'party-a',
        'filled_group_label' => ['en' => 'Party A'],
        'slot_key' => 'fill-in-slot-1',
        'slot_label' => ['en' => 'Fill in 1'],
        'position_in_group' => 1,
        'sort_order' => 10,
        'assigned_character_id' => $fillInCharacter->id,
        'assigned_by_user_id' => $owner->id,
    ]);
    $fillInSlot->fieldValues()->create([
        'field_key' => 'character_class',
        'field_label' => ['en' => 'Character Class'],
        'field_type' => 'single_select',
        'source' => 'character_classes',
        'value' => ['id' => 21, 'name' => 'Warrior', 'shorthand' => 'WAR'],
    ]);

    ActivityApplication::factory()->create([
        'activity_id' => $source->id,
        'selected_character_id' => $mainCharacter->id,
        'status' => ActivityApplication::STATUS_APPROVED,
    ]);
    $source->progressMilestones()->firstOrFail()->update([
        'kills' => 4,
        'best_progress_percent' => 100,
        'source' => 'manual',
        'notes' => 'Cleared',
    ]);

    $this->actingAs($owner)
        ->post(route('groups.dashboard.activities.duplicate', [
            'group' => $group,
            'activity' => $source,
        ]), [
            'title' => 'Static Reclear - Wednesday',
            'starts_at' => $targetStartsAt->format('Y-m-d\TH:i'),
            'status' => Activity::STATUS_SCHEDULED,
            'copy_bench' => false,
            'copy_fill_ins' => true,
        ])
        ->assertRedirect();

    $duplicate = $group->activities()->whereKeyNot($source->id)->sole();

    expect($duplicate->activity_type_id)->toBe($source->activity_type_id)
        ->and($duplicate->activity_type_version_id)->toBe($source->activity_type_version_id)
        ->and($duplicate->organized_by_user_id)->toBe($source->organized_by_user_id)
        ->and($duplicate->organized_by_character_id)->toBe($source->organized_by_character_id)
        ->and($duplicate->status)->toBe(Activity::STATUS_SCHEDULED)
        ->and($duplicate->starts_at?->format('Y-m-d H:i'))->toBe($targetStartsAt->format('Y-m-d H:i'))
        ->and($duplicate->title)->toBe('Static Reclear - Wednesday')
        ->and($duplicate->notes)->toBe($source->notes)
        ->and($duplicate->duration_hours)->toBe($source->duration_hours)
        ->and($duplicate->datacenter)->toBe($source->datacenter)
        ->and($duplicate->settings)->toBe([])
        ->and($duplicate->is_completed)->toBeFalse()
        ->and($duplicate->completed_at)->toBeNull()
        ->and($duplicate->progress_entry_mode)->toBeNull()
        ->and($duplicate->progress_link_url)->toBeNull()
        ->and($duplicate->progress_notes)->toBeNull()
        ->and($duplicate->furthest_progress_key)->toBeNull()
        ->and($duplicate->furthest_progress_percent)->toBeNull()
        ->and($duplicate->applications()->count())->toBe(0)
        ->and($duplicate->partyFinderInfo()->exists())->toBeFalse();

    $copiedMainSlot = $duplicate->slots()->where('slot_key', $mainSlot->slot_key)->firstOrFail();
    $copiedBenchSlot = $duplicate->slots()->where('slot_key', $benchSlot->slot_key)->firstOrFail();
    $copiedFillInSlot = $duplicate->slots()->where('slot_key', $fillInSlot->slot_key)->firstOrFail();
    $copiedAssignment = $duplicate->slotAssignments()->where('character_id', $mainCharacter->id)->sole();

    expect($copiedMainSlot->assigned_character_id)->toBe($mainCharacter->id)
        ->and($copiedMainSlot->assigned_by_user_id)->toBe($owner->id)
        ->and($copiedMainSlot->is_host)->toBeTrue()
        ->and($copiedMainSlot->is_raid_leader)->toBeTrue()
        ->and($copiedMainSlot->fieldValues()->where('field_key', 'character_class')->firstOrFail()->value)
        ->toBe(['id' => 33, 'name' => 'Astrologian', 'shorthand' => 'AST'])
        ->and($copiedBenchSlot->assigned_character_id)->toBeNull()
        ->and($copiedFillInSlot->assigned_character_id)->toBe($fillInCharacter->id)
        ->and($copiedFillInSlot->filled_group_key)->toBe('party-a')
        ->and($copiedAssignment->application_id)->toBeNull()
        ->and($copiedAssignment->assignment_source)->toBe(ActivitySlotAssignment::SOURCE_MANUAL)
        ->and($copiedAssignment->attendance_status)->toBe(ActivitySlotAssignment::STATUS_ASSIGNED)
        ->and($copiedAssignment->checked_in_at)->toBeNull()
        ->and($copiedAssignment->marked_missing_at)->toBeNull();

    $copiedMilestone = $duplicate->progressMilestones()->sole();

    expect($copiedMilestone->milestone_key)->toBe('first-boss')
        ->and($copiedMilestone->kills)->toBe(0)
        ->and($copiedMilestone->best_progress_percent)->toBeNull()
        ->and($copiedMilestone->source)->toBeNull()
        ->and($copiedMilestone->notes)->toBeNull()
        ->and(NotificationEvent::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'group.activity.created')->count())->toBe(1);
});

it('can duplicate into a draft with bench assignments and without fill-ins', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create(['owner_id' => $owner->id]);
    $version = ActivityTypeVersion::factory()->create(['bench_size' => 1]);
    $source = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $version->activity_type_id,
        'activity_type_version_id' => $version->id,
        'status' => Activity::STATUS_ASSIGNED,
    ]);
    $benchCharacter = Character::factory()->create();
    $benchSlot = ActivitySlot::factory()->create([
        'activity_id' => $source->id,
        'slot_kind' => ActivitySlot::SLOT_KIND_BENCH,
        'group_key' => 'bench',
        'group_label' => ['en' => 'Bench'],
        'slot_key' => 'bench-slot-1',
        'slot_label' => ['en' => 'Bench 1'],
        'position_in_group' => 1,
        'sort_order' => 9,
    ]);
    $benchSlot->update(['assigned_character_id' => $benchCharacter->id]);
    ActivitySlot::factory()->create([
        'activity_id' => $source->id,
        'slot_kind' => ActivitySlot::SLOT_KIND_FILL_IN,
        'group_key' => 'fill-ins',
        'slot_key' => 'fill-in-slot-1',
        'sort_order' => 10,
    ]);

    $this->actingAs($owner)
        ->post(route('groups.dashboard.activities.duplicate', [
            'group' => $group,
            'activity' => $source,
        ]), [
            'title' => 'Draft Roster Copy',
            'starts_at' => now('UTC')->addDays(2)->format('Y-m-d\TH:i'),
            'status' => Activity::STATUS_DRAFT,
            'copy_bench' => true,
            'copy_fill_ins' => false,
        ])
        ->assertRedirect();

    $duplicate = $group->activities()->whereKeyNot($source->id)->sole();

    expect($duplicate->status)->toBe(Activity::STATUS_DRAFT)
        ->and($duplicate->slots()->where('slot_kind', ActivitySlot::SLOT_KIND_BENCH)->firstOrFail()->assigned_character_id)
        ->toBe($benchCharacter->id)
        ->and($duplicate->slots()->where('slot_kind', ActivitySlot::SLOT_KIND_FILL_IN)->exists())
        ->toBeFalse();
});

it('forbids group members from duplicating runs', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $group = Group::factory()->open()->create(['owner_id' => $owner->id]);
    $group->memberships()->create([
        'user_id' => $member->id,
        'role' => GroupMembership::ROLE_MEMBER,
        'joined_at' => now(),
    ]);
    $source = Activity::factory()->create(['group_id' => $group->id]);

    $this->actingAs($member)
        ->post(route('groups.dashboard.activities.duplicate', [
            'group' => $group,
            'activity' => $source,
        ]), [
            'title' => 'Forbidden Copy',
            'starts_at' => now('UTC')->addDay()->format('Y-m-d\TH:i'),
            'status' => Activity::STATUS_DRAFT,
            'copy_bench' => true,
            'copy_fill_ins' => false,
        ])
        ->assertForbidden();

    expect($group->activities()->count())->toBe(1);
});
