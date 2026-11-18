<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivityTag;
use App\Models\ActivityType;
use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\Group;
use App\Models\User;
use App\Services\Runs\RunBrowseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-05-27 12:00:00'));
    Storage::fake('public');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('renders the run discovery scaffold with lookup data', function () {
    Group::factory()->open()->create([
        'name' => 'Test Group',
        'slug' => 'testgrup',
    ]);

    createRunDiscoveryClass([
        'name' => 'White Mage',
        'shorthand' => 'WHM',
        'role' => 'healer',
        'icon_url' => 'https://example.com/icons/whm.png',
    ]);

    $activityType = createRunDiscoveryActivityType([
        'slug' => 'forked-tower',
        'draft_name' => ['en' => 'Forked Tower'],
        'draft_difficulty' => ActivityType::DIFFICULTY_CHAOTIC,
        'draft_prog_points' => [
            [
                'key' => 'clear',
                'label' => ['en' => 'Clear'],
                'order' => 1,
            ],
        ],
    ]);

    $this->actingAs(User::factory()->create())->withSession(['locale' => 'en']);
    $this->get('/runs?activity_type=forked-tower')->assertRedirect('/en/runs?activity_type=forked-tower');
    $this->get('/en/dashboard/runs?activity_type=forked-tower')->assertMovedPermanently()
        ->assertRedirect('/en/runs?activity_type=forked-tower');
    $this->get('/en/runs?activity_type=forked-tower')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Runs/Index')
            ->has('lookups.activity_types', 1)
            ->has('lookups.class_options', 1)
            ->has('lookups.groups', 1)
            ->where('lookups.activity_types.0.id', $activityType->id)
            ->where('lookups.activity_types.0.value', 'forked-tower')
            ->where('lookups.activity_types.0.label', 'Forked Tower')
            ->where('lookups.activity_types.0.prog_points.0.value', 'clear')
            ->where('lookups.class_options.0.key', 'WHM')
            ->where('lookups.class_options.0.icon_url', 'https://example.com/icons/whm.png')
            ->where('lookups.regions.0.value', 'NA')
            ->where('lookups.regions.0.label', 'North America')
            ->where('lookups.datacenters.0.value', 'Aether')
            ->where('lookups.groups.0.value', 'testgrup'));
});

it('ranks current activity suggestions first and refreshes popularity only on the scheduled command', function () {
    $this->actingAs(User::factory()->create());
    $group = Group::factory()->open()->create();
    $savage = createRunDiscoveryActivityType(['slug' => 'aac-heavyweight-m1-savage', 'draft_name' => ['en' => 'AAC Heavyweight M1']]);
    $tower = createRunDiscoveryActivityType(['slug' => 'forked-tower', 'draft_name' => ['en' => 'Forked Tower']]);
    $older = createRunDiscoveryActivityType(['slug' => 'a-legacy-raid', 'draft_name' => ['en' => 'A legacy raid']]);
    $addRun = fn (ActivityType $type, array $overrides = []) => Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $type->current_published_version_id,
        'status' => Activity::STATUS_COMPLETE,
        'starts_at' => now()->subDay(),
        'is_public' => true,
        ...$overrides,
    ]);
    $suggestions = fn () => array_column(
        $this->getJson(route('dashboard.runs.search-options'))->assertOk()->json('activity_types'), 'value'
    );

    $addRun($tower);
    $addRun($older);
    $addRun($older);
    $addRun($savage, ['is_public' => false]);
    $addRun($savage, ['status' => Activity::STATUS_CANCELLED]);
    $addRun($savage, ['status' => Activity::STATUS_DRAFT]);
    $addRun($savage, ['starts_at' => now()->subDays(31)]);

    expect($suggestions())->toBe([$savage->slug, $tower->slug, $older->slug]);
    $this->artisan('runs:refresh-activity-popularity')->assertSuccessful();
    expect($suggestions())->toBe([$tower->slug, $savage->slug, $older->slug]);

    $addRun($savage);
    $addRun($savage);
    expect($suggestions())->toBe([$tower->slug, $savage->slug, $older->slug]);
    $this->artisan('runs:refresh-activity-popularity')->assertSuccessful();
    expect($suggestions())->toBe([$savage->slug, $tower->slug, $older->slug]);
});

it('allows group filters for hidden memberships and ownership while preserving access boundaries', function (string $role) {
    $viewer = User::factory()->create();
    $hidden = Group::factory()->hidden()->inviteOnly()->create([
        'owner_id' => $role === 'owner' ? $viewer->id : User::factory()->create()->id,
        'group_type' => Group::TYPE_STATIC,
    ]);
    $visible = Group::factory()->open()->create(['group_type' => Group::TYPE_STATIC]);
    if (in_array($role, ['member', 'banned'], true)) {
        $hidden->memberships()->create(['user_id' => $viewer->id, 'role' => 'member', 'joined_at' => now()]);
    }
    if ($role === 'banned') {
        $hidden->bans()->create(['user_id' => $viewer->id, 'banned_by_user_id' => $hidden->owner_id]);
    }
    $type = createRunDiscoveryActivityType(['slug' => 'selected-type']);
    $otherType = createRunDiscoveryActivityType(['slug' => 'other-type']);
    $runs = collect([
        [$hidden, $type], [$visible, $type], [$hidden, $otherType],
    ])->map(fn (array $entry) => Activity::factory()->create([
        'group_id' => $entry[0]->id,
        'activity_type_id' => $entry[1]->id,
        'activity_type_version_id' => $entry[1]->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'starts_at' => now()->addDay(),
        'is_public' => false,
        'needs_application' => true,
    ]));
    $allowed = in_array($role, ['owner', 'member'], true);

    $this->actingAs($viewer)->getJson(route('dashboard.runs.search-options'))
        ->assertOk()
        ->assertJsonCount($allowed ? 2 : 1, 'groups')
        ->assertJsonFragment(['value' => $visible->slug]);

    $this->actingAs($viewer)->get(route('dashboard.runs.index'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('lookups.groups', $allowed ? 2 : 1)
        ->where('lookups.groups', function ($groups) use ($hidden, $allowed) {
            $option = collect($groups)->firstWhere('value', $hidden->slug);
            expect($option !== null)->toBe($allowed);
            if ($allowed) {
                expect($option['group_type'])->toBe(Group::TYPE_STATIC);
            }

            return true;
        }));

    $response = $this->getJson(route('dashboard.runs.discover', [
        'group' => $hidden->slug,
        'activity_type' => $type->slug,
        'group_type' => Group::TYPE_STATIC,
        'timezone' => 'UTC',
        'date_range' => 'upcoming',
    ]));
    if ($allowed) {
        $response->assertOk()->assertJsonPath('ids', [$runs->first()->id]);
    } else {
        $response->assertUnprocessable()->assertJsonValidationErrors('group');
    }
})->with(['owner', 'member', 'outsider', 'banned']);

it('returns matching discoverable run ids for the filter payload', function () {
    $viewer = User::factory()->create();

    createRunDiscoveryClass([
        'name' => 'White Mage',
        'shorthand' => 'WHM',
        'role' => 'healer',
        'icon_url' => 'https://example.com/icons/whm.png',
    ]);

    $activityType = createRunDiscoveryActivityType([
        'slug' => 'savage-raids',
        'draft_name' => ['en' => 'Savage Raids'],
        'draft_difficulty' => ActivityType::DIFFICULTY_SAVAGE,
        'draft_prog_points' => [
            [
                'key' => 'enrage',
                'label' => ['en' => 'Enrage'],
                'order' => 1,
            ],
        ],
    ]);

    $matchingGroup = Group::factory()
        ->open()
        ->create([
            'datacenter' => 'Light',
            'group_type' => Group::TYPE_COMMUNITY,
            'profile_picture_url' => '/storage/groups/moon-raiders.webp',
            'discord_invite_url' => 'https://discord.gg/moon-raiders',
            'preferred_languages' => ['en'],
            'voice_expectation' => 'preferred',
        ]);

    $matchingActivity = Activity::factory()->create([
        'group_id' => $matchingGroup->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Moon Enrage Push',
        'notes' => 'Bring mitigation notes and be ready for enrage cleanup.',
        'starts_at' => now()->addWeek()->startOfWeek()->addDays(2)->setTime(18, 30),
        'datacenter' => 'Light',
        'intensity' => Activity::INTENSITY_MIDCORE,
        'run_style' => Activity::RUN_STYLE_PROGRESSION,
        'target_prog_point_key' => 'enrage',
        'is_public' => true,
        'needs_application' => true,
        'allow_guest_applications' => false,
    ]);

    $excludedGroup = Group::factory()
        ->open()
        ->create([
            'datacenter' => 'Aether',
            'group_type' => Group::TYPE_COMMUNITY,
            'preferred_languages' => ['en'],
            'voice_expectation' => 'preferred',
        ]);

    Activity::factory()->create([
        'group_id' => $excludedGroup->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Moon Enrage Push',
        'starts_at' => now()->addWeek()->startOfWeek()->addDays(2)->setTime(18, 30),
        'datacenter' => 'Aether',
        'intensity' => Activity::INTENSITY_MIDCORE,
        'run_style' => Activity::RUN_STYLE_PROGRESSION,
        'target_prog_point_key' => 'enrage',
        'is_public' => true,
        'needs_application' => true,
        'allow_guest_applications' => false,
    ]);

    $params = [
        'query' => 'mitigation',
        'activity_type' => 'savage-raids',
        'prog_point' => 'enrage',
        'region' => 'EU',
        'datacenter' => 'Light',
        'group' => $matchingGroup->slug,
        'timezone' => 'Europe/London',
        'date_range' => 'next_week',
        'time_of_day' => 'evening',
        'run_style' => Activity::RUN_STYLE_PROGRESSION,
        'beginner_friendly' => false,
        'language' => 'en',
        'role_category' => 'healer',
        'class_keys' => ['WHM'],
        'group_type' => Group::TYPE_COMMUNITY,
        'application_status' => 'applications_open',
        'intensity' => Activity::INTENSITY_MIDCORE,
        'voice_expectation' => 'preferred',
    ];

    $this->actingAs($viewer)
        ->getJson(route('dashboard.runs.discover', $params))
        ->assertOk()
        ->assertJsonPath('ids', [$matchingActivity->id])
        ->assertJsonPath('items.0.id', $matchingActivity->id)
        ->assertJsonPath('items.0.title', 'Moon Enrage Push')
        ->assertJsonPath('items.0.description', 'Bring mitigation notes and be ready for enrage cleanup.')
        ->assertJsonPath('items.0.group_name', $matchingGroup->name)
        ->assertJsonPath('items.0.group_slug', $matchingGroup->slug)
        ->assertJsonPath('items.0.group_profile_picture_url', '/storage/groups/moon-raiders.webp')
        ->assertJsonPath('items.0.group_discord_invite_url', 'https://discord.gg/moon-raiders')
        ->assertJsonPath('items.0.can_apply', true);

    $responseImageUrl = $this->actingAs($viewer)
        ->getJson(route('dashboard.runs.discover', $params))
        ->json('items.0.image_url');

    expect($responseImageUrl)->toContain('/storage/runs/generated-discovery/');

    $imagePath = ltrim((string) parse_url($responseImageUrl, PHP_URL_PATH), '/');
    expect($imagePath)->toEndWith('.png')
        ->not->toEndWith('.svg');

    Storage::disk('public')->assertExists(str_replace('storage/', '', $imagePath));
});

it('matches discoverable runs by activity tags', function () {
    $viewer = User::factory()->create();

    $activityType = createRunDiscoveryActivityType([
        'slug' => 'aac-light-heavyweight-m4-savage',
        'draft_name' => ['en' => 'AAC Light-heavyweight M4 (Savage)'],
        'draft_small_image_url' => '/type-icon.webp',
        'draft_banner_image_url' => '/type-banner.webp',
        'draft_prog_points' => [['key' => 'sunrise', 'label' => ['en' => 'Sunrise'], 'banner_image_url' => '/sunrise.webp']],
    ]);
    $activityType->tags()->attach(ActivityTag::query()->create(['name' => 'M4S']));

    $group = Group::factory()
        ->open()
        ->create([
            'datacenter' => 'Light',
            'group_type' => Group::TYPE_COMMUNITY,
            'preferred_languages' => ['en'],
        ]);

    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Late Night Prog',
        'target_prog_point_key' => 'sunrise',
        'starts_at' => now()->addWeek()->startOfWeek()->addDays(2)->setTime(20, 0),
        'datacenter' => 'Light',
        'is_public' => true,
        'needs_application' => true,
    ]);

    $this->actingAs($viewer)
        ->getJson(route('dashboard.runs.discover', [
            'query' => 'M4S',
            'timezone' => 'Europe/London',
            'date_range' => 'next_week',
            'group_type' => Group::TYPE_COMMUNITY,
        ]))
        ->assertOk()
        ->assertJsonPath('ids', [$activity->id])
        ->assertJsonPath('items.0.title', 'Late Night Prog')
        ->assertJsonPath('items.0.activity_type_name', 'AAC Light-heavyweight M4 (Savage)')
        ->assertJsonPath('items.0.image_url', '/sunrise.webp')
        ->assertJsonPath('cards.0.image_urls', ['/sunrise.webp', '/type-banner.webp', '/type-icon.webp'])
        ->assertJsonPath('cards.0.activity_type.banner_image_url', '/sunrise.webp');
});

it('defaults date filtering to upcoming runs from now', function () {
    $viewer = User::factory()->create();

    $activityType = createRunDiscoveryActivityType([
        'slug' => 'default-upcoming-check',
        'draft_name' => ['en' => 'Default Upcoming Check'],
    ]);

    $group = Group::factory()
        ->open()
        ->create([
            'datacenter' => 'Light',
            'group_type' => Group::TYPE_COMMUNITY,
            'preferred_languages' => ['en'],
        ]);

    Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Already Started',
        'starts_at' => now()->subHour(),
        'datacenter' => 'Light',
        'is_public' => true,
        'needs_application' => true,
    ]);

    $futureRun = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Far Future Run',
        'starts_at' => now()->addWeeks(8),
        'datacenter' => 'Light',
        'is_public' => true,
        'needs_application' => true,
    ]);

    $this->actingAs($viewer)
        ->getJson(route('dashboard.runs.discover', [
            'timezone' => 'Europe/London',
            'group_type' => Group::TYPE_COMMUNITY,
        ]))
        ->assertOk()
        ->assertJsonPath('ids', [$futureRun->id]);
});

it('filters discoverable runs by content category and custom dates', function () {
    $viewer = User::factory()->create();

    $savageType = createRunDiscoveryActivityType([
        'slug' => 'custom-date-savage',
        'draft_name' => ['en' => 'Custom Date Savage'],
        'draft_difficulty' => ActivityType::DIFFICULTY_SAVAGE,
    ]);

    $ultimateType = createRunDiscoveryActivityType([
        'slug' => 'custom-date-ultimate',
        'draft_name' => ['en' => 'Custom Date Ultimate'],
        'draft_difficulty' => ActivityType::DIFFICULTY_ULTIMATE,
    ]);

    $group = Group::factory()
        ->open()
        ->create([
            'datacenter' => 'Light',
            'group_type' => Group::TYPE_COMMUNITY,
            'preferred_languages' => ['en'],
        ]);

    $targetDate = now()->addDays(10)->toDateString();

    $savageRun = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $savageType->id,
        'activity_type_version_id' => $savageType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Savage Custom Day',
        'starts_at' => now()->addDays(10)->setTime(20, 0),
        'datacenter' => 'Light',
        'is_public' => true,
        'needs_application' => true,
    ]);

    $ultimateRun = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $ultimateType->id,
        'activity_type_version_id' => $ultimateType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Ultimate Custom Day',
        'starts_at' => now()->addDays(10)->setTime(21, 0),
        'datacenter' => 'Light',
        'is_public' => true,
        'needs_application' => true,
    ]);

    Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $savageType->id,
        'activity_type_version_id' => $savageType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Savage Outside Range',
        'starts_at' => now()->addDays(15)->setTime(20, 0),
        'datacenter' => 'Light',
        'is_public' => true,
        'needs_application' => true,
    ]);

    $commonParams = [
        'timezone' => 'UTC',
        'group_type' => Group::TYPE_COMMUNITY,
    ];

    $this->actingAs($viewer)
        ->getJson(route('dashboard.runs.discover', array_merge($commonParams, [
            'activity_category' => ActivityType::DIFFICULTY_SAVAGE,
            'date_range' => 'custom_day',
            'date_from' => $targetDate,
        ])))
        ->assertOk()
        ->assertJsonPath('ids', [$savageRun->id]);

    $this->actingAs($viewer)
        ->getJson(route('dashboard.runs.discover', array_merge($commonParams, [
            'date_range' => 'custom_range',
            'date_from' => $targetDate,
            'date_to' => now()->addDays(12)->toDateString(),
        ])))
        ->assertOk()
        ->assertJsonPath('ids', [$savageRun->id, $ultimateRun->id]);
});

it('treats melee-style role hints as dps slots in discovery', function () {
    $viewer = User::factory()->create();

    $activityType = createRunDiscoveryActivityType([
        'slug' => 'melee-check',
        'draft_name' => ['en' => 'Melee Check'],
        'draft_layout_schema' => [
            'groups' => [
                [
                    'key' => 'party-a',
                    'label' => ['en' => 'Party A'],
                    'size' => 1,
                    'composition_hints' => [
                        [
                            'position' => 1,
                            'accepts' => [
                                ['type' => 'role', 'key' => 'melee'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $group = Group::factory()
        ->open()
        ->create([
            'datacenter' => 'Light',
            'group_type' => Group::TYPE_COMMUNITY,
            'preferred_languages' => ['en'],
        ]);

    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Melee Spot Open',
        'starts_at' => now()->addWeek()->startOfWeek()->addDay()->setTime(19, 0),
        'datacenter' => 'Light',
        'is_public' => true,
        'needs_application' => false,
    ]);

    $this->actingAs($viewer)
        ->getJson(route('dashboard.runs.discover', [
            'timezone' => 'Europe/London',
            'date_range' => 'next_week',
            'group_type' => Group::TYPE_COMMUNITY,
            'role_category' => 'dps',
        ]))
        ->assertOk()
        ->assertJsonPath('ids', [$activity->id])
        ->assertJsonPath('items.0.id', $activity->id)
        ->assertJsonPath('items.0.role_slots.2.key', 'dps')
        ->assertJsonPath('items.0.role_slots.2.count', 1);
});

it('only exposes private moderator-visible runs to moderators of that group', function () {
    $moderator = User::factory()->create();
    $outsider = User::factory()->create();
    $activityType = createRunDiscoveryActivityType([
        'slug' => 'criterion',
        'draft_name' => ['en' => 'Criterion'],
    ]);

    $group = Group::factory()
        ->inviteOnly()
        ->create([
            'owner_id' => $moderator->id,
            'datacenter' => 'Light',
        ]);

    $privateDraftActivity = Activity::factory()
        ->private()
        ->create([
            'group_id' => $group->id,
            'activity_type_id' => $activityType->id,
            'activity_type_version_id' => $activityType->current_published_version_id,
            'status' => Activity::STATUS_DRAFT,
            'starts_at' => now()->addWeek()->startOfWeek()->addDays(1)->setTime(19, 0),
            'datacenter' => 'Light',
        ]);

    $params = [
        'timezone' => 'Europe/London',
        'date_range' => 'next_week',
        'group_type' => Group::TYPE_COMMUNITY,
    ];

    $this->actingAs($outsider)
        ->getJson(route('dashboard.runs.discover', $params))
        ->assertOk()
        ->assertJsonPath('ids', [])
        ->assertJsonPath('items', []);

    $this->getJson(route('dashboard.runs.discover-count', $params))
        ->assertOk()->assertExactJson(['total' => 0]);

    $this->actingAs($moderator)
        ->getJson(route('dashboard.runs.discover', $params))
        ->assertOk()
        ->assertJsonPath('ids', [$privateDraftActivity->id])
        ->assertJsonPath('items.0.id', $privateDraftActivity->id)
        ->assertJsonPath('items.0.can_apply', true);
});

it('shows runs the viewer can apply to, self-assign to, or already has an application for', function () {
    $viewer = User::factory()->create();
    $viewerCharacter = Character::factory()->primary()->create([
        'user_id' => $viewer->id,
    ]);

    $otherUser = User::factory()->create();
    $otherCharacter = Character::factory()->primary()->create([
        'user_id' => $otherUser->id,
    ]);

    $activityType = createRunDiscoveryActivityType([
        'slug' => 'chaotic-alliance',
        'draft_name' => ['en' => 'Chaotic Alliance'],
    ]);

    $group = Group::factory()
        ->open()
        ->create([
            'datacenter' => 'Light',
            'group_type' => Group::TYPE_COMMUNITY,
            'preferred_languages' => ['en'],
        ]);

    $applicationRun = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Open Applications',
        'starts_at' => now()->addWeek()->startOfWeek()->addDay()->setTime(19, 0),
        'datacenter' => 'Light',
        'is_public' => true,
        'needs_application' => true,
    ]);

    $directJoinRun = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Open Self Assign',
        'starts_at' => now()->addWeek()->startOfWeek()->addDays(2)->setTime(19, 0),
        'datacenter' => 'Light',
        'is_public' => true,
        'needs_application' => false,
    ]);

    ActivitySlot::factory()->create([
        'activity_id' => $directJoinRun->id,
        'group_key' => 'party-a',
        'slot_key' => 'party-a-slot-open',
    ]);

    $filledDirectJoinRun = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Filled Self Assign',
        'starts_at' => now()->addWeek()->startOfWeek()->addDays(3)->setTime(19, 0),
        'datacenter' => 'Light',
        'is_public' => true,
        'needs_application' => false,
    ]);

    ActivitySlot::factory()->assignedTo($otherCharacter)->create([
        'activity_id' => $filledDirectJoinRun->id,
        'group_key' => 'party-a',
        'slot_key' => 'party-a-slot-filled',
    ]);

    $filledDirectJoinRun->slots()->update([
        'assigned_character_id' => $otherCharacter->id,
        'assigned_by_user_id' => $otherUser->id,
    ]);

    $alreadyAppliedRun = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Already Applied',
        'starts_at' => now()->addWeek()->startOfWeek()->addDays(4)->setTime(19, 0),
        'datacenter' => 'Light',
        'is_public' => true,
        'needs_application' => true,
    ]);

    ActivityApplication::factory()->create([
        'activity_id' => $alreadyAppliedRun->id,
        'user_id' => $viewer->id,
        'selected_character_id' => $viewerCharacter->id,
    ]);

    $this->actingAs($viewer)
        ->getJson(route('dashboard.runs.discover', [
            'timezone' => 'Europe/London',
            'date_range' => 'next_week',
            'group_type' => Group::TYPE_COMMUNITY,
        ]))
        ->assertOk()
        ->assertJsonPath('ids', [$applicationRun->id, $directJoinRun->id, $alreadyAppliedRun->id])
        ->assertJsonPath('items.0.id', $applicationRun->id)
        ->assertJsonPath('items.0.has_existing_application', false)
        ->assertJsonPath('items.0.can_apply', true)
        ->assertJsonPath('items.1.id', $directJoinRun->id)
        ->assertJsonPath('items.1.has_existing_application', false)
        ->assertJsonPath('items.1.can_apply', false)
        ->assertJsonPath('items.2.id', $alreadyAppliedRun->id)
        ->assertJsonPath('items.2.has_existing_application', true)
        ->assertJsonPath('items.2.can_apply', false);
});

it('lets users apply to published future runs in discovery', function () {
    $viewer = User::factory()->create();
    Character::factory()->primary()->create([
        'user_id' => $viewer->id,
    ]);

    $activityType = createRunDiscoveryActivityType([
        'slug' => 'published-applications',
        'draft_name' => ['en' => 'Published Applications'],
    ]);

    $group = Group::factory()
        ->open()
        ->create([
            'datacenter' => 'Light',
            'group_type' => Group::TYPE_COMMUNITY,
            'preferred_languages' => ['en'],
        ]);

    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'status' => Activity::STATUS_ASSIGNED,
        'title' => 'Published Future Run',
        'starts_at' => now()->addWeek()->startOfWeek()->addDays(2)->setTime(19, 0),
        'datacenter' => 'Light',
        'is_public' => true,
        'needs_application' => true,
    ]);

    $this->actingAs($viewer)
        ->getJson(route('dashboard.runs.discover', [
            'timezone' => 'Europe/London',
            'date_range' => 'next_week',
            'group_type' => Group::TYPE_COMMUNITY,
            'application_status' => 'applications_open',
        ]))
        ->assertOk()
        ->assertJsonPath('ids', [$activity->id])
        ->assertJsonPath('items.0.can_apply', true)
        ->assertJsonPath('items.0.links.apply', route('groups.activities.application', [
            'locale' => app()->getLocale(),
            'group' => $group->slug,
            'activity' => $activity->id,
        ]));
});

it('does not return full application runs in discovery search', function () {
    $viewer = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherCharacter = Character::factory()->primary()->create([
        'user_id' => $otherUser->id,
    ]);

    $activityType = createRunDiscoveryActivityType([
        'slug' => 'full-search-check',
        'draft_name' => ['en' => 'Full Search Check'],
    ]);

    $group = Group::factory()
        ->open()
        ->create([
            'datacenter' => 'Light',
            'group_type' => Group::TYPE_COMMUNITY,
            'preferred_languages' => ['en'],
        ]);

    $openRun = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Need One More',
        'starts_at' => now()->addWeek()->startOfWeek()->addDays(2)->setTime(19, 0),
        'datacenter' => 'Light',
        'is_public' => true,
        'needs_application' => true,
    ]);

    $fullRun = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Need Nobody',
        'starts_at' => now()->addWeek()->startOfWeek()->addDays(3)->setTime(19, 0),
        'datacenter' => 'Light',
        'is_public' => true,
        'needs_application' => true,
    ]);

    $fullRun->slots()->update([
        'assigned_character_id' => $otherCharacter->id,
        'assigned_by_user_id' => $otherUser->id,
    ]);

    $this->actingAs($viewer)
        ->getJson(route('dashboard.runs.discover', [
            'query' => 'Need',
            'timezone' => 'Europe/London',
            'date_range' => 'next_week',
            'group_type' => Group::TYPE_COMMUNITY,
        ]))
        ->assertOk()
        ->assertJsonPath('ids', [$openRun->id])
        ->assertJsonPath('items.0.id', $openRun->id)
        ->assertJsonPath('items.0.can_apply', true);
});

it('only discovers members-only runs after joining the group', function () {
    $viewer = User::factory()->create();
    $activityType = createRunDiscoveryActivityType([
        'slug' => 'members-only-discovery',
        'draft_name' => ['en' => 'Members Only Discovery'],
    ]);
    $group = Group::factory()
        ->open()
        ->create([
            'datacenter' => 'Light',
            'group_type' => Group::TYPE_COMMUNITY,
            'preferred_languages' => ['en'],
        ]);

    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Members First Pull',
        'starts_at' => now()->addWeek()->startOfWeek()->addDays(2)->setTime(19, 0),
        'datacenter' => 'Light',
        'is_public' => false,
        'needs_application' => true,
        'allow_guest_applications' => false,
    ]);

    $params = [
        'query' => 'Members First',
        'timezone' => 'Europe/London',
        'date_range' => 'next_week',
        'group_type' => Group::TYPE_COMMUNITY,
    ];

    $this->actingAs($viewer)
        ->getJson(route('dashboard.runs.discover', $params))
        ->assertOk()
        ->assertJsonPath('ids', [])
        ->assertJsonCount(0, 'items');

    $group->memberships()->create([
        'user_id' => $viewer->id,
        'role' => 'member',
        'joined_at' => now(),
    ]);

    $this->actingAs($viewer)
        ->getJson(route('dashboard.runs.discover', $params))
        ->assertOk()
        ->assertJsonPath('ids', [$activity->id])
        ->assertJsonPath('items.0.can_apply', true)
        ->assertJsonPath('items.0.links.apply', route('groups.activities.application', [
            'locale' => app()->getLocale(),
            'group' => $group->slug,
            'activity' => $activity->id,
        ]));
});

it('sorts discovery results by the selected sort option before pagination', function () {
    $viewer = User::factory()->create();

    $activityType = createRunDiscoveryActivityType([
        'slug' => 'sort-check',
        'draft_name' => ['en' => 'Sort Check'],
        'draft_layout_schema' => [
            'groups' => [],
        ],
    ]);

    $group = Group::factory()
        ->open()
        ->create([
            'datacenter' => 'Light',
            'group_type' => Group::TYPE_COMMUNITY,
            'preferred_languages' => ['en'],
        ]);

    $makeRun = function (string $title, int $startDay, int $createdDaysAgo, int $updatedDaysAgo, int $openSlots, int $filledSlots) use ($activityType, $group): Activity {
        $activity = Activity::factory()->create([
            'group_id' => $group->id,
            'activity_type_id' => $activityType->id,
            'activity_type_version_id' => $activityType->current_published_version_id,
            'status' => Activity::STATUS_SCHEDULED,
            'title' => $title,
            'starts_at' => now()->addWeek()->startOfWeek()->addDays($startDay)->setTime(19, 0),
            'created_at' => now()->subDays($createdDaysAgo),
            'updated_at' => now()->subDays($updatedDaysAgo),
            'datacenter' => 'Light',
            'is_public' => true,
            'needs_application' => true,
        ]);

        for ($slotIndex = 1; $slotIndex <= $openSlots + $filledSlots; $slotIndex++) {
            ActivitySlot::factory()->create([
                'activity_id' => $activity->id,
                'slot_key' => sprintf('%s-slot-%d', str($title)->slug(), $slotIndex),
                'assigned_character_id' => $slotIndex > $openSlots ? Character::factory() : null,
                'position_in_group' => $slotIndex,
                'sort_order' => $slotIndex,
            ]);
        }

        return $activity;
    };

    $soonest = $makeRun('Soonest', 1, 4, 4, 1, 1);
    $newestPosted = $makeRun('Newest Posted', 3, 1, 3, 2, 2);
    $recentlyUpdated = $makeRun('Recently Updated', 2, 2, 0, 3, 1);
    $mostOpenSlots = $makeRun('Most Open Slots', 4, 0, 1, 5, 3);
    ActivitySlot::factory()->create([
        'activity_id' => $recentlyUpdated->id,
        'slot_kind' => ActivitySlot::SLOT_KIND_BENCH,
        'assigned_character_id' => Character::factory(),
    ]);

    $params = [
        'timezone' => 'Europe/London',
        'date_range' => 'next_week',
        'group_type' => Group::TYPE_COMMUNITY,
    ];

    $this->actingAs($viewer)
        ->getJson(route('dashboard.runs.discover', array_merge($params, [
            'sort' => 'starting_soonest',
        ])))
        ->assertOk()
        ->assertJsonPath('ids', [$soonest->id, $recentlyUpdated->id, $newestPosted->id, $mostOpenSlots->id]);

    $this->actingAs($viewer)
        ->getJson(route('dashboard.runs.discover', array_merge($params, [
            'sort' => 'newest_posted',
        ])))
        ->assertOk()
        ->assertJsonPath('ids', [$mostOpenSlots->id, $newestPosted->id, $recentlyUpdated->id, $soonest->id]);

    $this->actingAs($viewer)
        ->getJson(route('dashboard.runs.discover', array_merge($params, [
            'sort' => 'recently_updated',
        ])))
        ->assertOk()
        ->assertJsonPath('ids', [$recentlyUpdated->id, $mostOpenSlots->id, $newestPosted->id, $soonest->id]);

    $this->actingAs($viewer)
        ->getJson(route('dashboard.runs.discover', array_merge($params, [
            'sort' => 'open_slots',
        ])))
        ->assertOk()
        ->assertJsonPath('ids', [$mostOpenSlots->id, $recentlyUpdated->id, $newestPosted->id, $soonest->id]);

    $noRoster = $makeRun('No roster yet', 0, 5, 5, 0, 0);
    ActivityApplication::factory()->create(['activity_id' => $noRoster->id, 'user_id' => $viewer->id]);

    $this->getJson(route('dashboard.runs.discover', [...$params, 'sort' => 'most_full']))
        ->assertOk()
        ->assertJsonPath('ids', [$soonest->id, $newestPosted->id, $mostOpenSlots->id, $recentlyUpdated->id, $noRoster->id]);

    $this->getJson(route('dashboard.runs.discover', [...$params, 'sort' => 'least_full']))
        ->assertOk()
        ->assertJsonPath('ids', [$recentlyUpdated->id, $mostOpenSlots->id, $soonest->id, $newestPosted->id, $noRoster->id]);
});

it('matches time-of-day filters in the user timezone instead of the app timezone', function () {
    config(['app.timezone' => 'UTC']);

    $viewer = User::factory()->create();
    createRunDiscoveryClass([
        'name' => 'White Mage',
        'shorthand' => 'WHM',
        'role' => 'healer',
    ]);

    $activityType = createRunDiscoveryActivityType([
        'slug' => 'ultimate-raids',
        'draft_name' => ['en' => 'Ultimate Raids'],
    ]);

    $group = Group::factory()
        ->open()
        ->create([
            'datacenter' => 'Aether',
            'preferred_languages' => ['en'],
        ]);

    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Timezone Check',
        'starts_at' => now()->addWeek()->startOfWeek()->addDays(2)->setTime(20, 0),
        'datacenter' => 'Aether',
        'is_public' => true,
    ]);

    $afternoonParams = [
        'timezone' => 'America/New_York',
        'date_range' => 'next_week',
        'time_of_day' => 'afternoon',
        'language' => 'en',
        'group_type' => Group::TYPE_COMMUNITY,
    ];

    $eveningParams = [
        'timezone' => 'America/New_York',
        'date_range' => 'next_week',
        'time_of_day' => 'evening',
        'language' => 'en',
        'group_type' => Group::TYPE_COMMUNITY,
    ];

    $this->actingAs($viewer)
        ->getJson(route('dashboard.runs.discover', $afternoonParams))
        ->assertOk()
        ->assertJsonPath('ids', [$activity->id])
        ->assertJsonPath('items.0.id', $activity->id);

    $this->actingAs($viewer)
        ->getJson(route('dashboard.runs.discover', $eveningParams))
        ->assertOk()
        ->assertJsonPath('ids', [])
        ->assertJsonPath('items', []);
});

it('uses the updated evening and night boundaries for time-of-day filtering', function () {
    config(['app.timezone' => 'UTC']);

    $viewer = User::factory()->create();

    $activityType = createRunDiscoveryActivityType([
        'slug' => 'boundary-check',
        'draft_name' => ['en' => 'Boundary Check'],
    ]);

    $group = Group::factory()
        ->open()
        ->create([
            'datacenter' => 'Light',
            'group_type' => Group::TYPE_COMMUNITY,
            'preferred_languages' => ['en'],
        ]);

    $eveningRun = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Late Evening Run',
        'starts_at' => now()->addWeek()->startOfWeek()->addDays(2)->setTime(22, 0),
        'datacenter' => 'Light',
        'is_public' => true,
    ]);

    $nightRun = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Night Run',
        'starts_at' => now()->addWeek()->startOfWeek()->addDays(3)->setTime(23, 0),
        'datacenter' => 'Light',
        'is_public' => true,
    ]);

    $morningRun = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Morning Run',
        'starts_at' => now()->addWeek()->startOfWeek()->addDays(4)->setTime(5, 0),
        'datacenter' => 'Light',
        'is_public' => true,
    ]);

    $commonParams = [
        'timezone' => 'UTC',
        'date_range' => 'next_week',
        'group_type' => Group::TYPE_COMMUNITY,
        'language' => 'en',
    ];

    $this->actingAs($viewer)
        ->getJson(route('dashboard.runs.discover', array_merge($commonParams, [
            'time_of_day' => 'evening',
        ])))
        ->assertOk()
        ->assertJsonPath('ids', [$eveningRun->id]);

    $this->actingAs($viewer)
        ->getJson(route('dashboard.runs.discover', array_merge($commonParams, [
            'time_of_day' => 'night',
        ])))
        ->assertOk()
        ->assertJsonPath('ids', [$nightRun->id]);

    $this->actingAs($viewer)
        ->getJson(route('dashboard.runs.discover', array_merge($commonParams, [
            'time_of_day' => 'morning',
        ])))
        ->assertOk()
        ->assertJsonPath('ids', [$morningRun->id]);
});

it('lets users save and unsave discoverable runs and filter to saved runs only', function () {
    $viewer = User::factory()->create();

    $activityType = createRunDiscoveryActivityType([
        'slug' => 'saved-run-check',
        'draft_name' => ['en' => 'Saved Run Check'],
    ]);

    $group = Group::factory()
        ->open()
        ->create([
            'datacenter' => 'Light',
            'group_type' => Group::TYPE_COMMUNITY,
            'preferred_languages' => ['en'],
        ]);

    $savedRun = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Save Me',
        'starts_at' => now()->addWeek()->startOfWeek()->addDays(2)->setTime(18, 0),
        'datacenter' => 'Light',
        'is_public' => true,
        'needs_application' => true,
    ]);

    $otherRun = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Leave Me',
        'starts_at' => now()->addWeek()->startOfWeek()->addDays(3)->setTime(18, 0),
        'datacenter' => 'Light',
        'is_public' => true,
        'needs_application' => true,
    ]);
    User::factory()->create()->savedActivities()->attach($otherRun);

    $params = [
        'timezone' => 'Europe/London',
        'date_range' => 'next_week',
        'group_type' => Group::TYPE_COMMUNITY,
    ];

    $this->actingAs($viewer)
        ->postJson(route('dashboard.runs.save', [
            'activity' => $savedRun->id,
        ]))
        ->assertOk()
        ->assertJsonPath('saved', true);

    $this->assertDatabaseHas('activity_saves', [
        'activity_id' => $savedRun->id,
        'user_id' => $viewer->id,
    ]);

    $this->actingAs($viewer)
        ->getJson(route('dashboard.runs.discover', array_merge($params, [
            'saved_only' => true,
        ])))
        ->assertOk()
        ->assertJsonPath('ids', [$savedRun->id])
        ->assertJsonPath('items.0.id', $savedRun->id)
        ->assertJsonPath('items.0.is_saved', true);

    $this->actingAs($viewer)
        ->getJson(route('dashboard.runs.discover', $params))
        ->assertOk()
        ->assertJsonPath('items.0.id', $savedRun->id)
        ->assertJsonPath('items.0.is_saved', true)
        ->assertJsonPath('items.1.id', $otherRun->id)
        ->assertJsonPath('items.1.is_saved', false)
        ->assertJsonPath('cards.0.is_saved', true)
        ->assertJsonPath('cards.1.is_saved', false)
        ->assertJsonPath('cards.1.can_bookmark', true);

    $this->actingAs($viewer)
        ->deleteJson(route('dashboard.runs.unsave', [
            'activity' => $savedRun->id,
        ]))
        ->assertOk()
        ->assertJsonPath('saved', false);

    $this->assertDatabaseMissing('activity_saves', [
        'activity_id' => $savedRun->id,
        'user_id' => $viewer->id,
    ]);

    $this->actingAs($viewer)
        ->getJson(route('dashboard.runs.discover', array_merge($params, [
            'saved_only' => true,
        ])))
        ->assertOk()
        ->assertJsonPath('ids', [])
        ->assertJsonPath('items', []);
});

it('paginates discovery results using the requested grid size and defaults to ten', function () {
    $viewer = User::factory()->create();

    $activityType = createRunDiscoveryActivityType([
        'slug' => 'extreme-trials',
        'draft_name' => ['en' => 'Extreme Trials'],
    ]);

    $group = Group::factory()
        ->open()
        ->create([
            'datacenter' => 'Light',
            'group_type' => Group::TYPE_COMMUNITY,
            'preferred_languages' => ['en'],
        ]);

    $runIds = [];
    foreach (range(1, 20) as $index) {
        $runIds[] = Activity::factory()->create([
            'group_id' => $group->id,
            'activity_type_id' => $activityType->id,
            'activity_type_version_id' => $activityType->current_published_version_id,
            'status' => Activity::STATUS_SCHEDULED,
            'title' => 'Run '.$index,
            'starts_at' => now()->addWeek()->startOfWeek()->addDays(2)->setTime(18, 0)->addMinutes($index),
            'datacenter' => 'Light',
            'is_public' => true,
        ])->id;
    }

    $params = [
        'timezone' => 'Europe/London',
        'date_range' => 'next_week',
        'group_type' => Group::TYPE_COMMUNITY,
        'page' => 1,
    ];

    $this->actingAs($viewer)
        ->getJson(route('dashboard.runs.discover', $params))
        ->assertOk()
        ->assertJsonCount(10, 'ids')
        ->assertJsonCount(10, 'items')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('meta.per_page', 10)
        ->assertJsonPath('meta.total', 20);

    $this->getJson(route('dashboard.runs.discover-count', $params))
        ->assertOk()->assertExactJson(['total' => 20]);

    $this->getJson(route('dashboard.runs.discover', [...$params, 'per_page' => 20]))
        ->assertOk()
        ->assertJsonPath('ids', $runIds)
        ->assertJsonPath('meta.last_page', 1)
        ->assertJsonPath('meta.per_page', 20);

    $this->getJson(route('dashboard.runs.discover', [...$params, 'page' => 2, 'per_page' => 9]))
        ->assertOk()
        ->assertJsonPath('ids', array_slice($runIds, 9, 9))
        ->assertJsonCount(9, 'cards')
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.last_page', 3)
        ->assertJsonPath('meta.per_page', 9);

    $this->getJson(route('dashboard.runs.discover', [...$params, 'page' => 3, 'per_page' => 12]))
        ->assertOk()
        ->assertJsonPath('ids', array_slice($runIds, 12))
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('meta.per_page', 12);

    $this->getJson(route('dashboard.runs.discover', [...$params, 'per_page' => 500]))
        ->assertUnprocessable()->assertJsonValidationErrors('per_page');
});

it('builds browse rows from accessible future runs with real capacity and participation', function () {
    $viewer = User::factory()->create();
    $type = createRunDiscoveryActivityType(['slug' => 'forked-tower']);
    $magic = createRunDiscoveryActivityType(['slug' => 'forked-tower-magic']);
    $other = createRunDiscoveryActivityType(['slug' => 'other-content']);
    $group = Group::factory()->open()->create();
    $createRun = function (ActivityType $activityType, array $attributes = []) use ($group): Activity {
        $run = Activity::factory()->create(array_merge([
            'group_id' => $group->id,
            'activity_type_id' => $activityType->id,
            'activity_type_version_id' => $activityType->current_published_version_id,
            'status' => Activity::STATUS_SCHEDULED,
            'starts_at' => now()->addDays(2),
            'created_at' => now()->subDay(),
            'is_public' => true,
        ], $attributes));
        $run->slots()->delete();
        foreach (range(1, 4) as $index) {
            ActivitySlot::factory()->create(['activity_id' => $run->id, 'slot_key' => 'slot-'.$index]);
        }

        return $run;
    };

    $almostFull = $createRun($type);
    foreach ($almostFull->slots()->limit(3)->get() as $slot) {
        $slot->update(['assigned_character_id' => Character::factory()->create()->id]);
    }
    // Empty bench places must not dilute main roster occupancy.
    ActivitySlot::factory()->create(['activity_id' => $almostFull->id, 'slot_key' => 'bench',
        'slot_kind' => ActivitySlot::SLOT_KIND_BENCH]);
    $magicRun = $createRun($magic);
    $newest = $createRun($other, ['created_at' => now(), 'starts_at' => now()->addDay()]);
    ActivityApplication::factory()->create(['activity_id' => $almostFull->id, 'user_id' => $viewer->id,
        'status' => ActivityApplication::STATUS_PENDING]);
    $privateApplicant = User::factory()->create(['public_profile' => false, 'public_characters' => false]);
    ActivityApplication::factory()->create(['activity_id' => $almostFull->id, 'user_id' => $privateApplicant->id,
        'status' => ActivityApplication::STATUS_PENDING, 'applicant_avatar_url' => '/private-avatar.png']);
    // The factory adds membership; public-run applicants do not need it in the product.
    $group->memberships()->where('user_id', $viewer->id)->delete();

    $hiddenGroup = Group::factory()->hidden()->create();
    $memberRun = $createRun($other, ['group_id' => $hiddenGroup->id, 'is_public' => false]);
    $hiddenGroup->memberships()->create(['user_id' => $viewer->id, 'role' => 'member', 'joined_at' => now()]);
    $bannedGroup = Group::factory()->open()->create();
    $bannedGroup->bans()->create(['user_id' => $viewer->id, 'banned_by_user_id' => $bannedGroup->owner_id]);
    $excluded = collect([
        $createRun($type, ['starts_at' => now()->subHour()]),
        $createRun($type, ['status' => Activity::STATUS_DRAFT]),
        $createRun($type, ['status' => Activity::STATUS_CANCELLED]),
        $createRun($type, ['status' => Activity::STATUS_COMPLETE]),
        $createRun($type, ['is_public' => false]),
        $createRun($type, ['group_id' => $bannedGroup->id]),
    ]);
    $full = $createRun($type);
    foreach ($full->slots as $slot) {
        $slot->update(['assigned_character_id' => Character::factory()->create()->id]);
    }
    $excluded->push($full);

    $historical = createRunDiscoveryActivityType(['slug' => 'delubrum-reginae-savage']);
    $played = ['status' => Activity::STATUS_COMPLETE, 'starts_at' => now()->subDay()];
    $createRun($historical, $played);
    $this->artisan('runs:refresh-activity-popularity')->assertSuccessful();
    $createRun($magic, $played); // This run will count after the next daily refresh.
    createRunDiscoveryActivityType(['slug' => 'futures-rewritten-ultimate']);
    foreach (range(1, 6) as $index) {
        createRunDiscoveryActivityType(['slug' => 'archive-'.$index]);
    }
    createRunDiscoveryActivityType(['slug' => 'a-retired', 'is_active' => false]);
    ActivityType::factory()->create(['slug' => 'a-draft', 'is_active' => true]);

    $this->actingAs($viewer)->get(route('dashboard.runs.index'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $deferred) => $deferred
        ->has('browseSections', 5)
        ->where('browseSections.0.runs.0.id', $almostFull->id)));

    $rows = collect(app(RunBrowseService::class)->sections($viewer))->keyBy('id');
    $trending = collect(app(RunBrowseService::class)->trendingActivities($viewer));
    expect($trending->pluck('run_count', 'value')->all())->toBe([
        'delubrum-reginae-savage' => 0, 'other-content' => 2, 'forked-tower' => 1, 'forked-tower-magic' => 1,
        'futures-rewritten-ultimate' => 0, 'archive-1' => 0, 'archive-2' => 0, 'archive-3' => 0,
        'archive-4' => 0, 'archive-5' => 0,
    ]);
    $allCards = $rows->pluck('runs')->flatten(1);
    expect($allCards->pluck('id')->intersect($excluded->pluck('id'))->values()->all())->toBe([])
        ->and($allCards->pluck('id'))->toContain($memberRun->id)
        ->and(collect($rows['almost-full']['runs'])->pluck('id')->all())->toBe([$almostFull->id])
        ->and($rows['recently-posted']['runs'][0]['id'])->toBe($newest->id)
        ->and(collect($rows['latest-content']['runs'])->pluck('id')->all())->toBe([$almostFull->id, $magicRun->id])
        ->and(collect($rows['more-forked-tower']['runs'])->pluck('id')->all())->toBe([$almostFull->id])
        ->and($rows['recommended']['runs'][0]['participation_status'])->toBe('pending')
        ->and($rows['recommended']['runs'][0]['applicant_avatars'][1])->toBeNull()
        ->and($rows['recommended']['runs'][0]['href'])->toBe(route('groups.activities.overview', [
            'locale' => app()->getLocale(), 'group' => $group->slug, 'activity' => $almostFull->id,
        ], false));
});

it('searches flexible days and months and expands date ranges in the selected timezone', function () {
    $viewer = User::factory()->create();
    $type = createRunDiscoveryActivityType(['slug' => 'flexible-content']);
    $group = Group::factory()->open()->create();
    $runs = collect(['2026-05-30 00:30:00', '2026-05-31 23:30:00', '2026-06-01 08:30:00'])
        ->map(fn (string $start) => Activity::factory()->create([
            'group_id' => $group->id,
            'activity_type_id' => $type->id,
            'activity_type_version_id' => $type->current_published_version_id,
            'starts_at' => $start,
            'status' => Activity::STATUS_SCHEDULED,
            'is_public' => true,
        ]));
    $filters = ['activity_type' => $type->slug, 'group' => $group->slug, 'timezone' => 'America/Los_Angeles'];
    $this->actingAs($viewer);
    $this->getJson(route('dashboard.runs.discover', [...$filters, 'date_range' => 'flexible',
        'flexible_days' => 'weekend', 'date_months' => ['2026-05']]))
        ->assertOk()->assertJsonPath('ids', [$runs[1]->id])->assertJsonPath('cards.0.id', $runs[1]->id);
    $this->getJson(route('dashboard.runs.discover', [...$filters, 'timezone' => 'Asia/Tokyo',
        'date_range' => 'flexible', 'flexible_days' => 'weekday', 'date_months' => ['2026-06']]))
        ->assertOk()->assertJsonPath('ids', [$runs[1]->id, $runs[2]->id]);
    $this->getJson(route('dashboard.runs.discover', [...$filters, 'date_range' => 'custom_range',
        'date_from' => '2026-05-30', 'date_to' => '2026-05-30', 'date_flex_days' => 0]))
        ->assertOk()->assertJsonPath('ids', []);
    $this->getJson(route('dashboard.runs.discover', [...$filters, 'date_range' => 'custom_range',
        'date_from' => '2026-05-30', 'date_to' => '2026-05-30', 'date_flex_days' => 1]))
        ->assertOk()->assertJsonPath('ids', [$runs[0]->id, $runs[1]->id]);
    $this->getJson(route('dashboard.runs.discover', [...$filters, 'date_range' => 'custom_day',
        'date_from' => '2026-05-01', 'date_flex_days' => 1]))->assertOk()->assertJsonPath('ids', []);
    $this->getJson(route('dashboard.runs.discover', [...$filters, 'date_range' => 'flexible',
        'date_months' => ['2026-13'], 'flexible_days' => 'holiday', 'date_flex_days' => 50]))
        ->assertUnprocessable()->assertJsonValidationErrors(['date_months.0', 'flexible_days', 'date_flex_days']);
});

function createRunDiscoveryActivityType(array $attributes = []): ActivityType
{
    return ActivityType::factory()
        ->withPublishedVersion()
        ->create($attributes)
        ->fresh('currentPublishedVersion');
}

function createRunDiscoveryClass(array $attributes = []): CharacterClass
{
    return CharacterClass::query()->create(array_merge([
        'name' => 'White Mage',
        'shorthand' => 'WHM',
        'icon_url' => null,
        'flaticon_url' => null,
        'role' => 'healer',
    ], $attributes));
}
