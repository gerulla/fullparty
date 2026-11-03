<?php

use App\Models\Group;
use App\Models\User;
use App\Services\Groups\GroupLeaderboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the leaderboard shell without calculating or populating its cache', function () {
    $group = Group::factory()->open()->create();
    $this->mock(GroupLeaderboardService::class)->shouldNotReceive('calculate');

    $response = $this->actingAs($group->owner)
        ->get(route('groups.dashboard.leaderboard', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Groups/Leaderboard')
            ->where('group.slug', $group->slug)
            ->missing('leaderboard')
            ->missing('leaderboard_cache'));

    expect($response->viewData('page')['deferredProps']['group-leaderboard'])
        ->toBe(['leaderboard', 'leaderboard_cache']);
    expect(Cache::has("groups:{$group->id}:leaderboard:v5"))->toBeFalse();
});

it('calculates once for deferred leaderboard and cache metadata and reuses the result', function () {
    $group = Group::factory()->open()->create();
    $payload = app(GroupLeaderboardService::class)->calculate($group);
    $this->mock(GroupLeaderboardService::class)->shouldReceive('calculate')->once()->andReturn($payload);

    foreach (range(1, 2) as $visit) {
        $this->actingAs($group->owner)->get(route('groups.dashboard.leaderboard', $group))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps('group-leaderboard', fn (Assert $page) => $page
                    ->where('leaderboard.summary.total_participations', 0)
                    ->where('leaderboard_cache.can_refresh', true)
                    ->has('leaderboard_cache.cached_at')));
    }
});

it('reports a calculation failure and allows the deferred data to be retried', function () {
    Exceptions::fake();
    $group = Group::factory()->open()->create();
    $payload = app(GroupLeaderboardService::class)->calculate($group);
    $service = $this->mock(GroupLeaderboardService::class);
    $service->shouldReceive('calculate')->once()->andThrow(new RuntimeException('Leaderboard unavailable'));
    $service->shouldReceive('calculate')->once()->andReturn($payload);

    $this->actingAs($group->owner)->get(route('groups.dashboard.leaderboard', $group))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps('group-leaderboard', fn (Assert $page) => $page
                ->where('leaderboard', null)
                ->where('leaderboard_cache', null)));

    Exceptions::assertReported(RuntimeException::class);
    expect(Cache::has("groups:{$group->id}:leaderboard:v5"))->toBeFalse();

    $this->get(route('groups.dashboard.leaderboard', $group))
        ->assertInertia(fn (Assert $page) => $page
            ->reloadOnly(['leaderboard', 'leaderboard_cache'], fn (Assert $page) => $page
                ->where('leaderboard.summary.total_participations', 0)
                ->where('leaderboard_cache.can_refresh', true)));
});

it('checks membership again when deferred leaderboard data is requested', function () {
    $group = Group::factory()->create(['is_visible' => false]);
    $member = User::factory()->create();
    $group->memberships()->create(['user_id' => $member->id, 'role' => 'member', 'joined_at' => now()]);
    $this->mock(GroupLeaderboardService::class)->shouldNotReceive('calculate');

    $response = $this->actingAs($member)->get(route('groups.dashboard.leaderboard', $group))->assertOk();
    $group->memberships()->where('user_id', $member->id)->delete();

    $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $response->viewData('page')['version'],
        'X-Inertia-Partial-Component' => 'Dashboard/Groups/Leaderboard',
        'X-Inertia-Partial-Data' => 'leaderboard,leaderboard_cache',
    ])->get(route('groups.dashboard.leaderboard', $group))->assertNotFound();
});

it('checks the feature flag again when deferred leaderboard data is requested', function () {
    $group = Group::factory()->create();
    $this->mock(GroupLeaderboardService::class)->shouldNotReceive('calculate');
    $response = $this->actingAs($group->owner)->get(route('groups.dashboard.leaderboard', $group))->assertOk();
    $group->features()->update(['leaderboard_enabled' => false]);

    $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $response->viewData('page')['version'],
        'X-Inertia-Partial-Component' => 'Dashboard/Groups/Leaderboard',
        'X-Inertia-Partial-Data' => 'leaderboard,leaderboard_cache',
    ])->get(route('groups.dashboard.leaderboard', $group))->assertNotFound();
});
