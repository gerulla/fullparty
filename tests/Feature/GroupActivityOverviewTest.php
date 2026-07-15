<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlotAssignment;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\Group;
use App\Models\PhantomComposition;
use App\Models\PhantomJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders serialized roster slots on the attendee overview page', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);

    Character::factory()->primary()->create([
        'user_id' => $owner->id,
    ]);

    $type = ActivityType::factory()->create([
        'created_by_user_id' => $owner->id,
    ]);

    $whiteMage = CharacterClass::create([
        'name' => 'White Mage',
        'shorthand' => 'WHM',
        'role' => 'healer',
        'icon_url' => 'https://example.com/icons/whm.png',
    ]);
    $samurai = CharacterClass::create([
        'name' => 'Samurai',
        'shorthand' => 'SAM',
        'role' => 'melee dps',
        'icon_url' => 'https://example.com/icons/sam.png',
    ]);
    $warrior = CharacterClass::create([
        'name' => 'Warrior',
        'shorthand' => 'WAR',
        'role' => 'tank',
        'icon_url' => 'https://example.com/icons/war.png',
    ]);
    $paladin = CharacterClass::create([
        'name' => 'Paladin',
        'shorthand' => 'PLD',
        'role' => 'tank',
        'icon_url' => 'https://example.com/icons/pld.png',
    ]);
    $phantomSamurai = PhantomJob::create([
        'name' => 'Phantom Samurai',
        'max_level' => 20,
        'transparent_icon_url' => 'https://example.com/icons/phantom-sam.png',
    ]);
    $phantomBard = PhantomJob::create([
        'name' => 'Phantom Bard',
        'max_level' => 20,
        'transparent_icon_url' => 'https://example.com/icons/phantom-brd.png',
    ]);

    $version = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $type->id,
        'published_by_user_id' => $owner->id,
        'layout_schema' => [
            'groups' => [
                [
                    'key' => 'party-a',
                    'label' => ['en' => 'Party A'],
                    'size' => 2,
                ],
                [
                    'key' => 'party-b',
                    'label' => ['en' => 'Party B'],
                    'size' => 2,
                ],
            ],
        ],
        'slot_schema' => [],
        'application_schema' => [
            [
                'key' => 'character_classes',
                'label' => ['en' => 'Jobs'],
                'type' => 'multi_select',
                'required' => true,
                'source' => 'character_classes',
            ],
            [
                'key' => 'phantom_jobs',
                'label' => ['en' => 'Phantom Jobs'],
                'type' => 'multi_select',
                'required' => true,
                'source' => 'phantom_jobs',
            ],
            [
                'key' => 'experience',
                'label' => ['en' => 'Experience'],
                'type' => 'textarea',
                'required' => true,
            ],
        ],
        'roster_summary_presets' => [
            [
                'key' => 'recommended',
                'label' => ['en' => 'Recommended Composition'],
                'description' => ['en' => 'Balanced default composition.'],
                'requirements' => [
                    [
                        'source' => 'static_options',
                        'source_id' => 1,
                        'comparison' => 'at_least',
                        'target_count' => 1,
                        'scope_type' => 'all_slots',
                        'scope_group_keys' => [],
                    ],
                ],
            ],
        ],
        'progress_schema' => ['milestones' => []],
        'bench_size' => 1,
        'prog_points' => [
            [
                'key' => 'enrage',
                'label' => ['en' => 'Enrage'],
            ],
        ],
    ]);

    $type->update([
        'current_published_version_id' => $version->id,
    ]);

    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_UPCOMING,
        'needs_application' => true,
        'allow_guest_applications' => true,
        'target_prog_point_key' => 'enrage',
        'is_public' => true,
    ]);

    $assignedCharacter = Character::factory()->create();
    $benchApplicantUser = User::factory()->create();
    $benchApplicantCharacter = Character::factory()->primary()->create([
        'user_id' => $benchApplicantUser->id,
    ]);
    $benchApplicantCharacter->classes()->attach([
        $whiteMage->id => ['level' => 100, 'is_preferred' => true],
        $samurai->id => ['level' => 100, 'is_preferred' => false],
        $warrior->id => ['level' => 100, 'is_preferred' => false],
        $paladin->id => ['level' => 100, 'is_preferred' => false],
    ]);
    $benchApplicantCharacter->phantomJobs()->attach([
        $phantomSamurai->id => ['current_level' => 20, 'is_preferred' => true],
        $phantomBard->id => ['current_level' => 20, 'is_preferred' => false],
    ]);

    $slot = $activity->slots()->orderBy('sort_order')->firstOrFail();
    $slot->update([
        'assigned_character_id' => $assignedCharacter->id,
        'assigned_by_user_id' => $owner->id,
    ]);

    ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
    ]);

    $benchApplication = ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $benchApplicantUser->id,
        'selected_character_id' => $benchApplicantCharacter->id,
        'status' => ActivityApplication::STATUS_ON_BENCH,
        'reviewed_by_user_id' => $owner->id,
        'reviewed_at' => now(),
    ]);
    $benchApplication->answers()->delete();
    $benchApplication->answers()->create([
        'question_key' => 'character_classes',
        'question_label' => ['en' => 'Jobs'],
        'question_type' => 'multi_select',
        'source' => 'character_classes',
        'value' => [
            (string) $whiteMage->id,
            (string) $samurai->id,
            (string) $warrior->id,
            (string) $paladin->id,
        ],
    ]);
    $benchApplication->answers()->create([
        'question_key' => 'phantom_jobs',
        'question_label' => ['en' => 'Phantom Jobs'],
        'question_type' => 'multi_select',
        'source' => 'phantom_jobs',
        'value' => [
            (string) $phantomSamurai->id,
            (string) $phantomBard->id,
        ],
    ]);

    $benchSlot = $activity->slots()->create([
        'group_key' => 'bench',
        'group_label' => ['en' => 'Bench'],
        'slot_key' => 'bench-slot-1',
        'slot_label' => ['en' => 'Bench 1'],
        'position_in_group' => 1,
        'sort_order' => 5,
        'assigned_character_id' => $benchApplicantCharacter->id,
        'assigned_by_user_id' => $owner->id,
    ]);
    ActivitySlotAssignment::create([
        'activity_id' => $activity->id,
        'group_id' => $group->id,
        'activity_slot_id' => $benchSlot->id,
        'character_id' => $benchApplicantCharacter->id,
        'application_id' => $benchApplication->id,
        'assignment_source' => ActivitySlotAssignment::SOURCE_APPLICATION,
        'field_values_snapshot' => [],
        'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
        'assigned_at' => now(),
        'assigned_by_user_id' => $owner->id,
    ]);

    $response = $this->get(route('groups.activities.overview', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Groups/Activities/Overview')
            ->where('activity.slot_count', 5)
            ->where('activity.assigned_slot_count', 2)
            ->where('activity.pending_application_count', 1)
            ->where('activity.target_prog_point_label.en', 'Enrage')
            ->where('activity.roster_summary_presets.0.key', 'recommended')
            ->has('activity.slots', 5)
            ->where('activity.slots.0.group_key', 'party-a')
            ->where('activity.slots.0.assigned_character.name', $assignedCharacter->name)
            ->where('activity.slots.0.assigned_character.user_id', $assignedCharacter->user_id)
            ->where('activity.slots.0.attendance_status', 'assigned')
            ->where('activity.slots.4.group_key', 'bench')
            ->where('activity.slots.4.assigned_character.name', $benchApplicantCharacter->name)
            ->where('activity.slots.4.application_field_groups.0.items.0.label', 'White Mage')
            ->where('activity.slots.4.application_field_groups.0.items.3.label', 'Paladin')
            ->where('activity.slots.4.application_field_groups.1.items.0.label', 'Phantom Samurai')
            ->where('activity.slots.4.application_field_groups.1.items.1.label', 'Phantom Bard')
        );
});

it('uses group phantom compositions on Forked Tower attendee overviews', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);

    $type = ActivityType::factory()->create([
        'created_by_user_id' => $owner->id,
        'slug' => 'forked-tower',
    ]);

    $phantomJob = PhantomJob::query()->create([
        'name' => 'Phantom Bard',
        'max_level' => 20,
    ]);

    $characterClass = CharacterClass::query()->create([
        'name' => 'Scholar',
        'shorthand' => 'SCH',
        'role' => 'healer',
    ]);

    $version = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $type->id,
        'published_by_user_id' => $owner->id,
        'layout_schema' => [
            'groups' => [
                [
                    'key' => 'party-a',
                    'label' => ['en' => 'Party A'],
                    'size' => 1,
                ],
                [
                    'key' => 'party-b',
                    'label' => ['en' => 'Party B'],
                    'size' => 1,
                ],
            ],
        ],
        'roster_summary_presets' => [
            [
                'key' => 'legacy',
                'label' => ['en' => 'Legacy Summary'],
                'description' => ['en' => 'Old activity-type summary.'],
                'requirements' => [
                    [
                        'source' => 'phantom_jobs',
                        'source_id' => $phantomJob->id,
                        'comparison' => 'at_least',
                        'target_count' => 1,
                        'scope_type' => 'slot_group_set',
                        'scope_group_keys' => ['party-a', 'party-b'],
                    ],
                    [
                        'source' => 'character_classes',
                        'source_id' => $characterClass->id,
                        'comparison' => 'exactly',
                        'target_count' => 1,
                        'scope_type' => 'all_slots',
                        'scope_group_keys' => [],
                    ],
                ],
            ],
        ],
    ]);

    $type->update([
        'current_published_version_id' => $version->id,
    ]);

    $composition = PhantomComposition::query()->create([
        'group_id' => $group->id,
        'content_key' => PhantomComposition::CONTENT_FORKED_TOWER_BLOOD,
        'name' => 'Group Minimal',
        'description' => 'Group-managed phantom summary.',
        'is_default' => true,
        'is_active' => true,
        'sort_order' => 0,
        'rules' => [
            [
                'type' => PhantomComposition::RULE_SINGLE_JOB_COUNT,
                'label' => 'Phantom Bard',
                'severity' => PhantomComposition::SEVERITY_REQUIRED,
                'comparison' => PhantomComposition::COMPARISON_AT_LEAST,
                'target_count' => 1,
                'scope' => [
                    'type' => PhantomComposition::SCOPE_EACH_SLOT_GROUP_SET,
                    'group_sets' => [
                        ['party-a'],
                        ['party-b'],
                    ],
                ],
                'phantom_job_id' => $phantomJob->id,
            ],
        ],
    ]);

    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_UPCOMING,
        'is_public' => true,
    ]);

    $this->get(route('groups.activities.overview', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Groups/Activities/Overview')
            ->where('activity.roster_summary_presets.0.key', 'legacy')
            ->where('activity.roster_summary_presets.0.requirements.0.item.label.en', 'Scholar')
            ->where('activity.roster_summary_presets.1.key', "phantom-composition-{$composition->id}")
            ->where('activity.roster_summary_presets.1.label.en', 'Group Minimal')
            ->where('activity.roster_summary_presets.1.requirements.0.item.label.en', 'Phantom Bard')
            ->where('activity.roster_summary_presets.1.requirements.0.scope_type', 'slot_group')
            ->where('activity.roster_summary_presets.1.requirements.1.scope_groups.0.label.en', 'Party B'));
});

it('exposes the cancellation reason on the attendee overview payload', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $type = ActivityType::factory()->create([
        'created_by_user_id' => $owner->id,
    ]);
    $version = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $type->id,
        'published_by_user_id' => $owner->id,
        'layout_schema' => ['groups' => []],
        'slot_schema' => [],
        'application_schema' => [],
        'progress_schema' => ['milestones' => []],
    ]);

    $type->update([
        'current_published_version_id' => $version->id,
    ]);

    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_CANCELLED,
        'is_public' => true,
        'settings' => [
            Activity::SETTING_CANCELLATION_REASON => 'Raid lead is unavailable tonight.',
        ],
    ]);

    $this->get(route('groups.activities.overview', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Groups/Activities/Overview')
            ->where('activity.status', Activity::STATUS_CANCELLED)
            ->where('activity.cancellation_reason', 'Raid lead is unavailable tonight.'));
});

it('renders server-side embed meta for public activity overviews', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
        'name' => 'Midnight Static',
    ]);
    $type = ActivityType::factory()->create([
        'created_by_user_id' => $owner->id,
        'slug' => 'arcadion',
    ]);
    $version = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $type->id,
        'published_by_user_id' => $owner->id,
        'name' => ['en' => 'AAC Light-heavyweight M4 Savage'],
        'banner_image_url' => '/storage/activity-banners/m4s.webp',
        'layout_schema' => ['groups' => []],
        'slot_schema' => [],
        'application_schema' => [],
        'progress_schema' => ['milestones' => []],
    ]);

    $type->update([
        'current_published_version_id' => $version->id,
    ]);

    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Storm Raid',
        'description' => 'Push to clear.',
        'is_public' => true,
    ]);

    $this->get(route('groups.activities.overview', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]))
        ->assertOk()
        ->assertSee('<meta property="og:title" content="Storm Raid - FullParty.gg">', false)
        ->assertSee('<meta property="og:type" content="event">', false)
        ->assertSee('<meta property="og:description" content="Push to clear.">', false)
        ->assertSee('<meta property="og:image" content="http://fullparty.test/storage/activity-banners/m4s.webp">', false);
});

it('falls back to cancelled application review reasons on the attendee overview payload', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $type = ActivityType::factory()->create([
        'created_by_user_id' => $owner->id,
    ]);
    $version = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $type->id,
        'published_by_user_id' => $owner->id,
        'layout_schema' => ['groups' => []],
        'slot_schema' => [],
        'application_schema' => [],
        'progress_schema' => ['milestones' => []],
    ]);

    $type->update([
        'current_published_version_id' => $version->id,
    ]);

    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_CANCELLED,
        'is_public' => true,
        'settings' => [],
    ]);

    ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'status' => ActivityApplication::STATUS_CANCELLED,
        'review_reason' => 'Static fell through for maintenance.',
    ]);

    $this->get(route('groups.activities.overview', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Groups/Activities/Overview')
            ->where('activity.status', Activity::STATUS_CANCELLED)
            ->where('activity.cancellation_reason', 'Static fell through for maintenance.'));
});

it('exposes completion summary data on the attendee overview payload', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $type = ActivityType::factory()->create([
        'created_by_user_id' => $owner->id,
    ]);
    $version = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $type->id,
        'published_by_user_id' => $owner->id,
        'layout_schema' => ['groups' => []],
        'slot_schema' => [],
        'application_schema' => [],
        'progress_schema' => ['milestones' => []],
        'prog_points' => [
            [
                'key' => 'enrage',
                'label' => ['en' => 'Enrage'],
            ],
        ],
    ]);

    $type->update([
        'current_published_version_id' => $version->id,
    ]);

    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_COMPLETE,
        'is_public' => true,
        'progress_entry_mode' => 'manual',
        'progress_link_url' => 'https://www.fflogs.com/reports/example',
        'progress_notes' => 'Reached enrage consistently.',
        'furthest_progress_key' => 'enrage',
        'furthest_progress_percent' => 78,
        'completed_at' => now(),
    ]);

    $activity->progressMilestones()->create([
        'milestone_key' => 'boss-1',
        'milestone_label' => ['en' => 'Boss 1'],
        'sort_order' => 1,
        'kills' => 2,
        'best_progress_percent' => 78,
        'source' => 'manual',
        'notes' => 'Clean pulls.',
    ]);

    $this->get(route('groups.activities.overview', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Groups/Activities/Overview')
            ->where('activity.status', Activity::STATUS_COMPLETE)
            ->where('activity.progress_entry_mode', 'manual')
            ->where('activity.progress_link_url', 'https://www.fflogs.com/reports/example')
            ->where('activity.progress_notes', 'Reached enrage consistently.')
            ->where('activity.furthest_progress_key', 'enrage')
            ->where('activity.furthest_progress_percent', 78)
            ->where('activity.prog_points.0.key', 'enrage')
            ->where('activity.prog_points.0.label.en', 'Enrage')
            ->where('activity.progress_milestones.0.milestone_key', 'boss-1')
            ->where('activity.progress_milestones.0.milestone_label.en', 'Boss 1')
            ->where('activity.progress_milestones.0.kills', 2)
            ->where('activity.progress_milestones.0.best_progress_percent', 78)
            ->where('activity.progress_milestones.0.sort_order', 1));
});
