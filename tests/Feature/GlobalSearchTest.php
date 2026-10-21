<?php

use App\Models\Activity;
use App\Models\ActivityTag;
use App\Models\ActivityType;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(now()->startOfSecond());
});

it('returns sectioned global search results with the correct destination urls', function () {
    $viewer = User::factory()->create();

    $activityType = createGlobalSearchActivityType([
        'slug' => 'forked-tower',
        'draft_name' => ['en' => 'Forked Tower'],
        'draft_small_image_url' => 'https://example.com/forked-tower.png',
    ]);

    $memberGroup = Group::factory()
        ->withMember($viewer, GroupMembership::ROLE_MEMBER)
        ->create([
            'name' => 'Tower Friends',
            'slug' => 'tower-friends',
            'datacenter' => 'Light',
        ]);

    $publicGroup = Group::factory()->create([
        'name' => 'Tower Seekers',
        'slug' => 'tower-seekers',
        'datacenter' => 'Chaos',
        'is_visible' => true,
    ]);

    $run = Activity::factory()->create([
        'group_id' => $memberGroup->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Weekly Tower Push',
        'is_public' => true,
        'starts_at' => now()->addDay()->setTime(20, 0),
    ]);

    $response = $this
        ->actingAs($viewer)
        ->getJson(route('dashboard.search', ['query' => 'Tower']));

    $response
        ->assertOk()
        ->assertJsonPath('runs.0.id', $run->id)
        ->assertJsonPath('runs.0.title', 'Weekly Tower Push')
        ->assertJsonPath('groups.0.title', 'Tower Friends')
        ->assertJsonPath('groups.1.title', 'Tower Seekers')
        ->assertJsonPath('activities.0.title', 'Forked Tower');

    expect($response->json('runs.0.url'))->toContain("/groups/{$memberGroup->slug}/activities/{$run->id}")
        ->and($response->json('groups.0.url'))->toContain("/groups/{$memberGroup->slug}/dashboard")
        ->and($response->json('groups.1.url'))->toContain('/groups?group=tower-seekers')
        ->and($response->json('activities.0.url'))->toContain('/dashboard/runs?activity_type=forked-tower');
});

it('only matches runs by their actual title', function () {
    $viewer = User::factory()->create();
    $activityType = createGlobalSearchActivityType([
        'slug' => 'skydeep',
        'draft_name' => ['en' => 'Skydeep Cenote'],
    ]);

    $group = Group::factory()->create([
        'is_visible' => true,
    ]);

    Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Late Night Prog',
        'is_public' => true,
    ]);

    $this
        ->actingAs($viewer)
        ->getJson(route('dashboard.search', ['query' => 'Skydeep']))
        ->assertOk()
        ->assertJsonCount(0, 'runs')
        ->assertJsonPath('activities.0.title', 'Skydeep Cenote');
});

it('matches runs and activity types by activity tags', function () {
    $viewer = User::factory()->create();
    $activityType = createGlobalSearchActivityType([
        'slug' => 'aac-light-heavyweight-m4-savage',
        'draft_name' => ['en' => 'AAC Light-heavyweight M4 (Savage)'],
    ]);
    $tag = ActivityTag::query()->create(['name' => 'M4S']);
    $activityType->tags()->attach($tag);

    $group = Group::factory()->create([
        'is_visible' => true,
    ]);

    $run = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Late Night Prog',
        'is_public' => true,
    ]);

    $this
        ->actingAs($viewer)
        ->getJson(route('dashboard.search', ['query' => 'M4S']))
        ->assertOk()
        ->assertJsonPath('runs.0.id', $run->id)
        ->assertJsonPath('runs.0.title', 'Late Night Prog')
        ->assertJsonPath('activities.0.title', 'AAC Light-heavyweight M4 (Savage)');
});

it('uses run audience independently of legacy group visibility', function () {
    $viewer = User::factory()->create();
    $activityType = createGlobalSearchActivityType([
        'slug' => 'hidden-match',
        'draft_name' => ['en' => 'Hidden Match'],
    ]);

    $hiddenGroup = Group::factory()->hidden()->create([
        'name' => 'Hidden Match Group',
        'slug' => 'hidden-match-group',
    ]);

    $visibleGroup = Group::factory()->create([
        'name' => 'Visible Match Group',
        'slug' => 'visible-match-group',
        'is_visible' => true,
    ]);

    Activity::factory()->private()->create([
        'group_id' => $visibleGroup->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Private Match Run',
    ]);

    Activity::factory()->create([
        'group_id' => $hiddenGroup->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Hidden Match Run',
        'is_public' => true,
    ]);

    $response = $this
        ->actingAs($viewer)
        ->getJson(route('dashboard.search', ['query' => 'Match']));

    $response
        ->assertOk()
        ->assertJsonCount(1, 'runs')
        ->assertJsonPath('runs.0.title', 'Hidden Match Run')
        ->assertJsonCount(1, 'groups')
        ->assertJsonPath('groups.0.title', 'Visible Match Group');
});

it('separates upcoming runs from the previous week for both title and tag matches', function (bool $byTag) {
    $viewer = User::factory()->create();
    $activityType = createGlobalSearchActivityType();
    $activityType->tags()->attach(ActivityTag::query()->create(['name' => 'WindowTag']));
    $group = Group::factory()->create();
    $factory = Activity::factory()->state([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Timeline Run',
    ]);
    $later = $factory->create(['starts_at' => now()->addMonth()]);
    $tomorrow = $factory->create(['starts_at' => now()->addDay()]);
    $starting = $factory->create(['starts_at' => now()]);
    $justPast = $factory->create(['starts_at' => now()->subSecond()]);
    $boundary = $factory->create(['starts_at' => now()->subDays(7), 'status' => Activity::STATUS_COMPLETE]);
    $factory->create(['starts_at' => now()->subDays(7)->subSecond()]);
    $factory->create(['starts_at' => now()->subMonth()]);
    $factory->create(['starts_at' => null]);

    $response = $this->actingAs($viewer)
        ->getJson(route('dashboard.search', ['query' => $byTag ? 'WindowTag' : 'Timeline']))
        ->assertOk()
        ->assertJsonCount(3, 'runs')
        ->assertJsonCount(2, 'past_runs');

    expect(array_column($response->json('runs'), 'id'))->toBe([$starting->id, $tomorrow->id, $later->id])
        ->and(array_column($response->json('past_runs'), 'id'))->toBe([$justPast->id, $boundary->id])
        ->and($response->json('past_runs.0.type'))->toBe('run')
        ->and($response->json('past_runs.0.meta'))->toBe($justPast->starts_at->toIso8601String())
        ->and($response->json('past_runs.0.url'))->toContain("/groups/{$group->slug}/activities/{$justPast->id}");
})->with(['title' => false, 'activity tag' => true]);

it('keeps independent result limits so past runs do not displace upcoming runs', function () {
    $viewer = User::factory()->create();
    $activityType = createGlobalSearchActivityType();
    $group = Group::factory()->create();
    $factory = Activity::factory()->state([
        'group_id' => $group->id,
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Limits Run',
    ]);
    $upcoming = [];
    $past = [];
    foreach (range(1, 7) as $hours) {
        $upcoming[] = $factory->create(['starts_at' => now()->addHours($hours)])->id;
        $past[] = $factory->create(['starts_at' => now()->subHours($hours)])->id;
    }

    $response = $this->actingAs($viewer)
        ->getJson(route('dashboard.search', ['query' => 'Limits']))
        ->assertOk()
        ->assertJsonCount(6, 'runs')
        ->assertJsonCount(6, 'past_runs');

    expect(array_column($response->json('runs'), 'id'))->toBe(array_slice($upcoming, 0, 6))
        ->and(array_column($response->json('past_runs'), 'id'))->toBe(array_slice($past, 0, 6));
});

it('preserves members-only draft and ban permissions in both run sections', function (bool $past) {
    $viewer = User::factory()->create();
    $activityType = createGlobalSearchActivityType();
    $factory = Activity::factory()->state([
        'activity_type_id' => $activityType->id,
        'activity_type_version_id' => $activityType->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Access Run',
        'starts_at' => $past ? now()->subDay() : now()->addDay(),
    ]);
    $memberGroup = Group::factory()->hidden()->withMember($viewer)->create();
    $allowed = $factory->create(['group_id' => $memberGroup->id, 'is_public' => false]);
    $factory->create(['group_id' => Group::factory()->hidden()->create()->id, 'is_public' => false]);
    $factory->create(['group_id' => Group::factory()->create()->id, 'status' => Activity::STATUS_DRAFT]);
    $bannedGroup = Group::factory()->withMember($viewer)->create();
    $bannedGroup->bans()->create(['user_id' => $viewer->id, 'banned_by_user_id' => $bannedGroup->owner_id]);
    $factory->create(['group_id' => $bannedGroup->id]);

    $this->actingAs($viewer)
        ->getJson(route('dashboard.search', ['query' => 'Access']))
        ->assertOk()
        ->assertJsonCount(1, $past ? 'past_runs' : 'runs')
        ->assertJsonPath(($past ? 'past_runs' : 'runs').'.0.id', $allowed->id)
        ->assertJsonCount(0, $past ? 'runs' : 'past_runs');
})->with(['upcoming' => false, 'past' => true]);

it('filters inaccessible runs before the search candidate limit', function () {
    $viewer = User::factory()->create();
    $group = Group::factory()->create();
    $type = createGlobalSearchActivityType();
    $factory = Activity::factory()->state([
        'group_id' => $group->id,
        'activity_type_id' => $type->id,
        'activity_type_version_id' => $type->current_published_version_id,
        'status' => Activity::STATUS_SCHEDULED,
        'title' => 'Capacity Match',
        'starts_at' => now()->addDay(),
        'is_public' => false,
    ]);
    $factory->count(31)->create();
    $public = $factory->create(['starts_at' => now()->addDays(2), 'is_public' => true]);

    $this->actingAs($viewer)->getJson(route('dashboard.search', ['query' => 'Capacity']))
        ->assertOk()->assertJsonCount(1, 'runs')->assertJsonPath('runs.0.id', $public->id);
});

it('returns every section empty for a query shorter than two characters', function () {
    $this->actingAs(User::factory()->create())
        ->getJson(route('dashboard.search', ['query' => 'x']))
        ->assertOk()
        ->assertExactJson(['runs' => [], 'past_runs' => [], 'groups' => [], 'activities' => []]);
});

function createGlobalSearchActivityType(array $attributes = []): ActivityType
{
    return ActivityType::factory()
        ->withPublishedVersion()
        ->create($attributes)
        ->fresh('currentPublishedVersion');
}
