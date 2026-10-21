<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlotAssignment;
use App\Models\Character;
use App\Models\Group;
use App\Models\User;
use App\Services\Dashboard\HomeActivityOverviewDataService;
use App\Services\Dashboard\HomeBannerDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('filters group preview counts and recent run limits for the viewer', function (string $role, int $total, int $recentCount) {
    $viewer = User::factory()->create();
    $group = Group::factory()->open()->create();
    if (in_array($role, ['member', 'moderator'], true)) {
        $group->memberships()->create(['user_id' => $viewer->id, 'role' => $role, 'joined_at' => now()]);
    }
    if ($role === 'banned') {
        $group->bans()->create(['user_id' => $viewer->id, 'banned_by_user_id' => $group->owner_id]);
    }
    $public = Activity::factory()->for($group)->complete()->create(['starts_at' => now()->subDays(3)]);
    Activity::factory()->count(6)->for($group)->complete()->private()->create([
        'starts_at' => now()->subDay(),
        'activity_type_version_id' => $public->activity_type_version_id,
    ]);
    Activity::factory()->for($group)->create([
        'status' => Activity::STATUS_DRAFT,
        'activity_type_version_id' => $public->activity_type_version_id,
    ]);

    $response = $this->actingAs($viewer)->getJson(route('groups.details', $group))
        ->assertOk()
        ->assertJsonPath('data.activity_summary.total_runs', $total)
        ->assertJsonPath('data.content_summary.total_runs', $total)
        ->assertJsonCount($recentCount, 'data.recent_runs');

    if ($role === 'outsider') {
        $response->assertJsonPath('data.recent_runs.0.id', $public->id)
            ->assertJsonPath('data.activity_summary.completed_runs', 1)
            ->assertJsonPath('data.content_items.0.total_runs', 1);
    }
})->with([
    'outsider' => ['outsider', 1, 1],
    'member' => ['member', 7, 6],
    'moderator' => ['moderator', 8, 6],
    'banned' => ['banned', 0, 0],
]);

it('keeps selected groups public runs in my runs after the group becomes invite only', function () {
    $viewer = User::factory()->create();
    $group = Group::factory()->inviteOnly()->create();
    $viewer->runListGroups()->attach($group);
    $public = Activity::factory()->for($group)->create(['status' => Activity::STATUS_SCHEDULED]);
    Activity::factory()->for($group)->private()->create(['status' => Activity::STATUS_SCHEDULED]);
    Activity::factory()->for($group)->create(['status' => Activity::STATUS_DRAFT]);

    $this->actingAs($viewer)->get(route('account.runs.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('activities', 1)
            ->where('activities.0.id', $public->id)->has('groups', 1));

    $group->bans()->create(['user_id' => $viewer->id, 'banned_by_user_id' => $group->owner_id]);
    $this->get(route('account.runs.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('activities', 0)->has('groups', 0));
});

it('lets users remove their own saved runs after losing access without exposing or changing other bookmarks', function (string $reason) {
    $viewer = User::factory()->create();
    $other = User::factory()->create();
    $run = Activity::factory()->create(['status' => Activity::STATUS_SCHEDULED]);
    $viewer->savedActivities()->attach($run);
    $other->savedActivities()->attach($run);
    match ($reason) {
        'private' => $run->update(['is_public' => false]),
        'archived' => $run->update(['status' => Activity::STATUS_COMPLETE]),
        'banned' => $run->group->bans()->create(['user_id' => $viewer->id, 'banned_by_user_id' => $run->group->owner_id]),
    };

    $this->actingAs($viewer)->deleteJson(route('dashboard.runs.unsave', $run))->assertOk()->assertExactJson(['saved' => false]);
    expect($viewer->savedActivities()->whereKey($run->id)->exists())->toBeFalse()
        ->and($other->savedActivities()->whereKey($run->id)->exists())->toBeTrue();
    $this->deleteJson(route('dashboard.runs.unsave', $run))->assertNotFound();
    $this->postJson(route('dashboard.runs.save', $run))->assertNotFound();
    $this->get(route('groups.activities.overview', [$run->group, $run]))
        ->assertStatus($reason === 'archived' ? 200 : 404);
})->with(['private', 'archived', 'banned']);

it('shares participant actions between run overview and application responses', function (bool $signedIn, bool $guests, bool $closed) {
    $run = Activity::factory()->create([
        'status' => $closed ? Activity::STATUS_ONGOING : Activity::STATUS_SCHEDULED,
        'is_public' => true,
        'needs_application' => true,
        'allow_guest_applications' => $guests,
    ]);
    if ($signedIn) {
        $this->actingAs(User::factory()->create());
    }
    $expected = [
        'can_apply' => $signedIn && ! $closed,
        'can_apply_as_guest' => ! $signedIn && $guests && ! $closed,
        'can_self_assign' => false,
        'requires_login' => ! $signedIn && ! $guests && ! $closed,
    ];

    foreach (['groups.activities.overview', 'groups.activities.application'] as $route) {
        $response = $this->get(route($route, [$run->group, $run]))->assertOk()
            ->assertInertia(function (Assert $page) use ($expected) {
                foreach ($expected as $key => $value) {
                    $page->where('permissions.'.$key, $value);
                }
                $page->missing('permissions.requires_group_membership')
                    ->missing('permissions.can_join_group')->missing('permissions.can_request_group_membership');
            });
        if ($expected['requires_login']) {
            $response->assertSessionHas('url.intended', route('groups.activities.application', [$run->group, $run]));
        }
    }
})->with([false, true])->with([false, true])->with([false, true]);

it('offers sign in on slot selection runs until the guest slot workflow is available', function (bool $guests) {
    $run = Activity::factory()->create([
        'status' => Activity::STATUS_SCHEDULED, 'needs_application' => false, 'allow_guest_applications' => $guests,
    ]);
    $url = route('groups.activities.overview', [$run->group, $run]);
    $this->get($url)->assertOk()->assertSessionHas('url.intended', $url)
        ->assertInertia(fn (Assert $page) => $page->where('permissions.requires_login', true)
            ->where('permissions.can_self_assign', false));
    $this->actingAs(User::factory()->create())->get($url)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('permissions.requires_login', false)
            ->where('permissions.can_self_assign', true));
})->with([false, true]);

it('excludes inaccessible runs from finder pagination and saved result totals', function () {
    $viewer = User::factory()->create();
    $group = Group::factory()->inviteOnly()->create();
    $public = Activity::factory()->count(11)->for($group)->create([
        'status' => Activity::STATUS_SCHEDULED, 'starts_at' => now()->addDay(),
    ]);
    $private = Activity::factory()->count(12)->for($group)->private()->create([
        'status' => Activity::STATUS_SCHEDULED, 'starts_at' => now()->addHour(),
        'activity_type_version_id' => $public->first()->activity_type_version_id,
    ]);
    $viewer->savedActivities()->attach($public->pluck('id')->merge($private->pluck('id')));
    foreach ([false, true] as $savedOnly) {
        $params = ['timezone' => 'UTC', 'saved_only' => $savedOnly];
        $first = $this->actingAs($viewer)->getJson(route('dashboard.runs.discover', $params))
            ->assertOk()->assertJsonCount(10, 'items')->assertJsonPath('meta.total', 11)->json('ids');
        $second = $this->getJson(route('dashboard.runs.discover', [...$params, 'page' => 2]))
            ->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('meta.total', 11)->json('ids');
        expect([...$first, ...$second])->toEqualCanonicalizing($public->pluck('id')->all());
    }
});

it('keeps personal history but removes inaccessible links and skips hidden upcoming reminders before limiting', function (string $participation) {
    $viewer = User::factory()->create();
    $character = Character::factory()->for($viewer)->create();
    $private = Activity::factory()->private()->create(['status' => Activity::STATUS_SCHEDULED, 'starts_at' => now()->addHour()]);
    $public = Activity::factory()->create(['status' => Activity::STATUS_SCHEDULED, 'starts_at' => now()->addHours(2)]);
    foreach ([$private, $public] as $run) {
        if ($participation === 'application') {
            ActivityApplication::factory()->for($viewer)->for($run)->create([
                'selected_character_id' => $character->id, 'status' => ActivityApplication::STATUS_APPROVED,
            ]);
        } else {
            $slot = $run->slots()->firstOrFail();
            $slot->update(['assigned_character_id' => $character->id]);
            if ($participation === 'assignment') {
                ActivitySlotAssignment::query()->create([
                    'activity_id' => $run->id, 'activity_slot_id' => $slot->id, 'group_id' => $run->group_id,
                    'character_id' => $character->id, 'ended_at' => null, 'assigned_at' => now(),
                    'attendance_status' => ActivitySlotAssignment::STATUS_ASSIGNED,
                ]);
            }
        }
    }
    // The application factory adds membership; model a participant who has since left.
    $private->group->memberships()->where('user_id', $viewer->id)->delete();
    $this->actingAs($viewer);
    expect(app(HomeBannerDataService::class)->detailsForUser($viewer)['next_run']['activity_id'])->toBe($public->id);
    $overview = app(HomeActivityOverviewDataService::class)->forUser($viewer);
    expect(collect($overview['runs'])->firstWhere('activity_id', $private->id)['href'])->toBeNull()
        ->and(collect($overview['runs'])->firstWhere('activity_id', $public->id)['href'])->not->toBeNull();
    if ($participation === 'application') {
        expect(collect($overview['applications'])->firstWhere('activity.id', $private->id)['href'])->toBeNull();
    }
})->with(['application', 'assignment', 'slot']);
