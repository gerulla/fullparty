<?php

use App\Models\ActivityType;
use App\Models\RaidPlan;
use App\Models\RaidPlanAccessLink;
use App\Models\User;
use App\Services\Planner\RaidPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->missing('raid_plan.links.edit'));
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
            ->where('raid_plan.links.edit', route('planner.edit', ['token' => $editLink->token])));

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
