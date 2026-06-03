<?php

use App\Models\Activity;
use App\Models\ActivityTag;
use App\Models\ActivityType;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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

it('returns members-only runs from visible groups without leaking hidden groups', function () {
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
        ->assertJsonPath('runs.0.title', 'Private Match Run')
        ->assertJsonCount(1, 'groups')
        ->assertJsonPath('groups.0.title', 'Visible Match Group');
});

function createGlobalSearchActivityType(array $attributes = []): ActivityType
{
    return ActivityType::factory()
        ->withPublishedVersion()
        ->create($attributes)
        ->fresh('currentPublishedVersion');
}
