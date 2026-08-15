<?php

use App\DTOs\QuotaCheck;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\Group;
use App\Models\QuotaOverride;
use App\Models\User;
use App\Services\Quotas\QuotaService;
use App\Support\Quotas\QuotaKey;
use App\Support\Quotas\QuotaScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('quotas.mode', 'enforce');
});

function setQuotaLimit(string $key, int $limit): void
{
    $limits = config('quotas.limits', []);
    $limits[$key] = $limit;

    config()->set('quotas.limits', $limits);
}

it('blocks quota consumption atomically at the default limit', function () {
    setQuotaLimit(QuotaKey::GROUPS_OWNED, 2);

    $user = User::factory()->create();
    Group::factory()->count(2)->create(['owner_id' => $user->id]);
    $operationRan = false;

    expect(fn () => app(QuotaService::class)->run([
        new QuotaCheck(QuotaKey::GROUPS_OWNED, $user),
    ], function () use (&$operationRan): void {
        $operationRan = true;
    }))->toThrow(ValidationException::class);

    expect($operationRan)->toBeFalse()
        ->and(Group::query()->where('owner_id', $user->id)->count())->toBe(2);
});

it('applies a focused override without disabling other quotas', function () {
    setQuotaLimit(QuotaKey::GROUPS_OWNED, 1);

    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();
    Group::factory()->create(['owner_id' => $user->id]);

    QuotaOverride::query()->create([
        'subject_type' => QuotaScope::USER,
        'subject_id' => $user->id,
        'quota_key' => QuotaKey::GROUPS_OWNED,
        'limit' => 2,
        'is_unlimited' => false,
        'reason' => 'Approved community organizer.',
        'created_by_user_id' => $admin->id,
    ]);

    $created = app(QuotaService::class)->run([
        new QuotaCheck(QuotaKey::GROUPS_OWNED, $user),
    ], fn (): Group => Group::factory()->create(['owner_id' => $user->id]));

    expect($created)->toBeInstanceOf(Group::class)
        ->and(Group::query()->where('owner_id', $user->id)->count())->toBe(2);
});

it('logs through observation mode without blocking the write', function () {
    config()->set('quotas.mode', 'observe');
    setQuotaLimit(QuotaKey::GROUPS_OWNED, 1);

    $user = User::factory()->create();
    Group::factory()->create(['owner_id' => $user->id]);
    $operationRan = false;

    app(QuotaService::class)->run([
        new QuotaCheck(QuotaKey::GROUPS_OWNED, $user),
    ], function () use (&$operationRan): void {
        $operationRan = true;
    });

    expect($operationRan)->toBeTrue();
});

it('does not block an idempotent group join at the membership limit', function () {
    setQuotaLimit(QuotaKey::GROUPS_JOINED, 1);

    $user = User::factory()->create();
    $joinedGroup = Group::factory()->open()->create();
    $otherGroup = Group::factory()->open()->create();
    $joinedGroup->memberships()->create([
        'user_id' => $user->id,
        'role' => 'member',
        'joined_at' => now(),
    ]);

    $this->actingAs($user)
        ->post(route('groups.join', $joinedGroup))
        ->assertRedirect(route('groups.dashboard', $joinedGroup));

    $this->actingAs($user)
        ->from(route('groups.index'))
        ->post(route('groups.join', $otherGroup))
        ->assertRedirect(route('groups.index'))
        ->assertSessionHasErrors('quota');

    expect($otherGroup->memberships()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('counts scheduled-day run limits in the group timezone and ignores cancelled runs', function () {
    setQuotaLimit(QuotaKey::RUNS_PER_DAY, 5);

    $group = Group::factory()->create(['active_timezone' => 'America/Los_Angeles']);
    $startsAt = '2026-08-11T06:30:00Z';

    Activity::factory()->count(5)->create([
        'group_id' => $group->id,
        'starts_at' => $startsAt,
        'status' => Activity::STATUS_SCHEDULED,
    ]);
    Activity::factory()->create([
        'group_id' => $group->id,
        'starts_at' => $startsAt,
        'status' => Activity::STATUS_CANCELLED,
    ]);

    $status = app(QuotaService::class)->status(
        QuotaKey::RUNS_PER_DAY,
        $group,
        ['starts_at' => '2026-08-10T23:30:00-07:00'],
        1,
    );

    expect($status['usage'])->toBe(5)
        ->and($status['exceeded'])->toBeTrue();
});

it('does not count applications for historical runs as active quota usage', function () {
    $user = User::factory()->create();
    $group = Group::factory()->create();
    $upcoming = Activity::factory()->create([
        'group_id' => $group->id,
        'status' => Activity::STATUS_SCHEDULED,
        'starts_at' => now()->addDay(),
    ]);
    $completed = Activity::factory()->create([
        'group_id' => $group->id,
        'status' => Activity::STATUS_COMPLETE,
        'starts_at' => now()->subDay(),
    ]);

    ActivityApplication::factory()->create([
        'activity_id' => $upcoming->id,
        'user_id' => $user->id,
        'status' => ActivityApplication::STATUS_APPROVED,
    ]);
    ActivityApplication::factory()->create([
        'activity_id' => $completed->id,
        'user_id' => $user->id,
        'status' => ActivityApplication::STATUS_APPROVED,
    ]);

    $status = app(QuotaService::class)->status(QuotaKey::ACTIVE_APPLICATIONS, $user);

    expect($status['usage'])->toBe(1);
});

it('only allows site admins to manage quota overrides', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get(route('admin.quotas.index'))
        ->assertForbidden();
});

it('lets site admins create and inspect a quota override', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.quotas.store'), [
            'subject_type' => QuotaScope::USER,
            'subject_id' => $user->id,
            'quota_key' => QuotaKey::CHARACTERS_TOTAL,
            'limit' => 25,
            'is_unlimited' => false,
            'starts_at' => null,
            'expires_at' => now()->addMonth()->toIso8601String(),
            'reason' => 'Support-approved character import.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'quota_override_saved');

    $this->assertDatabaseHas('quota_overrides', [
        'subject_type' => QuotaScope::USER,
        'subject_id' => $user->id,
        'quota_key' => QuotaKey::CHARACTERS_TOTAL,
        'limit' => 25,
        'is_unlimited' => false,
        'created_by_user_id' => $admin->id,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.quotas.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Quotas')
            ->where('mode', 'enforce')
            ->where('overrides.data.0.subject_id', $user->id)
            ->where('overrides.data.0.quota_key', QuotaKey::CHARACTERS_TOTAL)
            ->where('overrides.data.0.limit', 25)
        );
});
