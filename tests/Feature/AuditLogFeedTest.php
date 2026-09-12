<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\AuditLog;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use App\Services\Groups\GroupActivityAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function createFeedAuditLog(Group $group, array $attributes = []): AuditLog
{
    return AuditLog::create(array_merge([
        'scope_type' => 'group',
        'scope_id' => $group->id,
        'action' => 'group.activity.updated',
        'severity' => 'info',
        'message' => 'audit_log.events.group.activity.updated',
        'created_at' => '2026-08-03 18:00:00',
        'metadata' => [],
    ], $attributes));
}

it('loads forty rows at a time with stable cursors on both audit pages', function (bool $admin) {
    $user = User::factory()->create(['is_admin' => $admin]);
    $group = Group::factory()->create(['owner_id' => $user->id]);
    $ids = collect(range(1, 85))->map(fn () => createFeedAuditLog($group)->id)->reverse()->values();
    $url = $admin
        ? route('admin.audit-log', ['locale' => 'en'])
        : route('groups.dashboard.audit-log', ['locale' => 'en', 'group' => $group]);

    $response = $this->actingAs($user)->get($url)->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->has('auditLogs', 40)->where('auditLogs.0.id', $ids[0])
        ->where('auditLogs.39.id', $ids[39])->where('nextCursor', fn ($cursor) => is_string($cursor)));
    $cursor = $response->viewData('page')['props']['nextCursor'];

    // A new event must not shift the next page or duplicate existing rows.
    createFeedAuditLog($group, ['created_at' => '2026-08-04 18:00:00']);
    $second = $this->getJson($url.'?'.http_build_query(['cursor' => $cursor]))
        ->assertOk()->assertJsonCount(40, 'auditLogs')
        ->assertJsonPath('auditLogs.0.id', $ids[40])
        ->assertJsonPath('auditLogs.39.id', $ids[79])
        ->assertJsonMissingPath('filters');
    $this->getJson($url.'?'.http_build_query(['cursor' => $second->json('nextCursor')]))
        ->assertOk()->assertJsonCount(5, 'auditLogs')
        ->assertJsonPath('auditLogs.0.id', $ids[80])->assertJsonPath('nextCursor', null);
})->with([true, false]);

it('filters the full history rather than just the loaded page', function (bool $admin) {
    $actor = User::factory()->create(['is_admin' => $admin, 'name' => 'Audit Moderator']);
    $group = Group::factory()->create(['owner_id' => $actor->id]);
    $match = createFeedAuditLog($group, [
        'action' => 'group.activity.created', 'severity' => 'moderation_change',
        'message' => 'audit_log.events.group.activity.created',
        'actor_user_id' => $actor->id, 'metadata' => ['activity_title' => 'Specific older run'],
    ]);
    foreach (range(1, 45) as $index) {
        createFeedAuditLog($group, ['created_at' => '2026-08-04 18:00:00']);
    }
    $url = $admin
        ? route('admin.audit-log', ['locale' => 'en'])
        : route('groups.dashboard.audit-log', ['locale' => 'en', 'group' => $group]);
    $this->actingAs($actor)->get($url)->assertInertia(fn (Assert $page) => $page
        ->has('filters.actions', 2)->has('filters.severities', 2)->has('filters.users', 2));
    $this->getJson($url.'?'.http_build_query([
        'search' => 'SPECIFIC OLDER', 'action' => $match->action, 'severity' => $match->severity,
        'user' => $actor->id, 'afterDate' => '2026-08-03', 'beforeDate' => '2026-08-03',
    ]))->assertOk()->assertJsonCount(1, 'auditLogs')->assertJsonPath('auditLogs.0.id', $match->id);
    $this->getJson($url.'?search=Audit%20Moderator')
        ->assertOk()->assertJsonCount(1, 'auditLogs');
    $translatedTitle = __('audit_log.events.group.activity.created');
    $this->getJson($url.'?'.http_build_query(['search' => $translatedTitle]))
        ->assertOk()->assertJsonCount(1, 'auditLogs')->assertJsonPath('auditLogs.0.id', $match->id);
    $this->getJson($url.'?user=__system__&beforeDate=2026-08-03')
        ->assertOk()->assertJsonCount(0, 'auditLogs');
})->with([true, false]);

it('keeps the admin group filter available beyond the first page', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $first = Group::factory()->create();
    $second = Group::factory()->create();
    $match = createFeedAuditLog($second);
    foreach (range(1, 45) as $index) {
        createFeedAuditLog($first);
    }
    $url = route('admin.audit-log', ['locale' => 'en']);
    $this->actingAs($admin)->get($url.'?search=')->assertInertia(fn (Assert $page) => $page
        ->has('filters.groups', 2)->where('selectedFilters', []));
    $this->getJson($url.'?group='.$second->id)->assertOk()
        ->assertJsonCount(1, 'auditLogs')->assertJsonPath('auditLogs.0.id', $match->id);
});

it('filters exact runs and keeps run options and records inside their group', function () {
    $group = Group::factory()->create();
    $otherGroup = Group::factory()->create();
    $run = Activity::factory()->create(['group_id' => $group->id, 'title' => 'Repeated title']);
    $otherRun = Activity::factory()->create(['group_id' => $group->id, 'title' => $run->title]);
    $foreignRun = Activity::factory()->create(['group_id' => $otherGroup->id]);
    $direct = createFeedAuditLog($group, ['subject_type' => Activity::class, 'subject_id' => $run->id]);
    $metadata = createFeedAuditLog($group, [
        'subject_type' => User::class, 'subject_id' => $group->owner_id,
        'metadata' => ['activity_id' => $run->id, 'activity_title' => $run->title],
    ]);
    $application = ActivityApplication::factory()->create(['activity_id' => $run->id]);
    $legacy = createFeedAuditLog($group, ['subject_type' => ActivityApplication::class, 'subject_id' => $application->id]);
    createFeedAuditLog($group, ['metadata' => ['activity_id' => $otherRun->id, 'activity_title' => $run->title]]);
    createFeedAuditLog($group, ['metadata' => ['activity_title' => $run->title]]);
    createFeedAuditLog($otherGroup, ['metadata' => ['activity_id' => $run->id]]);
    $url = route('groups.dashboard.audit-log', ['locale' => 'en', 'group' => $group]);

    $this->actingAs($group->owner)->get($url)->assertInertia(fn (Assert $page) => $page
        ->has('filters.activities', 2)->where('filters.activities', fn ($options) => collect($options)
        ->pluck('value')->sort()->values()->all() === collect([(string) $run->id, (string) $otherRun->id])->sort()->values()->all()));
    $result = $this->getJson($url.'?activity='.$run->id)->assertOk()->assertJsonCount(3, 'auditLogs');
    expect(collect($result->json('auditLogs'))->pluck('id')->all())->toBe([$legacy->id, $metadata->id, $direct->id]);
    $this->getJson($url.'?activity='.$foreignRun->id)->assertUnprocessable()->assertJsonValidationErrors('activity');
});

it('records the exact run for application audit events even when the subject is a user', function () {
    $run = Activity::factory()->create();
    $application = ActivityApplication::factory()->create(['activity_id' => $run->id]);
    app(GroupActivityAuditService::class)->logApplicationSubmitted($application, $application->user);

    $log = AuditLog::query()->where('action', 'group.activity.application.submitted')->sole();
    expect($log->subject_type)->toBe(User::class)
        ->and($log->metadata['activity_id'])->toBe($run->id);
});

it('protects both html and incremental audit requests', function () {
    $member = User::factory()->create();
    $group = Group::factory()->withMember($member, GroupMembership::ROLE_MEMBER)->create();
    $groupUrl = route('groups.dashboard.audit-log', ['locale' => 'en', 'group' => $group]);
    $adminUrl = route('admin.audit-log', ['locale' => 'en']);
    foreach ([$groupUrl, $adminUrl] as $url) {
        $this->actingAs($member)->get($url)->assertForbidden();
        $this->getJson($url)->assertForbidden();
    }
});
