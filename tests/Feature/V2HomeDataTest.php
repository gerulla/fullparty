<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use App\Services\Dashboard\V2HomeDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()->instance('env', 'local');
    Http::preventStrayRequests();
    $this->travelTo(now()->startOfSecond());
});

it('defers the home datasets independently and returns empty lists after loading', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard', ['locale' => 'en']));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('V2/Home')
        ->missing('user')
        ->missing('applications')
        ->missing('applicationHistory')
        ->missing('groups')
        ->missing('upcomingRuns')
        ->missing('hostedRuns')
        ->missing('groupRuns')
        ->loadDeferredProps('v2-user', fn (Assert $page) => $page
            ->where('user.id', $user->id)
            ->has('user.characters', 0)
            ->missing('applications')
            ->missing('groups')
            ->missing('upcomingRuns'))
        ->loadDeferredProps('v2-applications', fn (Assert $page) => $page
            ->has('applications', 0)->missing('user')->missing('groups')->missing('upcomingRuns'))
        ->loadDeferredProps('v2-application-history', fn (Assert $page) => $page
            ->has('applicationHistory', 0)->missing('user')->missing('applications')->missing('groups')->missing('upcomingRuns'))
        ->loadDeferredProps('v2-groups', fn (Assert $page) => $page
            ->has('groups', 0)->missing('user')->missing('applications')->missing('upcomingRuns'))
        ->loadDeferredProps('v2-upcoming-runs', fn (Assert $page) => $page
            ->has('upcomingRuns', 0)->missing('user')->missing('applications')->missing('groups')->missing('groupRuns'))
        ->loadDeferredProps('v2-hosted-runs', fn (Assert $page) => $page
            ->has('hostedRuns', 0)->missing('upcomingRuns')->missing('user')->missing('groups'))
        ->loadDeferredProps('v2-group-runs', fn (Assert $page) => $page
            ->has('groupRuns', 0)->missing('user')->missing('applications')->missing('groups')->missing('upcomingRuns')));
});

it('returns only the current users characters including those awaiting verification', function () {
    $user = User::factory()->create();
    $primary = Character::factory()->primary()->create(['user_id' => $user->id, 'name' => 'Primary Character', 'claimed_at' => now()]);
    $unverified = Character::factory()->unverified()->create(['user_id' => $user->id, 'name' => 'Another Character']);
    Character::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps('v2-user', fn (Assert $page) => $page
                ->where('user.id', $user->id)
                ->where('user.name', $user->name)
                ->has('user.characters', 2)
                ->where('user.characters.0.id', $primary->id)
                ->where('user.characters.0.is_primary', true)
                ->where('user.characters.0.claimed_at', $primary->claimed_at->toIso8601String())
                ->where('user.characters.1.id', $unverified->id)
                ->where('user.characters.1.verified_at', null)
                ->where('user.characters.1.claimed_at', null)
                ->missing('user.password')
                ->missing('user.email')
                ->missing('user.characters.1.token')));
});

it('returns only the current users applications for upcoming runs ordered by start time', function () {
    $user = User::factory()->create();
    $group = Group::factory()->create();
    $version = ActivityTypeVersion::factory()->create(['layout_schema' => [], 'prog_points' => []]);
    $run = fn (array $attributes = []) => Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_version_id' => $version->id,
        'status' => Activity::STATUS_SCHEDULED,
        'starts_at' => now()->addDays(2),
        ...$attributes,
    ]);
    $application = fn (Activity $activity, array $attributes = []) => ActivityApplication::factory()->create([
        'user_id' => $user->id,
        'activity_id' => $activity->id,
        ...$attributes,
    ]);

    $later = $application($run(), ['status' => ActivityApplication::STATUS_DECLINED]);
    $sooner = $application($run(['starts_at' => now()->addDay(), 'is_public' => false]));

    foreach ([
        ['starts_at' => now()->subMinute()],
        ['starts_at' => null],
        ['status' => Activity::STATUS_DRAFT],
        ['status' => Activity::STATUS_ONGOING],
        ['status' => Activity::STATUS_CANCELLED],
        ['status' => Activity::STATUS_COMPLETE],
        ['is_completed' => true],
    ] as $attributes) {
        $application($run($attributes));
    }

    ActivityApplication::factory()->create(['activity_id' => $later->activity_id]);
    ActivityApplication::factory()->guest()->create(['activity_id' => $later->activity_id]);

    $this->actingAs($user)->get(route('dashboard', ['locale' => 'en']))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps('v2-applications', fn (Assert $page) => $page
                ->has('applications', 2)
                ->where('applications.0.id', $sooner->id)
                ->where('applications.1.id', $later->id)
                ->where('applications.1.status', ActivityApplication::STATUS_DECLINED)
                ->where('applications.0.run.group.id', $group->id)
                ->where('applications.0.href', route('groups.activities.application', [
                    'locale' => 'en', 'group' => $group->slug, 'activity' => $sooner->activity_id,
                ], false))
                ->missing('applications.0.guest_access_token')
                ->missing('applications.0.run.secret_key')));
});

it('returns distinct priority memberships and includes owners without a membership row', function () {
    $user = User::factory()->create();
    $expected = [];

    foreach ([GroupMembership::ROLE_MEMBER, GroupMembership::ROLE_MODERATOR, GroupMembership::ROLE_ADMIN] as $role) {
        $group = Group::factory()->hidden()->create();
        $group->memberships()->create(['user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);
        $expected[$group->id] = $role === GroupMembership::ROLE_MODERATOR ? 'mod' : $role;
    }

    $owned = Group::factory()->create(['owner_id' => $user->id]);
    $owned->memberships()->delete();
    $expected[$owned->id] = 'owner';
    $alsoOwned = Group::factory()->create(['owner_id' => $user->id]);
    $expected[$alsoOwned->id] = 'owner';
    Group::factory()->create();

    $banned = Group::factory()->create();
    $banned->memberships()->create(['user_id' => $user->id, 'role' => GroupMembership::ROLE_MEMBER]);
    $banned->bans()->create(['user_id' => $user->id, 'banned_by_user_id' => $banned->owner_id]);
    $expected = collect($expected)->reject(fn ($rank) => $rank === 'member')->take(3)->all();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps('v2-groups', fn (Assert $page) => $page
                ->has('groups', 3)
                ->where('groups', fn ($groups) => $groups->pluck('rank', 'id')->sortKeys()->all() === collect($expected)->sortKeys()->all())
                ->missing('groups.0.discord_link_token_hash')));
});

it('shows upcoming applications and manual placements in date order without unrelated or inaccessible runs', function () {
    $user = User::factory()->create();
    $character = Character::factory()->create(['user_id' => $user->id]);
    $group = Group::factory()->withMember($user)->create();
    $version = ActivityTypeVersion::factory()->create(['layout_schema' => [], 'prog_points' => []]);
    $run = fn (array $attributes = []) => Activity::factory()->create([
        'group_id' => $group->id, 'activity_type_version_id' => $version->id,
        'status' => Activity::STATUS_SCHEDULED, 'starts_at' => now()->addDays(2),
        ...$attributes,
    ]);
    $apply = fn (Activity $activity, string $status = 'pending') => ActivityApplication::factory()->create([
        'activity_id' => $activity->id, 'user_id' => $user->id,
        'selected_character_id' => $character->id, 'status' => $status,
    ]);
    $pending = $run(['starts_at' => now()->addHour()]);
    $approved = $run(['is_public' => false]);
    $bench = $run(['starts_at' => now()->addDays(3)]);
    $manual = $run(['starts_at' => now()->addDays(4), 'needs_application' => false]);
    $apply($pending);
    $apply($pending, 'withdrawn');
    $apply($approved, 'approved');
    $apply($bench, 'on_bench');
    ActivitySlot::factory()->create(['activity_id' => $manual->id, 'assigned_character_id' => $character->id]);

    foreach ([
        ['starts_at' => now()->subSecond()], ['starts_at' => null],
        ['status' => Activity::STATUS_DRAFT], ['status' => Activity::STATUS_ONGOING],
        ['status' => Activity::STATUS_CANCELLED], ['status' => Activity::STATUS_COMPLETE],
        ['is_completed' => true],
    ] as $attributes) {
        $apply($run($attributes));
    }
    foreach (['declined', 'cancelled', 'withdrawn'] as $status) {
        $apply($run(), $status);
    }
    ActivityApplication::factory()->create(['activity_id' => $run()->id]);
    ActivityApplication::factory()->guest()->create(['activity_id' => $run()->id]);
    $privateGroup = Group::factory()->create();
    $apply($run(['group_id' => $privateGroup->id, 'is_public' => false]));
    $privateGroup->memberships()->where('user_id', $user->id)->delete();
    $bannedGroup = Group::factory()->create();
    $apply($run(['group_id' => $bannedGroup->id]));
    $bannedGroup->bans()->create(['user_id' => $user->id, 'banned_by_user_id' => $bannedGroup->owner_id]);

    $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('v2-upcoming-runs', fn (Assert $page) => $page
            ->has('upcomingRuns', 4)
            ->where('upcomingRuns', fn ($runs) => $runs->pluck('id')->all() === [$pending->id, $approved->id, $bench->id, $manual->id])
            ->where('upcomingRuns.0.participation_status', 'pending')
            ->where('upcomingRuns.2.participation_status', 'on_bench')
            ->where('upcomingRuns.3.participation_status', 'assigned')
            ->missing('upcomingRuns.0.secret_key')->missing('recommendedRuns')));
});

it('limits upcoming runs to the next ten distinct runs across the users characters', function () {
    $user = User::factory()->create();
    $group = Group::factory()->withMember($user)->create();
    $version = ActivityTypeVersion::factory()->create(['layout_schema' => [], 'prog_points' => []]);
    $ids = [];
    for ($day = 1; $day <= 12; $day++) {
        $run = Activity::factory()->create([
            'group_id' => $group->id, 'activity_type_version_id' => $version->id,
            'status' => Activity::STATUS_SCHEDULED, 'starts_at' => now()->addDays($day),
        ]);
        ActivityApplication::factory()->create(['activity_id' => $run->id, 'user_id' => $user->id]);
        $ids[] = $run->id;
    }
    expect(array_column(app(V2HomeDataService::class)->upcomingRuns($user), 'id'))->toBe(array_slice($ids, 0, 10));
});

it('shows only the users unfinished hosted runs including ongoing runs and unscheduled drafts', function () {
    $user = User::factory()->create();
    $group = Group::factory()->create(['owner_id' => $user->id]);
    $version = ActivityTypeVersion::factory()->create(['layout_schema' => [], 'prog_points' => []]);
    $run = fn (array $attributes = []) => Activity::factory()->create([
        'group_id' => $group->id, 'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $user->id, 'status' => Activity::STATUS_SCHEDULED,
        'starts_at' => now()->addDay(), ...$attributes,
    ]);
    $run(['organized_by_user_id' => User::factory()->create()->id]);
    expect(app(V2HomeDataService::class)->hostedRuns($user))->toBe([]);
    $future = $run(['is_public' => false]);
    $ongoing = $run(['status' => Activity::STATUS_ONGOING, 'starts_at' => now()->subHour()]);
    $draft = $run(['status' => Activity::STATUS_DRAFT, 'starts_at' => null]);
    foreach ([
        ['status' => Activity::STATUS_COMPLETE], ['status' => Activity::STATUS_CANCELLED], ['is_completed' => true],
        ['starts_at' => now()->subDay()], ['status' => Activity::STATUS_DRAFT, 'starts_at' => now()->subDay()],
    ] as $attributes) {
        $run($attributes);
    }
    $banned = Group::factory()->create();
    $run(['group_id' => $banned->id]);
    $banned->bans()->create(['user_id' => $user->id, 'banned_by_user_id' => $banned->owner_id]);

    $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('v2-hosted-runs', fn (Assert $page) => $page
            ->has('hostedRuns', 3)
            ->where('hostedRuns', fn ($runs) => $runs->pluck('id')->all() === [$ongoing->id, $future->id, $draft->id])
            ->where('hostedRuns.2.starts_at', null)
            ->missing('hostedRuns.0.secret_key')));
});
