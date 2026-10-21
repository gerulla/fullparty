<?php

use App\Models\AuditLog;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupMembershipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function membershipApplicationGroupPayload(string $slug, string $joinMode = Group::JOIN_MODE_APPLICATION, string $groupType = Group::TYPE_COMMUNITY): array
{
    return [
        'name' => 'Application Group',
        'description' => 'A group that reviews members.',
        'profile_picture' => null,
        'banner_image' => null,
        'discord_invite_url' => null,
        'datacenter' => config('datacenters.values')[0] ?? 'Light',
        'join_mode' => $joinMode,
        'is_visible' => true,
        'slug' => $slug,
        'group_type' => $groupType,
        'primary_focuses' => [config('group_discovery.primary_focuses')[0] ?? 'progression'],
        'experience_expectation' => config('group_discovery.experience_expectations')[0] ?? 'casual',
        'voice_expectation' => config('group_discovery.voice_expectations')[0] ?? 'optional',
        'preferred_languages' => [config('group_discovery.preferred_languages')[0] ?? 'en'],
        'tags' => [],
        'active_timezone' => null,
        'active_days' => [],
        'active_start_time' => null,
        'active_end_time' => null,
    ];
}

function membershipApplicationSettingsPayload(Group $group, string $joinMode): array
{
    return [
        'name' => $group->name,
        'description' => $group->description,
        'profile_picture' => null,
        'banner_image' => null,
        'discord_invite_url' => $group->discord_invite_url,
        'datacenter' => $group->datacenter,
        'join_mode' => $joinMode,
        'is_visible' => $group->is_visible,
    ];
}

it('rejects retired membership mode when creating either group type', function (string $type) {
    $this->actingAs(User::factory()->create())
        ->post(route('groups.store'), membershipApplicationGroupPayload('retired', Group::JOIN_MODE_APPLICATION, $type))
        ->assertSessionHasErrors('join_mode');

    expect(Group::where('slug', 'retired')->exists())->toBeFalse();
})->with([Group::TYPE_COMMUNITY, Group::TYPE_STATIC]);

it('allows either supported join mode for both group types when updating settings', function (string $type, string $mode) {
    $group = Group::factory()->create(['group_type' => $type]);

    $this->actingAs($group->owner)
        ->put(route('groups.dashboard.settings.update', $group), membershipApplicationSettingsPayload($group, $mode))
        ->assertSessionDoesntHaveErrors();

    expect($group->fresh()->join_mode)->toBe($mode)
        ->and($group->fresh()->membership_application_schema)->toBeNull()
        ->and($group->fresh()->hasPermanentInvite())->toBe($mode === Group::JOIN_MODE_OPEN);
})->with([
    [Group::TYPE_COMMUNITY, Group::JOIN_MODE_OPEN],
    [Group::TYPE_COMMUNITY, Group::JOIN_MODE_INVITE_ONLY],
    [Group::TYPE_STATIC, Group::JOIN_MODE_OPEN],
    [Group::TYPE_STATIC, Group::JOIN_MODE_INVITE_ONLY],
]);

it('rejects retired membership mode in settings without changing membership or forms', function (string $type) {
    $group = Group::factory()->create(['group_type' => $type]);

    $this->actingAs($group->owner)
        ->put(route('groups.dashboard.settings.update', $group), membershipApplicationSettingsPayload($group, Group::JOIN_MODE_APPLICATION))
        ->assertSessionHasErrors('join_mode');

    expect($group->fresh()->join_mode)->toBe(Group::JOIN_MODE_INVITE_ONLY)
        ->and($group->fresh()->membership_application_schema)->toBeNull();
})->with([Group::TYPE_COMMUNITY, Group::TYPE_STATIC]);

it('rejects the retired join-mode discovery filter', function () {
    $this->actingAs(User::factory()->create())
        ->getJson(route('groups.search', ['join_mode' => Group::JOIN_MODE_APPLICATION]))
        ->assertUnprocessable()->assertJsonValidationErrors('join_mode');
});

it('keeps retired request and review endpoints closed even for legacy groups', function (string $method, string $routeName, bool $withApplication) {
    Queue::fake();
    $group = Group::factory()->applicationBased()->create();
    $request = GroupMembershipApplication::factory()->create(['group_id' => $group->id]);
    $parameters = ['group' => $group];
    if ($withApplication) {
        $parameters['application'] = $request;
    }

    $this->actingAs($group->owner)->{$method}(route($routeName, $parameters), [
        'answers' => ['intro' => 'This should not be saved.'],
        'fields' => [],
    ])->assertNotFound();

    expect($request->fresh()->status)->toBe(GroupMembershipApplication::STATUS_PENDING)
        ->and(GroupMembershipApplication::count())->toBe(1)
        ->and($group->memberships()->where('user_id', $request->user_id)->exists())->toBeFalse();
    Queue::assertNothingPushed();
})->with([
    ['get', 'groups.membership-applications.create', false],
    ['post', 'groups.membership-applications.store', false],
    ['put', 'groups.membership-applications.update', false],
    ['get', 'groups.dashboard.membership-applications.index', false],
    ['post', 'groups.dashboard.membership-applications.approve', true],
    ['post', 'groups.dashboard.membership-applications.decline', true],
    ['get', 'groups.dashboard.membership-application-form.edit', false],
    ['put', 'groups.dashboard.membership-application-form.update', false],
]);

it('redirects old request-list bookmarks to groups', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('groups.requests.index'))->assertRedirect(route('groups.index'));
});

it('does not advertise request actions for legacy records before the migration runs', function () {
    $group = Group::factory()->applicationBased()->create();
    $this->actingAs($group->owner)->get(route('groups.dashboard.settings', $group))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('group.permissions.can_review_membership_applications', false)
        ->where('group.permissions.can_manage_membership_application_form', false));
});

it('cancels every pending membership request and preserves membership and historical evidence', function () {
    Queue::fake();
    $legacy = Group::factory()->applicationBased()->create([
        'membership_application_schema' => [['id' => 'intro', 'type' => 'small_text']],
        'is_visible' => false,
    ]);
    $open = Group::factory()->open()->create();
    $inviteOnly = Group::factory()->inviteOnly()->create();
    $pending = collect([$legacy, $open, $inviteOnly])->map(fn (Group $group) => GroupMembershipApplication::factory()->create(['group_id' => $group->id]));
    $approved = GroupMembershipApplication::factory()->approved()->create(['group_id' => $legacy->id]);
    $declined = GroupMembershipApplication::factory()->declined()->create(['group_id' => $legacy->id]);
    $approvedBefore = $approved->fresh()->getAttributes();
    $declinedBefore = $declined->fresh()->getAttributes();
    $answers = $pending->mapWithKeys(fn ($request) => [$request->id => [$request->answers, $request->form_snapshot]]);
    $memberships = GroupMembership::count();
    $invites = DB::table('group_invites')->count();
    $schema = $legacy->membership_application_schema;

    $migration = require database_path('migrations/2026_10_21_200000_retire_group_membership_requests.php');
    $migration->up();

    expect($legacy->fresh()->join_mode)->toBe(Group::JOIN_MODE_INVITE_ONLY)
        ->and($legacy->fresh()->is_visible)->toBeFalse()
        ->and($legacy->fresh()->membership_application_schema)->toBe($schema)
        ->and($open->fresh()->join_mode)->toBe(Group::JOIN_MODE_OPEN)
        ->and($inviteOnly->fresh()->join_mode)->toBe(Group::JOIN_MODE_INVITE_ONLY)
        ->and(GroupMembership::count())->toBe($memberships)
        ->and(DB::table('group_invites')->count())->toBe($invites)
        ->and($approved->fresh()->getAttributes())->toBe($approvedBefore)
        ->and($declined->fresh()->getAttributes())->toBe($declinedBefore)
        ->and(GroupMembershipApplication::where('status', 'pending')->count())->toBe(0);

    foreach ($pending as $request) {
        $request->refresh();
        expect($request->status)->toBe(GroupMembershipApplication::STATUS_CANCELLED)
            ->and($request->cancelled_at)->not->toBeNull()
            ->and($request->cancellation_reason)->toBe('membership_requests_retired')
            ->and($request->reviewed_at)->toBeNull()
            ->and($request->reviewed_by_user_id)->toBeNull()
            ->and([$request->answers, $request->form_snapshot])->toBe($answers[$request->id]);
    }

    $logs = AuditLog::where('action', 'group.membership_requests.retired')->get();
    expect($logs)->toHaveCount(3);
    $legacyLog = $logs->firstWhere('scope_id', $legacy->id);
    expect($legacyLog->actor_user_id)->toBeNull()
        ->and($legacyLog->metadata['previous_join_mode'])->toBe('application')
        ->and($legacyLog->metadata['join_mode'])->toBe('invite_only')
        ->and($legacyLog->metadata['cancelled_request_count'])->toBe(1);

    // Retrying or rolling back must not reopen requests or duplicate the audit events.
    $migration->down();
    $migration->up();
    expect(GroupMembershipApplication::where('status', 'cancelled')->count())->toBe(3)
        ->and(AuditLog::where('action', 'group.membership_requests.retired')->count())->toBe(3);
    Queue::assertNothingPushed();
});
