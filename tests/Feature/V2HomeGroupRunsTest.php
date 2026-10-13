<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivityTypeVersion;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use App\Services\Dashboard\V2HomeDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(now()->startOfSecond());
    $this->user = User::factory()->create();
    $this->group = Group::factory()->withMember($this->user)->create();
    $this->version = ActivityTypeVersion::factory()->create([
        'layout_schema' => [], 'progress_schema' => [], 'prog_points' => [],
    ]);
    $this->run = fn (array $attributes = []) => Activity::factory()->create([
        'group_id' => $this->group->id,
        'activity_type_version_id' => $this->version->id,
        'status' => Activity::STATUS_SCHEDULED,
        'starts_at' => now()->addDay(),
        ...$attributes,
    ]);
});

it('includes only upcoming unfinished published runs within the next seven days', function () {
    $last = ($this->run)(['starts_at' => now()->addDays(7), 'status' => Activity::STATUS_ASSIGNED]);
    $first = ($this->run)(['starts_at' => now(), 'status' => Activity::STATUS_UPCOMING]);
    $middle = ($this->run)(['is_public' => false]);

    foreach ([
        ['starts_at' => now()->subSecond()],
        ['starts_at' => now()->addDays(7)->addSecond()],
        ['starts_at' => null],
        ['status' => Activity::STATUS_DRAFT],
        ['status' => Activity::STATUS_ONGOING],
        ['status' => Activity::STATUS_CANCELLED],
        ['status' => Activity::STATUS_COMPLETE],
        ['is_completed' => true],
    ] as $attributes) {
        ($this->run)($attributes);
    }

    $rows = app(V2HomeDataService::class)->groupRuns($this->user);

    expect($rows)->toHaveCount(1)
        ->and(array_column($rows[0]['runs'], 'id'))->toBe([$first->id, $middle->id, $last->id])
        ->and($rows[0]['runs'][1]['is_public'])->toBeFalse();
});

it('includes every membership rank and owners without membership rows including hidden groups', function () {
    $expected = [];
    foreach ([GroupMembership::ROLE_MEMBER, GroupMembership::ROLE_MODERATOR, GroupMembership::ROLE_ADMIN, GroupMembership::ROLE_OWNER] as $index => $role) {
        $group = $role === GroupMembership::ROLE_OWNER
            ? Group::factory()->hidden()->create(['owner_id' => $this->user->id])
            : Group::factory()->hidden()->withMember($this->user, $role)->create();
        ($this->run)(['group_id' => $group->id, 'starts_at' => now()->addHours($index + 1), 'is_public' => false]);
        if ($role === GroupMembership::ROLE_OWNER) {
            $group->memberships()->delete();
        }
        $expected[] = $group->id;
    }

    $rows = app(V2HomeDataService::class)->groupRuns($this->user);

    expect(array_column(array_column($rows, 'group'), 'id'))->toBe($expected)
        ->and($rows)->toHaveCount(4);
});

it('excludes unrelated groups and bans even when the user still owns or belongs to the group', function () {
    $allowed = ($this->run)();
    $outsider = Group::factory()->create();
    $privateOutsider = Group::factory()->hidden()->create();
    $bannedMember = Group::factory()->withMember($this->user)->create();
    $bannedOwner = Group::factory()->create(['owner_id' => $this->user->id]);
    foreach ([$bannedMember, $bannedOwner] as $group) {
        $group->bans()->create(['user_id' => $this->user->id, 'banned_by_user_id' => $outsider->owner_id]);
    }
    foreach ([$outsider, $privateOutsider, $bannedMember, $bannedOwner] as $group) {
        ($this->run)(['group_id' => $group->id]);
    }

    $rows = app(V2HomeDataService::class)->groupRuns($this->user);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['group']['id'])->toBe($this->group->id)
        ->and(array_column($rows[0]['runs'], 'id'))->toBe([$allowed->id]);
});

it('lists all matching runs without the recommendations cap and orders rows by their next run', function () {
    $laterGroup = Group::factory()->withMember($this->user)->create();
    $later = ($this->run)(['group_id' => $laterGroup->id, 'starts_at' => now()->addDays(3)]);
    $expected = [];
    foreach (range(12, 1) as $hour) {
        $expected[] = ($this->run)(['starts_at' => now()->addHours($hour)])->id;
    }
    $tie = ($this->run)(['starts_at' => now()->addHours(12)]);

    $rows = app(V2HomeDataService::class)->groupRuns($this->user);

    expect(array_column(array_column($rows, 'group'), 'id'))->toBe([$this->group->id, $laterGroup->id])
        ->and(array_column($rows[0]['runs'], 'id'))->toBe([...array_reverse($expected), $tie->id])
        ->and(array_column($rows[1]['runs'], 'id'))->toBe([$later->id]);
});

it('defers group runs with localized links and protects private applicant previews', function () {
    app()->instance('env', 'local');
    $run = ($this->run)(['is_public' => false]);
    $privateUser = User::factory()->create(['public_profile' => false, 'public_characters' => true]);
    foreach ([$privateUser, null, $this->user, User::factory()->create()] as $index => $applicant) {
        $application = ActivityApplication::factory()->create([
            'activity_id' => $run->id,
            'user_id' => $applicant?->id,
            'status' => $index === 3 ? ActivityApplication::STATUS_WITHDRAWN : ActivityApplication::STATUS_PENDING,
            'created_at' => now()->subMinutes(4 - $index),
        ]);
        $application->update(['applicant_avatar_url' => "https://example.test/avatar-{$index}.png"]);
    }
    $parameters = ['locale' => 'de', 'group' => $this->group->slug, 'activity' => $run->id];

    $this->actingAs($this->user)->get(route('ui-v2.home', ['locale' => 'de']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->missing('groupRuns')
        ->loadDeferredProps('v2-group-runs', fn (Assert $page) => $page
            ->has('groupRuns', 1)
            ->has('groupRuns.0.runs', 1)
            ->where('groupRuns.0.group.id', $this->group->id)
            ->where('groupRuns.0.runs_href', route('groups.dashboard.activities.index', [
                'locale' => 'de', 'group' => $this->group->slug,
            ], false))
            ->where('groupRuns.0.runs.0.href', route('groups.activities.overview', $parameters, false))
            ->where('groupRuns.0.runs.0.application_href', route('groups.activities.application', $parameters, false))
            ->where('groupRuns.0.runs.0.application_count', 3)
            ->where('groupRuns.0.runs.0.applicant_avatars', [null, null, 'https://example.test/avatar-2.png'])
            ->where('groupRuns.0.runs.0.has_existing_application', true)
            ->where('groupRuns.0.runs.0.can_apply', false)
            ->missing('groupRuns.0.runs.0.applications')
            ->missing('groupRuns.0.runs.0.secret_key')
            ->missing('groupRuns.0.group.discord_link_token_hash')
            ->missing('recommendedRuns')));
});

it('omits empty groups instead of rendering empty carousel rows', function () {
    ($this->run)(['starts_at' => now()->addDays(8)]);
    Group::factory()->withMember($this->user)->create();

    expect(app(V2HomeDataService::class)->groupRuns($this->user))->toBe([]);
});
