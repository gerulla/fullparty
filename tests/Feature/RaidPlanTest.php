<?php

use App\Models\ActivityType;
use App\Models\RaidPlan;
use App\Models\RaidPlanAccessLink;
use App\Models\RaidPlanMechanic;
use App\Models\User;
use App\Services\Planner\RaidPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('creates an anonymous raid plan with separate view and edit links', function () {
    $response = $this->post(route('planner.raid-plans.store'), [
        'name' => 'Fatebreaker Strategy',
        'description' => 'A plan for the opening mechanic.',
        'fight_id' => null,
        'visibility' => RaidPlan::VISIBILITY_UNLISTED,
    ]);

    $raidPlan = RaidPlan::query()->with('accessLinks')->firstOrFail();
    $editLink = $raidPlan->accessLinks
        ->firstWhere('permission', RaidPlanAccessLink::PERMISSION_EDIT);

    expect($raidPlan->author_id)->toBeNull()
        ->and($raidPlan->accessLinks)->toHaveCount(2)
        ->and($editLink)->not->toBeNull();

    $response->assertRedirect(route('planner.edit', ['token' => $editLink->token]));
});

it('saves a raid plan to the authenticated authors account', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('planner.raid-plans.store'), [
            'name' => 'Pandora Strategy',
            'description' => null,
            'fight_id' => null,
            'visibility' => RaidPlan::VISIBILITY_UNLISTED,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('raid_plans', [
        'author_id' => $user->id,
        'name' => 'Pandora Strategy',
    ]);

    expect($user->raidPlans()->count())->toBe(1);
});

it('keeps the edit link out of the view-only payload', function () {
    $raidPlan = app(RaidPlanService::class)->create(null, [
        'name' => 'View-only Strategy',
        'description' => null,
    ]);
    $viewLink = $raidPlan->accessLinks
        ->firstWhere('permission', RaidPlanAccessLink::PERMISSION_VIEW);

    $this->get(route('planner.view', ['token' => $viewLink->token]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->where('mode', 'view')
            ->where('raid_plan.name', 'View-only Strategy')
            ->where('raid_plan.can_edit', false)
            ->missing('raid_plan.links.edit')
            ->missing('raid_plan.links.asset_upload'));
});

it('loads and updates a raid plan through its edit link only', function () {
    $raidPlan = app(RaidPlanService::class)->create(null, [
        'name' => 'Original Strategy',
        'description' => null,
    ]);
    $viewLink = $raidPlan->accessLinks
        ->firstWhere('permission', RaidPlanAccessLink::PERMISSION_VIEW);
    $editLink = $raidPlan->accessLinks
        ->firstWhere('permission', RaidPlanAccessLink::PERMISSION_EDIT);

    $this->get(route('planner.edit', ['token' => $editLink->token]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->where('mode', 'edit')
            ->where('raid_plan.can_edit', true)
            ->where('raid_plan.mechanics', [])
            ->where('raid_plan.links.edit', route('planner.edit', ['token' => $editLink->token]))
            ->where(
                'raid_plan.links.asset_upload',
                route('planner.raid-plans.images.store', ['token' => $editLink->token])
            ));

    $this->patch(route('planner.raid-plans.update', ['token' => $editLink->token]), [
        'name' => 'Updated Strategy',
        'description' => 'Updated metadata.',
        'fight_id' => null,
        'visibility' => RaidPlan::VISIBILITY_PUBLIC,
    ])->assertRedirect(route('planner.edit', ['token' => $editLink->token]));

    $this->patch(route('planner.raid-plans.update', ['token' => $viewLink->token]), [
        'name' => 'Forbidden Update',
        'description' => null,
        'fight_id' => null,
        'visibility' => RaidPlan::VISIBILITY_UNLISTED,
    ])->assertNotFound();

    $this->assertDatabaseHas('raid_plans', [
        'id' => $raidPlan->id,
        'name' => 'Updated Strategy',
        'description' => 'Updated metadata.',
        'visibility' => RaidPlan::VISIBILITY_PUBLIC,
    ]);
});

it('associates a raid plan with a stable FullParty activity type', function () {
    $fight = ActivityType::factory()
        ->withPublishedVersion()
        ->create([
            'draft_name' => [
                'en' => 'Futures Rewritten (Ultimate)',
                'de' => 'Futures Rewritten (Fatal)',
            ],
        ]);

    $this->post(route('planner.raid-plans.store'), [
        'name' => 'FRU Strategy',
        'description' => null,
        'fight_id' => $fight->id,
        'visibility' => RaidPlan::VISIBILITY_PUBLIC,
    ])->assertRedirect();

    $raidPlan = RaidPlan::query()->with('accessLinks')->firstOrFail();
    $editLink = $raidPlan->accessLinks
        ->firstWhere('permission', RaidPlanAccessLink::PERMISSION_EDIT);

    $this->assertDatabaseHas('raid_plans', [
        'id' => $raidPlan->id,
        'activity_type_id' => $fight->id,
        'visibility' => RaidPlan::VISIBILITY_PUBLIC,
    ]);

    $this->get(route('planner.edit', ['token' => $editLink->token]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('raid_plan.fight_id', $fight->id)
            ->where('raid_plan.visibility', RaidPlan::VISIBILITY_PUBLIC)
            ->where('fight_options.0.id', $fight->id)
            ->where('fight_options.0.label', 'Futures Rewritten (Ultimate)'));
});

it('saves and synchronizes the ordered mechanic tree', function () {
    $this->post(route('planner.raid-plans.store'), [
        'name' => 'Mechanic Strategy',
        'description' => null,
        'fight_id' => null,
        'visibility' => RaidPlan::VISIBILITY_UNLISTED,
        'mechanics' => [
            [
                'id' => null,
                'name' => 'Opening',
                'type' => RaidPlanMechanic::TYPE_FIXED,
                'duration_ms' => 12_000,
                'timeline' => [
                    'events' => [['type' => 'cast']],
                    'components' => [[
                        'id' => 'arena-map-1',
                        'type' => 'arena_map',
                        'image_url' => '/storage/planner/raid-plans/1/arena.webp',
                        'display_mode' => 'crop',
                        'offset_x' => 24,
                        'offset_y' => -12,
                        'rotation' => 15,
                        'crop_left' => 5,
                        'crop_right' => 10,
                        'crop_top' => 15,
                        'crop_bottom' => 20,
                    ], [
                        'id' => 'boss-1',
                        'type' => 'boss',
                        'offset_x' => 120,
                        'offset_y' => -80,
                        'rotation' => 45,
                        'scale' => 1.25,
                        'color' => '#38bdf8',
                        'hitbox_style' => 'no_positionals',
                    ], [
                        'id' => 'marker-a-1',
                        'type' => 'marker',
                        'marker_key' => 'A',
                        'offset_x' => -140,
                        'offset_y' => 90,
                        'rotation' => 0,
                        'scale' => 1,
                    ], [
                        'id' => 'marker-layout-1',
                        'type' => 'marker_layout',
                        'layout' => 'waymark_studio',
                        'distance' => 140,
                        'waymark_preset' => '{"A":{"X":100,"Z":88,"Active":true},"C":{"X":100,"Z":112,"Active":true}}',
                        'offset_x' => 0,
                        'offset_y' => 0,
                        'rotation' => 0,
                    ]],
                ],
                'timeline_schema_version' => 1,
                'variants' => [],
            ],
            [
                'id' => null,
                'name' => 'Tether Pattern',
                'type' => RaidPlanMechanic::TYPE_RANDOM_SET,
                'variants' => [
                    [
                        'id' => null,
                        'name' => 'North and South',
                        'duration_ms' => 8_000,
                        'selection_weight' => 2,
                        'timeline' => [],
                        'timeline_schema_version' => 1,
                    ],
                ],
            ],
        ],
    ])->assertRedirect();

    $raidPlan = RaidPlan::query()
        ->with(['accessLinks', 'rootMechanics.children'])
        ->firstOrFail();
    $opening = $raidPlan->rootMechanics[0];
    $randomSet = $raidPlan->rootMechanics[1];
    $editLink = $raidPlan->accessLinks
        ->firstWhere('permission', RaidPlanAccessLink::PERMISSION_EDIT);

    expect($raidPlan->rootMechanics->pluck('name')->all())
        ->toBe(['Opening', 'Tether Pattern'])
        ->and($opening->timeline['components'][0]['type'])->toBe('arena_map')
        ->and($opening->timeline['components'][0]['display_mode'])->toBe('crop')
        ->and($opening->timeline['components'][0]['offset_x'])->toBe(24)
        ->and($opening->timeline['components'][0]['crop_bottom'])->toBe(20)
        ->and($opening->timeline['components'][1]['type'])->toBe('boss')
        ->and($opening->timeline['components'][1]['scale'])->toBe(1.25)
        ->and($opening->timeline['components'][1]['hitbox_style'])->toBe('no_positionals')
        ->and($opening->timeline['components'][2]['type'])->toBe('marker')
        ->and($opening->timeline['components'][2]['marker_key'])->toBe('A')
        ->and($opening->timeline['components'][3]['type'])->toBe('marker_layout')
        ->and($opening->timeline['components'][3]['layout'])->toBe('waymark_studio')
        ->and($opening->timeline['components'][3]['distance'])->toBe(140)
        ->and($opening->timeline['components'][3]['waymark_preset'])->toContain('"A"')
        ->and($randomSet->children)->toHaveCount(1)
        ->and($randomSet->children[0]->selection_weight)->toBe(2);

    $this->get(route('planner.edit', ['token' => $editLink->token]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('raid_plan.mechanics.0.id', $opening->id)
            ->where('raid_plan.mechanics.0.timeline.components.0.display_mode', 'crop')
            ->where('raid_plan.mechanics.0.timeline.components.1.color', '#38bdf8')
            ->where('raid_plan.mechanics.0.timeline.components.2.marker_key', 'A')
            ->where('raid_plan.mechanics.0.timeline.components.3.layout', 'waymark_studio')
            ->where('raid_plan.mechanics.0.timeline.components.3.distance', 140)
            ->where('raid_plan.mechanics.1.variants.0.name', 'North and South'));

    $this->patchJson(
        route('planner.raid-plans.update', ['token' => $editLink->token]),
        [
            'name' => 'Mechanic Strategy',
            'description' => null,
            'fight_id' => null,
            'visibility' => RaidPlan::VISIBILITY_UNLISTED,
            'mechanics' => [
                [
                    'id' => $randomSet->id,
                    'name' => 'Tether Pattern',
                    'type' => RaidPlanMechanic::TYPE_FIXED,
                    'duration_ms' => 5_000,
                    'timeline' => [],
                    'timeline_schema_version' => 1,
                    'variants' => [],
                ],
            ],
        ],
    )
        ->assertOk()
        ->assertJsonPath('data.mechanics.0.id', $randomSet->id)
        ->assertJsonPath('data.mechanics.0.type', RaidPlanMechanic::TYPE_FIXED)
        ->assertJsonCount(0, 'data.mechanics.0.variants');

    $this->assertDatabaseMissing('raid_plan_mechanics', [
        'id' => $opening->id,
    ]);
    $this->assertDatabaseMissing('raid_plan_mechanics', [
        'parent_id' => $randomSet->id,
    ]);
    $this->assertDatabaseHas('raid_plan_mechanics', [
        'id' => $randomSet->id,
        'type' => RaidPlanMechanic::TYPE_FIXED,
        'sort_order' => 0,
    ]);
});

it('uploads a managed arena image through an edit link', function () {
    Storage::fake('public');

    $raidPlan = app(RaidPlanService::class)->create(null, [
        'name' => 'Arena Image Strategy',
        'description' => null,
    ]);
    $editLink = $raidPlan->accessLinks
        ->firstWhere('permission', RaidPlanAccessLink::PERMISSION_EDIT);
    $viewLink = $raidPlan->accessLinks
        ->firstWhere('permission', RaidPlanAccessLink::PERMISSION_VIEW);

    $response = $this->postJson(
        route('planner.raid-plans.images.store', ['token' => $editLink->token]),
        ['image' => UploadedFile::fake()->image('arena.png', 1280, 720)]
    );

    $response
        ->assertCreated()
        ->assertJsonPath('data.url', fn (string $url) => str_ends_with($url, '.webp'));

    $storedUrl = $response->json('data.url');
    $storedPath = str_replace('/storage/', '', parse_url($storedUrl, PHP_URL_PATH));

    Storage::disk('public')->assertExists($storedPath);

    $this->postJson(
        route('planner.raid-plans.images.store', ['token' => $viewLink->token]),
        ['image' => UploadedFile::fake()->image('forbidden.png')]
    )->assertNotFound();
});
