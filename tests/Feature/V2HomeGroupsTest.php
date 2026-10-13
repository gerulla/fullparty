<?php

use App\Models\Activity;
use App\Models\ActivityTypeVersion;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use App\Services\Dashboard\V2HomeDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()->instance('env', 'local');
    Http::preventStrayRequests();
    $this->travelTo(now()->startOfSecond());
    $this->user = User::factory()->create();
    $this->group = function (string $role, int $daysAgo): Group {
        $group = Group::factory()->create([
            ...($role === GroupMembership::ROLE_OWNER ? ['owner_id' => $this->user->id] : []),
            'created_at' => now()->subDays(100),
            'updated_at' => now()->subDays($daysAgo),
        ]);
        if ($role === GroupMembership::ROLE_OWNER) {
            $group->memberships()->delete();
        } else {
            $group->memberships()->create(['user_id' => $this->user->id, 'role' => $role]);
        }

        return $group;
    };
});

it('prioritizes all managed groups over member groups and sorts that tier by recent activity', function () {
    $owner = ($this->group)('owner', 60);
    $admin = ($this->group)('admin', 5);
    $moderator = ($this->group)('moderator', 10);
    ($this->group)('member', 0);
    ($this->group)('member', 1);

    $groups = app(V2HomeDataService::class)->groups($this->user);
    expect(array_column($groups, 'id'))->toBe([$admin->id, $moderator->id, $owner->id])
        ->and(array_column($groups, 'rank'))->toBe(['admin', 'mod', 'owner']);
});

it('fills remaining places with recently active member groups using run updates and completions', function () {
    $managed = ($this->group)('moderator', 80);
    $newRun = ($this->group)('member', 70);
    $completedRun = ($this->group)('member', 70);
    ($this->group)('member', 30);
    $version = ActivityTypeVersion::factory()->create(['layout_schema' => [], 'progress_schema' => [], 'prog_points' => []]);
    Activity::factory()->create([
        'group_id' => $newRun->id, 'activity_type_version_id' => $version->id,
        'status' => Activity::STATUS_SCHEDULED, 'starts_at' => now()->addMonths(2),
        'created_at' => now()->subDays(3), 'updated_at' => now()->subHours(2),
    ]);
    Activity::factory()->create([
        'group_id' => $completedRun->id, 'activity_type_version_id' => $version->id,
        'status' => Activity::STATUS_COMPLETE, 'starts_at' => now()->subDay(),
        'created_at' => now()->subDays(4), 'updated_at' => now()->subDays(2), 'completed_at' => now()->subHours(5),
    ]);

    $groups = app(V2HomeDataService::class)->groups($this->user);
    expect(array_column($groups, 'id'))->toBe([$managed->id, $newRun->id, $completedRun->id])
        ->and($groups[1]['last_activity_at'])->toBe(now()->subHours(2)->toIso8601String())
        ->and($groups[2]['last_activity_at'])->toBe(now()->subHours(5)->toIso8601String());
});

it('does not rank a member group higher because of drafts or future timestamps', function () {
    $older = ($this->group)('member', 30);
    $newer = ($this->group)('member', 10);
    $version = ActivityTypeVersion::factory()->create(['layout_schema' => [], 'progress_schema' => [], 'prog_points' => []]);
    foreach ([
        ['status' => Activity::STATUS_DRAFT, 'updated_at' => now()],
        ['status' => Activity::STATUS_SCHEDULED, 'updated_at' => now()->addDay()],
        ['status' => Activity::STATUS_COMPLETE, 'updated_at' => now()->addDay(), 'completed_at' => now()->addDay()],
    ] as $attributes) {
        Activity::factory()->create([
            'group_id' => $older->id, 'activity_type_version_id' => $version->id,
            'starts_at' => now()->addDays(10), 'created_at' => now()->subDays(40), ...$attributes,
        ]);
    }

    $groups = app(V2HomeDataService::class)->groups($this->user);
    expect(array_column($groups, 'id'))->toBe([$newer->id, $older->id])
        ->and($groups[1]['last_activity_at'])->toBe(now()->subDays(30)->toIso8601String());
});

it('returns localized group routes with settings available only to group moderators and above', function (string $role, string $rank, bool $canManage) {
    $group = ($this->group)($role, 1);
    app()->setLocale('de');
    $data = app(V2HomeDataService::class)->groups($this->user)[0];
    $parameters = ['locale' => 'de', 'group' => $group->slug];

    expect($data['rank'])->toBe($rank)
        ->and($data['can_manage'])->toBe($canManage)
        ->and($data['href'])->toBe(route('groups.dashboard', $parameters, false))
        ->and($data['runs_href'])->toBe(route('groups.dashboard.activities.index', $parameters, false))
        ->and($data['settings_href'])->toBe($canManage ? route('groups.dashboard.settings', $parameters, false) : null);

    $response = $this->actingAs($this->user)->get(route('groups.dashboard.settings', ['locale' => 'en', 'group' => $group->slug]));
    $canManage ? $response->assertOk() : $response->assertForbidden();
})->with([
    'owner without membership' => ['owner', 'owner', true],
    'admin' => ['admin', 'admin', true],
    'moderator' => ['moderator', 'mod', true],
    'member' => ['member', 'member', false],
]);

it('excludes unrelated and banned groups even if they are newer than the users groups', function () {
    $mine = ($this->group)('member', 60);
    Group::factory()->create(['updated_at' => now()]);
    $banned = ($this->group)('admin', 0);
    $banned->bans()->create(['user_id' => $this->user->id, 'banned_by_user_id' => $banned->owner_id]);

    expect(array_column(app(V2HomeDataService::class)->groups($this->user), 'id'))->toBe([$mine->id]);
});
