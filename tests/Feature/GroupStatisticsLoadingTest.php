<?php

use App\Models\Group;
use App\Models\User;
use App\Services\Groups\GroupStatisticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the statistics shell without calculating or populating its cache', function () {
    $group = Group::factory()->open()->create();
    $this->mock(GroupStatisticsService::class)->shouldNotReceive('calculate');

    $response = $this->actingAs($group->owner)
        ->get(route('groups.dashboard.statistics', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Groups/Statistics')
            ->where('group.slug', $group->slug)
            ->missing('statistics')
            ->missing('statistics_cache'));

    expect($response->viewData('page')['deferredProps']['group-statistics'])
        ->toBe(['statistics', 'statistics_cache']);
    expect(Cache::has("groups:{$group->id}:statistics:v3"))->toBeFalse();
});

it('calculates once for deferred statistics and cache metadata and reuses the result', function () {
    $group = Group::factory()->open()->create();
    $payload = app(GroupStatisticsService::class)->calculate($group);
    $this->mock(GroupStatisticsService::class)->shouldReceive('calculate')->once()->andReturn($payload);

    foreach (range(1, 2) as $visit) {
        $this->actingAs($group->owner)->get(route('groups.dashboard.statistics', $group))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps('group-statistics', fn (Assert $page) => $page
                    ->where('statistics.summary.total_runs', 0)
                    ->where('statistics_cache.can_refresh', true)
                    ->has('statistics_cache.cached_at')));
    }
});

it('reports a calculation failure and allows the deferred data to be retried', function () {
    Exceptions::fake();
    $group = Group::factory()->open()->create();
    $payload = app(GroupStatisticsService::class)->calculate($group);
    $service = $this->mock(GroupStatisticsService::class);
    $service->shouldReceive('calculate')->once()->andThrow(new RuntimeException('Statistics unavailable'));
    $service->shouldReceive('calculate')->once()->andReturn($payload);

    $this->actingAs($group->owner)->get(route('groups.dashboard.statistics', $group))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps('group-statistics', fn (Assert $page) => $page
                ->where('statistics', null)
                ->where('statistics_cache', null)));

    Exceptions::assertReported(RuntimeException::class);
    expect(Cache::has("groups:{$group->id}:statistics:v3"))->toBeFalse();

    $this->get(route('groups.dashboard.statistics', $group))
        ->assertInertia(fn (Assert $page) => $page
            ->reloadOnly(['statistics', 'statistics_cache'], fn (Assert $page) => $page
                ->where('statistics.summary.total_runs', 0)
                ->where('statistics_cache.can_refresh', true)));
});

it('checks membership again when deferred statistics are requested', function () {
    $group = Group::factory()->create(['is_visible' => false]);
    $member = User::factory()->create();
    $group->memberships()->create(['user_id' => $member->id, 'role' => 'member', 'joined_at' => now()]);
    $this->mock(GroupStatisticsService::class)->shouldNotReceive('calculate');

    $response = $this->actingAs($member)->get(route('groups.dashboard.statistics', $group))->assertOk();
    $group->memberships()->where('user_id', $member->id)->delete();

    $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $response->viewData('page')['version'],
        'X-Inertia-Partial-Component' => 'Dashboard/Groups/Statistics',
        'X-Inertia-Partial-Data' => 'statistics,statistics_cache',
    ])->get(route('groups.dashboard.statistics', $group))->assertNotFound();
});

it('checks the feature flag again when deferred statistics are requested', function () {
    $group = Group::factory()->create();
    $this->mock(GroupStatisticsService::class)->shouldNotReceive('calculate');
    $response = $this->actingAs($group->owner)->get(route('groups.dashboard.statistics', $group))->assertOk();
    $group->features()->update(['statistics_enabled' => false]);

    $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $response->viewData('page')['version'],
        'X-Inertia-Partial-Component' => 'Dashboard/Groups/Statistics',
        'X-Inertia-Partial-Data' => 'statistics,statistics_cache',
    ])->get(route('groups.dashboard.statistics', $group))->assertNotFound();
});
