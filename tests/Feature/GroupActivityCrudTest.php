<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlotAssignment;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\AuditLog;
use App\Models\Character;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use App\Support\Input\TextInputSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function createCrudActivityType(User $creator): ActivityType
{
    $type = ActivityType::factory()->create([
        'created_by_user_id' => $creator->id,
        'is_active' => true,
    ]);

    $version = ActivityTypeVersion::factory()->create([
        'activity_type_id' => $type->id,
        'published_by_user_id' => $creator->id,
        'name' => ['en' => 'Savage Prog'],
        'description' => ['en' => 'Eight-player progression run.'],
        'difficulty' => ActivityType::DIFFICULTY_SAVAGE,
        'default_min_item_level' => 710,
        'layout_schema' => [
            'groups' => [
                [
                    'key' => 'party-a',
                    'label' => ['en' => 'Party A'],
                    'size' => 2,
                    'composition_hint_key' => 'two-slot-test',
                    'composition_hints' => [
                        [
                            'position' => 1,
                            'accepts' => [
                                ['type' => 'role', 'key' => 'tank'],
                            ],
                        ],
                        [
                            'position' => 2,
                            'accepts' => [
                                ['type' => 'role', 'key' => 'healer'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'slot_schema' => [
            [
                'key' => 'raid_position',
                'label' => ['en' => 'Raid Position'],
                'type' => 'single_select',
                'source' => 'static_options',
                'options' => [
                    ['key' => 'mt', 'label' => ['en' => 'MT']],
                    ['key' => 'ot', 'label' => ['en' => 'OT']],
                ],
            ],
        ],
        'application_schema' => [
            [
                'key' => 'raid_position',
                'label' => ['en' => 'Preferred Position'],
                'type' => 'single_select',
                'required' => false,
                'source' => 'static_options',
                'options' => [
                    ['key' => 'mt', 'label' => ['en' => 'MT']],
                    ['key' => 'ot', 'label' => ['en' => 'OT']],
                ],
            ],
        ],
        'progress_schema' => [
            'milestones' => [
                ['key' => 'clear', 'label' => ['en' => 'Clear'], 'order' => 1],
                ['key' => 'enrage', 'label' => ['en' => 'Enrage'], 'order' => 2],
            ],
        ],
        'bench_size' => 1,
        'prog_points' => [
            ['key' => 'clear', 'label' => ['en' => 'Clear']],
            ['key' => 'enrage', 'label' => ['en' => 'Enrage']],
        ],
    ]);

    $type->update([
        'current_published_version_id' => $version->id,
    ]);

    return $type->fresh('currentPublishedVersion');
}

it('allows moderators to create members-only application activities and disables guest applications', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $organizerCharacter = Character::factory()->primary()->create([
        'user_id' => $owner->id,
    ]);
    $activityType = createCrudActivityType($owner);
    $startsAt = CarbonImmutable::now('UTC')->addDays(14)->setTime(20, 30);

    $this->actingAs($owner);

    $response = $this->post(route('groups.dashboard.activities.store', [
        'group' => $group->slug,
    ]), [
        'activity_type_id' => $activityType->id,
        'organized_by_user_id' => $owner->id,
        'organized_by_character_id' => $organizerCharacter->id,
        'status' => Activity::STATUS_DRAFT,
        'title' => 'Tuesday Savage Prog',
        'notes' => 'Bring food and pots.',
        'starts_at' => $startsAt->format('Y-m-d\TH:i'),
        'duration_hours' => 2.5,
        'datacenter' => 'Chaos',
        'intensity' => Activity::INTENSITY_HARDCORE,
        'min_item_level' => 720,
        'beginner_friendly' => true,
        'run_style' => Activity::RUN_STYLE_CLEAR,
        'target_prog_point_key' => 'enrage',
        'is_public' => false,
        'needs_application' => true,
        'allow_guest_applications' => true,
    ]);

    $response->assertRedirect(route('groups.dashboard.activities.index', [
        'group' => $group->slug,
    ]));

    /** @var Activity $activity */
    $activity = $group->activities()->latest('id')->firstOrFail();

    expect($activity->activity_type_id)->toBe($activityType->id)
        ->and($activity->organized_by_user_id)->toBe($owner->id)
        ->and($activity->organized_by_character_id)->toBe($organizerCharacter->id)
        ->and($activity->title)->toBe('Tuesday Savage Prog')
        ->and($activity->starts_at?->format('Y-m-d H:i'))->toBe($startsAt->format('Y-m-d H:i'))
        ->and($activity->duration_hours)->toBe(2.5)
        ->and($activity->datacenter)->toBe('Chaos')
        ->and($activity->intensity)->toBe(Activity::INTENSITY_HARDCORE)
        ->and($activity->min_item_level)->toBe(720)
        ->and($activity->beginner_friendly)->toBeTrue()
        ->and($activity->run_style)->toBe(Activity::RUN_STYLE_CLEAR)
        ->and($activity->target_prog_point_key)->toBe('enrage')
        ->and($activity->is_public)->toBeFalse()
        ->and($activity->needs_application)->toBeTrue()
        ->and($activity->allow_guest_applications)->toBeFalse()
        ->and($activity->secret_key)->toBeNull();

    expect($activity->slots()->count())->toBe(3);
    expect($activity->slots()->where('group_key', 'bench')->count())->toBe(1);
    expect($activity->slots()->where('group_key', '!=', 'bench')->count())->toBe(2);
    expect($activity->slots()->where('group_key', '!=', 'bench')->withCount('compositionHints')->get()->pluck('composition_hints_count')->all())
        ->toBe([1, 1]);
    expect($activity->progressMilestones()->count())->toBe(2);

    $auditLog = AuditLog::query()->where('action', 'group.activity.created')->sole();

    expect($auditLog->actor_user_id)->toBe($owner->id)
        ->and($auditLog->subject_type)->toBe(Activity::class)
        ->and($auditLog->subject_id)->toBe($activity->id)
        ->and($auditLog->metadata['activity_title'])->toBe('Tuesday Savage Prog')
        ->and($auditLog->metadata['needs_application'])->toBeTrue();
});

it('allows group admins to organize runs with their own character', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $group->memberships()->create([
        'user_id' => $admin->id,
        'role' => GroupMembership::ROLE_ADMIN,
        'joined_at' => now(),
    ]);

    $adminCharacter = Character::factory()->primary()->create([
        'user_id' => $admin->id,
    ]);
    $activityType = createCrudActivityType($owner);

    $this->actingAs($admin)
        ->get(route('groups.dashboard.activities.create', [
            'group' => $group->slug,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('organizerCharacters.0.id', $adminCharacter->id)
            ->where('organizerCharacters.0.user_id', $admin->id));

    $this->actingAs($admin)
        ->post(route('groups.dashboard.activities.store', [
            'group' => $group->slug,
        ]), [
            'activity_type_id' => $activityType->id,
            'organized_by_user_id' => $admin->id,
            'organized_by_character_id' => $adminCharacter->id,
            'status' => Activity::STATUS_DRAFT,
        ])
        ->assertRedirect(route('groups.dashboard.activities.index', [
            'group' => $group->slug,
        ]));

    $this->assertDatabaseHas('activities', [
        'group_id' => $group->id,
        'organized_by_user_id' => $admin->id,
        'organized_by_character_id' => $adminCharacter->id,
    ]);
});

it('defaults activity discovery metadata from the group and activity type version', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
        'datacenter' => 'Light',
    ]);
    $activityType = createCrudActivityType($owner);

    $this->actingAs($owner);

    $this->post(route('groups.dashboard.activities.store', [
        'group' => $group->slug,
    ]), [
        'activity_type_id' => $activityType->id,
        'status' => Activity::STATUS_DRAFT,
    ])->assertRedirect(route('groups.dashboard.activities.index', [
        'group' => $group->slug,
    ]));

    /** @var Activity $activity */
    $activity = $group->activities()->latest('id')->firstOrFail();

    expect($activity->datacenter)->toBe('Light')
        ->and($activity->intensity)->toBe(Activity::INTENSITY_CASUAL)
        ->and($activity->min_item_level)->toBe(710)
        ->and($activity->beginner_friendly)->toBeFalse()
        ->and($activity->run_style)->toBe(Activity::RUN_STYLE_PROGRESSION);
});

it('rejects creating activities with past start times', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-26 12:00:30', 'UTC'));

    try {
        $owner = User::factory()->create();
        $group = Group::factory()->open()->create([
            'owner_id' => $owner->id,
        ]);
        $activityType = createCrudActivityType($owner);

        $this->actingAs($owner)
            ->post(route('groups.dashboard.activities.store', [
                'group' => $group->slug,
            ]), [
                'activity_type_id' => $activityType->id,
                'status' => Activity::STATUS_DRAFT,
                'starts_at' => '2026-05-26T11:59',
            ])
            ->assertSessionHasErrors(['starts_at']);

        expect($group->activities()->count())->toBe(0);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('shows only non-bench slot counts on the group runs page', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $activityType = createCrudActivityType($owner);

    $this->actingAs($owner);

    $this->post(route('groups.dashboard.activities.store', [
        'group' => $group->slug,
    ]), [
        'activity_type_id' => $activityType->id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Bench Count Check',
    ])->assertRedirect(route('groups.dashboard.activities.index', [
        'group' => $group->slug,
    ]));

    $activity = $group->activities()->latest('id')->firstOrFail();
    $assignedCharacter = Character::factory()->create();
    $activity->slots()
        ->where('group_key', '!=', 'bench')
        ->firstOrFail()
        ->update([
            'assigned_character_id' => $assignedCharacter->id,
            'assigned_by_user_id' => $owner->id,
        ]);

    expect($activity->slots()->count())->toBe(3)
        ->and($activity->slots()->where('group_key', '!=', 'bench')->count())->toBe(2);

    $this->actingAs($owner)
        ->get(route('groups.dashboard.activities.index', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('activities.0.id', $activity->id)
            ->where('activities.0.slot_count', 2)
            ->where('activities.0.assigned_slot_count', 1));
});

it('excludes historical applications from group runs page application counts', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $activityType = createCrudActivityType($owner);

    $this->actingAs($owner);

    $this->post(route('groups.dashboard.activities.store', [
        'group' => $group->slug,
    ]), [
        'activity_type_id' => $activityType->id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Application Count Check',
    ])->assertRedirect(route('groups.dashboard.activities.index', [
        'group' => $group->slug,
    ]));

    $activity = $group->activities()->latest('id')->firstOrFail();

    foreach (ActivityApplication::ACTIVE_STATUSES as $status) {
        ActivityApplication::factory()->create([
            'activity_id' => $activity->id,
            'status' => $status,
        ]);
    }

    foreach ([ActivityApplication::STATUS_DECLINED, ActivityApplication::STATUS_CANCELLED, ActivityApplication::STATUS_WITHDRAWN] as $status) {
        ActivityApplication::factory()->create([
            'activity_id' => $activity->id,
            'status' => $status,
        ]);
    }

    $this->actingAs($owner)
        ->get(route('groups.dashboard.activities.index', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('activities.0.id', $activity->id)
            ->where('activities.0.application_count', count(ActivityApplication::ACTIVE_STATUSES)));
});

it('sanitizes activity free-text fields when creating and updating activities', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $activityType = createCrudActivityType($owner);
    $sanitizer = app(TextInputSanitizer::class);

    $this->actingAs($owner);

    $this->post(route('groups.dashboard.activities.store', [
        'group' => $group->slug,
    ]), [
        'activity_type_id' => $activityType->id,
        'status' => Activity::STATUS_DRAFT,
        'title' => "  Tues\u{200B}day   Savage  ",
        'description' => " Line one\u{200B}\r\nLine\t two ",
        'notes' => " Bring\t food \r\n\r\n And pots ",
    ])->assertRedirect(route('groups.dashboard.activities.index', [
        'group' => $group->slug,
    ]));

    $activity = $group->activities()->latest('id')->firstOrFail();

    expect($activity->title)->toBe($sanitizer->sanitizeSingleLine("  Tues\u{200B}day   Savage  "))
        ->and($activity->description)->toBe($sanitizer->sanitizeMultiline(" Line one\u{200B}\r\nLine\t two "))
        ->and($activity->notes)->toBe($sanitizer->sanitizeMultiline(" Bring\t food \r\n\r\n And pots "));

    $this->put(route('groups.dashboard.activities.update', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]), [
        'title' => "  Upd\u{200B}ated   Run  ",
        'description' => " Fresh\r\n\r\nNotes ",
        'notes' => " New\tplan ",
    ])->assertRedirect(route('groups.dashboard.activities.show', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    $activity->refresh();

    expect($activity->title)->toBe($sanitizer->sanitizeSingleLine("  Upd\u{200B}ated   Run  "))
        ->and($activity->description)->toBe($sanitizer->sanitizeMultiline(" Fresh\r\n\r\nNotes "))
        ->and($activity->notes)->toBe($sanitizer->sanitizeMultiline(" New\tplan "));
});

it('rejects activity descriptions and notes that exceed the configured limits', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $activityType = createCrudActivityType($owner);

    $this->actingAs($owner);

    $this->post(route('groups.dashboard.activities.store', [
        'group' => $group->slug,
    ]), [
        'activity_type_id' => $activityType->id,
        'status' => Activity::STATUS_DRAFT,
        'description' => str_repeat('d', Activity::DESCRIPTION_MAX_LENGTH + 1),
        'notes' => str_repeat('n', Activity::NOTES_MAX_LENGTH + 1),
    ])->assertSessionHasErrors(['description', 'notes']);

    expect($group->activities()->count())->toBe(0);

    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_DRAFT,
        'description' => 'Original description',
        'notes' => 'Original notes',
    ]);

    $this->put(route('groups.dashboard.activities.update', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]), [
        'description' => str_repeat('d', Activity::DESCRIPTION_MAX_LENGTH + 1),
        'notes' => str_repeat('n', Activity::NOTES_MAX_LENGTH + 1),
    ])->assertSessionHasErrors(['description', 'notes']);

    expect($activity->fresh()->description)->toBe('Original description')
        ->and($activity->fresh()->notes)->toBe('Original notes');
});

it('forbids non moderators from creating activities', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $group->memberships()->create([
        'user_id' => $member->id,
        'role' => GroupMembership::ROLE_MEMBER,
        'joined_at' => now(),
    ]);
    $activityType = createCrudActivityType($owner);

    $this->actingAs($member);

    $response = $this->post(route('groups.dashboard.activities.store', [
        'group' => $group->slug,
    ]), [
        'activity_type_id' => $activityType->id,
        'status' => Activity::STATUS_DRAFT,
    ]);

    $response->assertForbidden();
    expect($group->activities()->count())->toBe(0);
});

it('prefills the create run page starts_at value from the requested calendar slot', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    createCrudActivityType($owner);

    $this->actingAs($owner)
        ->get(route('groups.dashboard.activities.create', [
            'group' => $group->slug,
            'starts_at' => '2026-06-15T20:00',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('prefilledStartsAt', '2026-06-15T20:00'));
});

it('hides draft activities from non moderators on the dashboard runs page', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $group->memberships()->create([
        'user_id' => $member->id,
        'role' => GroupMembership::ROLE_MEMBER,
        'joined_at' => now(),
    ]);
    $activityType = createCrudActivityType($owner);

    $scheduledActivity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Scheduled Run',
        'is_public' => true,
        'updated_at' => now()->subMinutes(10),
    ]);

    Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_DRAFT,
        'is_public' => true,
    ]);

    $this->actingAs($member)
        ->get(route('groups.dashboard.activities.index', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('activities', 1)
            ->where('activities.0.id', $scheduledActivity->id)
            ->where('activities.0.status', Activity::STATUS_SCHEDULED));

    $this->actingAs($owner)
        ->get(route('groups.dashboard.activities.index', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('activities', 2));
});

it('redirects non members away from the dashboard runs page', function () {
    $viewer = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => User::factory()->create()->id,
    ]);

    $this->actingAs($viewer)
        ->get(route('groups.dashboard.activities.index', $group))
        ->assertRedirect(route('groups.index'));
});

it('rejects organizer characters that do not belong to the organizer user', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $activityType = createCrudActivityType($owner);
    $foreignCharacter = Character::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    $this->actingAs($owner);

    $response = $this->post(route('groups.dashboard.activities.store', [
        'group' => $group->slug,
    ]), [
        'activity_type_id' => $activityType->id,
        'organized_by_user_id' => $owner->id,
        'organized_by_character_id' => $foreignCharacter->id,
        'status' => Activity::STATUS_DRAFT,
    ]);

    $response->assertStatus(422);
    expect($group->activities()->count())->toBe(0);
});

it('updates mutable activity fields while keeping members-only access intact', function () {
    $owner = User::factory()->create();
    $moderator = User::factory()->create();
    $group = Group::factory()->inviteOnly()->create([
        'owner_id' => $owner->id,
    ]);
    $group->memberships()->create([
        'user_id' => $moderator->id,
        'role' => GroupMembership::ROLE_MODERATOR,
        'joined_at' => now(),
    ]);

    $activityType = createCrudActivityType($owner);
    $moderatorCharacter = Character::factory()->primary()->create([
        'user_id' => $moderator->id,
    ]);

    $activity = Activity::factory()->private()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'organized_by_user_id' => $owner->id,
        'target_prog_point_key' => 'clear',
        'allow_guest_applications' => true,
        'datacenter' => 'Light',
        'intensity' => Activity::INTENSITY_CASUAL,
        'min_item_level' => 710,
        'beginner_friendly' => false,
        'run_style' => Activity::RUN_STYLE_PROGRESSION,
    ]);
    $updatedStartsAt = CarbonImmutable::now('UTC')->addDays(21)->setTime(21, 15);

    $this->actingAs($owner);

    $response = $this->put(route('groups.dashboard.activities.update', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]), [
        'organized_by_user_id' => $moderator->id,
        'organized_by_character_id' => $moderatorCharacter->id,
        'title' => 'Updated Run',
        'notes' => 'Updated moderator notes.',
        'starts_at' => $updatedStartsAt->format('Y-m-d\TH:i'),
        'duration_hours' => 3.5,
        'datacenter' => 'Aether',
        'intensity' => Activity::INTENSITY_MIDCORE,
        'min_item_level' => null,
        'beginner_friendly' => true,
        'run_style' => Activity::RUN_STYLE_RECLEAR,
        'target_prog_point_key' => 'enrage',
        'allow_guest_applications' => false,
    ]);

    $response->assertRedirect(route('groups.dashboard.activities.show', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    $activity->refresh();

    expect($activity->organized_by_user_id)->toBe($moderator->id)
        ->and($activity->organized_by_character_id)->toBe($moderatorCharacter->id)
        ->and($activity->title)->toBe('Updated Run')
        ->and($activity->notes)->toBe('Updated moderator notes.')
        ->and($activity->starts_at?->format('Y-m-d H:i'))->toBe($updatedStartsAt->format('Y-m-d H:i'))
        ->and($activity->duration_hours)->toBe(3.5)
        ->and($activity->datacenter)->toBe('Aether')
        ->and($activity->intensity)->toBe(Activity::INTENSITY_MIDCORE)
        ->and($activity->min_item_level)->toBeNull()
        ->and($activity->beginner_friendly)->toBeTrue()
        ->and($activity->run_style)->toBe(Activity::RUN_STYLE_RECLEAR)
        ->and($activity->target_prog_point_key)->toBe('enrage')
        ->and($activity->is_public)->toBeFalse()
        ->and($activity->allow_guest_applications)->toBeFalse()
        ->and($activity->secret_key)->toBeNull();

    $auditLog = AuditLog::query()->where('action', 'group.activity.updated')->sole();

    expect($auditLog->actor_user_id)->toBe($owner->id)
        ->and($auditLog->subject_type)->toBe(Activity::class)
        ->and($auditLog->subject_id)->toBe($activity->id)
        ->and($auditLog->metadata['changes']['organized_by_user_id']['old'])->toBe($owner->id)
        ->and($auditLog->metadata['changes']['organized_by_user_id']['new'])->toBe($moderator->id)
        ->and($auditLog->metadata['changes']['datacenter']['new'])->toBe('Aether')
        ->and($auditLog->metadata['changes']['min_item_level']['new'])->toBeNull()
        ->and($auditLog->metadata['changes']['allow_guest_applications']['new'])->toBeFalse();
});

it('allows moderators to update activity visibility', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $activityType = createCrudActivityType($owner);
    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_DRAFT,
        'is_public' => true,
        'secret_key' => null,
    ]);

    $this->actingAs($owner)
        ->put(route('groups.dashboard.activities.update', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]), [
            'is_public' => false,
        ])
        ->assertRedirect(route('groups.dashboard.activities.show', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]));

    $activity->refresh();

    expect($activity->is_public)->toBeFalse()
        ->and($activity->secret_key)->toBeNull();

    $this->put(route('groups.dashboard.activities.update', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]), [
        'is_public' => true,
    ])->assertRedirect(route('groups.dashboard.activities.show', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    $activity->refresh();

    expect($activity->is_public)->toBeTrue()
        ->and($activity->secret_key)->toBeNull();
});

it('rejects updating activities with past start times', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-26 12:00:30', 'UTC'));

    try {
        $owner = User::factory()->create();
        $group = Group::factory()->open()->create([
            'owner_id' => $owner->id,
        ]);
        $activityType = createCrudActivityType($owner);
        $activity = Activity::factory()->create([
            'group_id' => $group->id,
            'activity_type_id' => $activityType->id,
            'activity_type_version_id' => $activityType->current_published_version_id,
            'organized_by_user_id' => $owner->id,
            'status' => Activity::STATUS_DRAFT,
            'starts_at' => '2026-05-27 20:00:00',
        ]);

        $this->actingAs($owner)
            ->put(route('groups.dashboard.activities.update', [
                'group' => $group->slug,
                'activity' => $activity->id,
            ]), [
                'starts_at' => '2026-05-26T11:59',
            ])
            ->assertSessionHasErrors(['starts_at']);

        expect($activity->fresh()->starts_at?->format('Y-m-d H:i'))->toBe('2026-05-27 20:00');
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('accepts half-hour durations and rejects values outside the half-hour step', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $activityType = createCrudActivityType($owner);

    $this->actingAs($owner);

    $this->post(route('groups.dashboard.activities.store', [
        'group' => $group->slug,
    ]), [
        'activity_type_id' => $activityType->id,
        'status' => Activity::STATUS_DRAFT,
        'duration_hours' => 2.5,
    ])->assertRedirect(route('groups.dashboard.activities.index', [
        'group' => $group->slug,
    ]));

    $activity = $group->activities()->latest('id')->firstOrFail();

    expect($activity->duration_hours)->toBe(2.5);

    $this->put(route('groups.dashboard.activities.update', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]), [
        'duration_hours' => 2.25,
    ])->assertSessionHasErrors(['duration_hours']);

    expect($activity->fresh()->duration_hours)->toBe(2.5);
});

it('allows moderators to schedule a draft activity', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $activityType = createCrudActivityType($owner);

    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_DRAFT,
    ]);

    $this->actingAs($owner)
        ->post(route('groups.dashboard.activities.schedule', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]))
        ->assertRedirect(route('groups.dashboard.activities.show', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]));

    $activity->refresh();

    expect($activity->status)->toBe(Activity::STATUS_SCHEDULED);

    $auditLog = AuditLog::query()->where('action', 'group.activity.updated')->sole();

    expect($auditLog->metadata['changes']['status']['old'])->toBe(Activity::STATUS_DRAFT)
        ->and($auditLog->metadata['changes']['status']['new'])->toBe(Activity::STATUS_SCHEDULED);
});

it('allows moderators to delete runs before roster publish', function (string $status) {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $activityType = createCrudActivityType($owner);

    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'organized_by_user_id' => $owner->id,
        'status' => $status,
    ]);

    $this->actingAs($owner)
        ->delete(route('groups.dashboard.activities.destroy', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]))
        ->assertRedirect(route('groups.dashboard.activities.index', [
            'group' => $group->slug,
        ]));

    expect(Activity::query()->whereKey($activity->id)->exists())->toBeFalse();

    $auditLog = AuditLog::query()->where('action', 'group.activity.deleted')->sole();

    expect($auditLog->actor_user_id)->toBe($owner->id)
        ->and($auditLog->subject_id)->toBe($activity->id);
})->with([
    'draft' => [Activity::STATUS_DRAFT],
    'scheduled' => [Activity::STATUS_SCHEDULED],
]);

it('forbids cancelling runs before roster publish', function (string $status) {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $activityType = createCrudActivityType($owner);

    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'organized_by_user_id' => $owner->id,
        'status' => $status,
    ]);

    $this->actingAs($owner)
        ->post(route('groups.dashboard.activities.cancel', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]), [
            'reason' => 'No longer needed.',
        ])
        ->assertForbidden();

    expect($activity->fresh()->status)->toBe($status);
})->with([
    'draft' => [Activity::STATUS_DRAFT],
    'scheduled' => [Activity::STATUS_SCHEDULED],
]);

it('forbids deleting runs after roster publish', function (string $status) {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $activityType = createCrudActivityType($owner);

    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'organized_by_user_id' => $owner->id,
        'status' => $status,
    ]);

    $this->actingAs($owner)
        ->delete(route('groups.dashboard.activities.destroy', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]))
        ->assertForbidden();

    expect(Activity::query()->whereKey($activity->id)->exists())->toBeTrue();
})->with([
    'assigned' => [Activity::STATUS_ASSIGNED],
    'upcoming' => [Activity::STATUS_UPCOMING],
    'ongoing' => [Activity::STATUS_ONGOING],
]);

it('does not allow archived activities to be updated', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $activityType = createCrudActivityType($owner);

    $activity = Activity::factory()->complete()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'organized_by_user_id' => $owner->id,
    ]);

    $this->actingAs($owner);

    $response = $this->put(route('groups.dashboard.activities.update', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]), [
        'title' => 'Should Not Save',
    ]);

    $response->assertForbidden();
    expect($activity->fresh()->title)->not->toBe('Should Not Save');
});

it('rejects invalid target prog points during activity creation', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $activityType = createCrudActivityType($owner);

    $this->actingAs($owner);

    $response = $this->post(route('groups.dashboard.activities.store', [
        'group' => $group->slug,
    ]), [
        'activity_type_id' => $activityType->id,
        'status' => Activity::STATUS_DRAFT,
        'target_prog_point_key' => 'not-a-real-prog-point',
    ]);

    $response->assertStatus(422);
    expect($group->activities()->count())->toBe(0);
    expect(AuditLog::query()->count())->toBe(0);
});

it('rejects prohibited fields during activity updates', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $activityType = createCrudActivityType($owner);
    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_DRAFT,
        'is_public' => true,
        'needs_application' => true,
    ]);

    $this->actingAs($owner);

    $response = $this->put(route('groups.dashboard.activities.update', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]), [
        'status' => Activity::STATUS_CANCELLED,
        'needs_application' => false,
        'activity_type_id' => $activityType->id,
    ]);

    expect($response->getStatusCode())->toBe(302);
    expect($response->baseResponse->getSession()->has('errors'))->toBeTrue();

    $activity->refresh();

    expect($activity->status)->toBe(Activity::STATUS_DRAFT)
        ->and($activity->is_public)->toBeTrue()
        ->and($activity->needs_application)->toBeTrue();
    expect(AuditLog::query()->count())->toBe(0);
});

it('cancels active applications, preserves roster slots, and keeps guest status pages read only', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
    ]);
    $activityType = createCrudActivityType($owner);

    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'organized_by_user_id' => $owner->id,
        'status' => Activity::STATUS_ASSIGNED,
        'needs_application' => true,
        'allow_guest_applications' => true,
        'is_public' => true,
    ]);

    $activity->slots()->create([
        'group_key' => 'bench',
        'group_label' => ['en' => 'Bench'],
        'slot_key' => 'bench-slot-1',
        'slot_label' => ['en' => 'Bench 1'],
        'position_in_group' => 1,
        'sort_order' => 999,
    ]);

    $rosterSlot = $activity->slots()->where('group_key', '!=', 'bench')->firstOrFail();
    $benchSlot = $activity->slots()->where('group_key', 'bench')->firstOrFail();

    $pendingUser = User::factory()->create();
    $pendingCharacter = Character::factory()->primary()->create([
        'user_id' => $pendingUser->id,
        'lodestone_id' => '10000001',
    ]);
    $pendingApplication = ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $pendingUser->id,
        'selected_character_id' => $pendingCharacter->id,
        'status' => ActivityApplication::STATUS_PENDING,
    ]);

    $approvedUser = User::factory()->create();
    $approvedCharacter = Character::factory()->primary()->create([
        'user_id' => $approvedUser->id,
        'lodestone_id' => '10000002',
    ]);
    $approvedApplication = ActivityApplication::factory()->approved($owner)->create([
        'activity_id' => $activity->id,
        'user_id' => $approvedUser->id,
        'selected_character_id' => $approvedCharacter->id,
    ]);

    $benchApplication = ActivityApplication::factory()->guest()->create([
        'activity_id' => $activity->id,
        'status' => ActivityApplication::STATUS_ON_BENCH,
        'reviewed_by_user_id' => $owner->id,
        'reviewed_at' => now(),
    ]);
    $benchApplication->load('selectedCharacter');
    $benchApplicationAccessToken = $benchApplication->guest_access_token;

    $declinedApplication = ActivityApplication::factory()->guest()->declined($owner)->create([
        'activity_id' => $activity->id,
    ]);

    $withdrawnApplication = ActivityApplication::factory()->create([
        'activity_id' => $activity->id,
        'status' => ActivityApplication::STATUS_WITHDRAWN,
        'reviewed_at' => now(),
    ]);

    $rosterSlot->update([
        'assigned_character_id' => $approvedCharacter->id,
        'assigned_by_user_id' => $owner->id,
    ]);

    $rosterSlot->fieldValues()->firstOrFail()->update([
        'value' => [
            'key' => 'mt',
            'label' => ['en' => 'MT'],
        ],
    ]);

    $benchSlot->update([
        'assigned_character_id' => $benchApplication->selected_character_id,
        'assigned_by_user_id' => $owner->id,
    ]);

    ActivitySlotAssignment::query()->create([
        'activity_id' => $activity->id,
        'group_id' => $group->id,
        'activity_slot_id' => $rosterSlot->id,
        'character_id' => $approvedCharacter->id,
        'application_id' => $approvedApplication->id,
        'field_values_snapshot' => [
            'raid_position' => [
                'key' => 'mt',
                'label' => ['en' => 'MT'],
            ],
        ],
        'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
        'assigned_at' => now()->subHour(),
        'assigned_by_user_id' => $owner->id,
    ]);

    ActivitySlotAssignment::query()->create([
        'activity_id' => $activity->id,
        'group_id' => $group->id,
        'activity_slot_id' => $benchSlot->id,
        'character_id' => $benchApplication->selected_character_id,
        'application_id' => $benchApplication->id,
        'field_values_snapshot' => [],
        'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
        'assigned_at' => now()->subMinutes(30),
        'assigned_by_user_id' => $owner->id,
    ]);

    $this->actingAs($owner);
    $sanitizer = app(TextInputSanitizer::class);
    $rawReason = "  Run\u{200B} cancelled due to\r\nserver instability.  ";
    $sanitizedReason = $sanitizer->sanitizeMultiline($rawReason);

    $response = $this->post(route('groups.dashboard.activities.cancel', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]), [
        'reason' => $rawReason,
    ]);

    $response->assertRedirect(route('groups.dashboard.activities.show', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    $activity->refresh();
    $rosterSlot->refresh();
    $benchSlot->refresh();
    $pendingApplication->refresh();
    $approvedApplication->refresh();
    $benchApplication->refresh();
    $declinedApplication->refresh();
    $withdrawnApplication->refresh();

    expect($activity->status)->toBe(Activity::STATUS_CANCELLED);
    expect($activity->cancellationReason())->toBe($sanitizedReason);

    expect($pendingApplication->status)->toBe(ActivityApplication::STATUS_CANCELLED)
        ->and($pendingApplication->review_reason)->toBe($sanitizedReason)
        ->and($approvedApplication->status)->toBe(ActivityApplication::STATUS_CANCELLED)
        ->and($approvedApplication->review_reason)->toBe($sanitizedReason)
        ->and($benchApplication->status)->toBe(ActivityApplication::STATUS_CANCELLED)
        ->and($benchApplication->review_reason)->toBe($sanitizedReason)
        ->and($benchApplication->guest_access_token)->toBeNull()
        ->and($declinedApplication->status)->toBe(ActivityApplication::STATUS_DECLINED)
        ->and($withdrawnApplication->status)->toBe(ActivityApplication::STATUS_WITHDRAWN);

    expect($rosterSlot->assigned_character_id)->toBe($approvedCharacter->id)
        ->and($rosterSlot->assigned_by_user_id)->toBe($owner->id)
        ->and($benchSlot->assigned_character_id)->toBe($benchApplication->selected_character_id)
        ->and($benchSlot->assigned_by_user_id)->toBe($owner->id)
        ->and($rosterSlot->fieldValues()->firstOrFail()->fresh()->value)->toBe([
            'key' => 'mt',
            'label' => ['en' => 'MT'],
        ]);

    expect(ActivitySlotAssignment::query()->where('activity_id', $activity->id)->count())->toBe(2);
    expect(ActivitySlotAssignment::query()->where('activity_id', $activity->id)->whereNull('ended_at')->count())->toBe(0);

    expect(AuditLog::query()->where('action', 'group.activity.updated')->count())->toBe(1);
    expect(AuditLog::query()->where('action', 'group.activity.application.cancelled')->count())->toBe(3);

    $activityAuditLog = AuditLog::query()->where('action', 'group.activity.updated')->sole();

    expect($activityAuditLog->metadata['changes']['status']['new'])->toBe(Activity::STATUS_CANCELLED)
        ->and($activityAuditLog->metadata['changes']['review_reason']['new'])->toBe($sanitizedReason);

    $statusResponse = $this->get(route('groups.activities.application.status', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'accessToken' => $benchApplicationAccessToken,
    ]));

    $statusResponse->assertNotFound();

    $editResponse = $this->get(route('groups.activities.application.edit-guest', [
        'group' => $group->slug,
        'activity' => $activity->id,
        'accessToken' => $benchApplicationAccessToken,
    ]));

    $editResponse->assertNotFound();

    $managementDataResponse = $this->getJson(route('groups.dashboard.activities.management-data', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    $managementDataResponse
        ->assertOk()
        ->assertJsonPath('activity.status', Activity::STATUS_CANCELLED)
        ->assertJsonPath('activity.cancellation_reason', $sanitizedReason);
});
