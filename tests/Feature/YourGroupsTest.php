<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use App\Models\ActivityTypeVersion;
use App\Models\AuditLog;
use App\Models\Character;
use App\Models\Group;
use App\Models\User;
use App\Support\Audit\AuditScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(now()->startOfSecond());
    $this->user = User::factory()->create();
    $this->version = ActivityTypeVersion::factory()->create(['layout_schema' => [], 'progress_schema' => [], 'prog_points' => []]);
    $this->group = function (string $role = 'member'): Group {
        $group = Group::factory()->open()->create([
            ...($role === 'owner' ? ['owner_id' => $this->user->id] : []),
            'created_at' => now()->subMonths(3), 'updated_at' => now()->subMonths(2),
        ]);
        if ($role !== 'owner') {
            $group->memberships()->create(['user_id' => $this->user->id, 'role' => $role]);
        }
        $group->memberships()->update(['joined_at' => now()->subMonths(2), 'created_at' => now()->subMonths(2)]);

        return $group;
    };
    $this->run = fn (Group $group, array $attributes = []) => Activity::factory()->create([
        'group_id' => $group->id, 'activity_type_version_id' => $this->version->id,
        'organized_by_user_id' => $group->owner_id, 'status' => Activity::STATUS_SCHEDULED,
        'created_at' => now()->subWeeks(3), 'updated_at' => now()->subWeeks(2),
        ...$attributes,
    ]);
    $this->apply = fn (Activity $run, int $minutesAgo) => ActivityApplication::query()->forceCreate([
        'activity_id' => $run->id, 'user_id' => $this->user->id,
        'submitted_at' => now()->subMinutes($minutesAgo), 'created_at' => now()->subMinutes($minutesAgo),
    ]);
});

it('returns every membership once with owner admin moderator then personally recent members', function () {
    $oldMember = ($this->group)();
    $recentMember = ($this->group)();
    $moderator = ($this->group)('moderator');
    $admin = ($this->group)('admin');
    $owner = ($this->group)('owner');
    // Ownership wins even if a legacy membership has a different role and appears in both lists.
    $owner->memberships()->where('user_id', $this->user->id)->update(['role' => 'admin']);
    ($this->apply)(($this->run)($oldMember, ['updated_at' => now()]), 240);
    ($this->apply)(($this->run)($recentMember), 60);
    Group::factory()->open()->create();

    $this->actingAs($this->user)->get(route('groups.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('yourGroups', 5)
            ->where('yourGroups', fn ($groups) => collect($groups)->pluck('id')->all() === [
                $owner->id, $admin->id, $moderator->id, $recentMember->id, $oldMember->id,
            ])
            ->where('yourGroups.0.current_user_role', 'owner')
            ->where('yourGroups.3.last_interaction_at', now()->subHour()->toIso8601String())
            ->where('yourGroups.4.stats.last_activity_at', now()->toIso8601String()));
});

it('keeps owners first even when their membership record is absent', function () {
    $owner = ($this->group)('owner');
    $owner->memberships()->delete();
    ($this->group)('admin');

    $this->actingAs($this->user)->get(route('groups.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('yourGroups.0.id', $owner->id)
            ->where('yourGroups.0.current_user_role', 'owner'));
});

it('uses run updates and completions for the group timestamp without counting hidden drafts or future dates', function () {
    $group = ($this->group)();
    ($this->run)($group, ['updated_at' => now()->subHours(2)]);
    ($this->run)($group, ['status' => Activity::STATUS_COMPLETE, 'completed_at' => now()->subHour()]);
    ($this->run)($group, ['status' => Activity::STATUS_DRAFT, 'updated_at' => now()]);
    ($this->run)($group, ['created_at' => now()->addDay(), 'updated_at' => now()->addDay()]);

    $this->actingAs($this->user)->get(route('groups.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('yourGroups.0.stats.last_activity_at', now()->subHour()->toIso8601String())
            ->where('yourGroups.0.last_interaction_at', now()->subMonths(2)->toIso8601String()));
});

it('sorts by application edits and actor actions without crediting someone elses review', function () {
    $edited = ($this->group)();
    $reviewed = ($this->group)();
    $acted = ($this->group)();
    ($this->apply)(($this->run)($edited), 500)->update(['edited_at' => now()->subMinutes(30)]);
    ($this->apply)(($this->run)($reviewed), 400)->update([
        'reviewed_at' => now(), 'reviewed_by_user_id' => $reviewed->owner_id,
    ]);
    AuditLog::query()->create([
        'actor_user_id' => $this->user->id, 'scope_type' => AuditScope::GROUP, 'scope_id' => $acted->id,
        'action' => 'group.resource.created', 'severity' => 'info', 'message' => 'Created resource',
        'created_at' => now()->subMinutes(15),
    ]);

    $this->actingAs($this->user)->get(route('groups.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('yourGroups', fn ($groups) => collect($groups)->pluck('id')->all() === [
            $acted->id, $edited->id, $reviewed->id,
        ]));
});

it('uses recent attendance and completed participation instead of the original application time', function (string $attendance, bool $countsCompletion) {
    $group = ($this->group)();
    $run = ($this->run)($group, [
        'status' => Activity::STATUS_COMPLETE, 'starts_at' => now()->subHours(3), 'completed_at' => now()->subHour(),
    ]);
    $character = Character::factory()->create(['user_id' => $this->user->id]);
    $slot = ActivitySlot::factory()->create(['activity_id' => $run->id]);
    ActivitySlotAssignment::query()->create([
        'activity_id' => $run->id, 'group_id' => $group->id, 'activity_slot_id' => $slot->id,
        'character_id' => $character->id, 'attendance_status' => $attendance,
        'assigned_at' => now()->subDay(), 'checked_in_at' => $countsCompletion ? now()->subHours(3) : null,
    ]);
    $other = ($this->group)();
    ($this->apply)(($this->run)($other), 120);

    $expected = $countsCompletion ? [$group->id, $other->id] : [$other->id, $group->id];
    $this->actingAs($this->user)->get(route('groups.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('yourGroups', fn ($groups) => collect($groups)->pluck('id')->all() === $expected));
})->with([
    'checked in' => [ActivitySlotAssignment::STATUS_CHECKED_IN, true],
    'late' => [ActivitySlotAssignment::STATUS_LATE, true],
    'missing' => [ActivitySlotAssignment::STATUS_MISSING, false],
]);

it('returns an empty your groups list for users with no memberships', function () {
    Group::factory()->count(2)->open()->create();
    $this->actingAs($this->user)->get(route('groups.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('yourGroups', 0));
});
