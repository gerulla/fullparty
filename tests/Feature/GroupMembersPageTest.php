<?php

use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\PhantomJob;
use App\Models\User;
use App\Services\Groups\ActivitySlotBench;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shows group-specific completed run participation counts for members', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['name' => 'Completed Runner']);
    $group = Group::factory()->create(['owner_id' => $owner->id]);

    GroupMembership::query()->firstOrCreate(
        ['group_id' => $group->id, 'user_id' => $member->id],
        ['role' => GroupMembership::ROLE_MEMBER, 'joined_at' => now()->subMonth()]
    );

    $character = Character::factory()->primary()->create([
        'user_id' => $member->id,
        'name' => 'Runner Main',
    ]);
    $replacementCharacter = Character::factory()->create([
        'user_id' => $owner->id,
        'name' => 'Replacement Runner',
    ]);

    $firstCompleted = Activity::factory()->complete()->create([
        'group_id' => $group->id,
        'starts_at' => now()->subDays(4),
    ]);
    $secondCompleted = Activity::factory()->complete()->create([
        'group_id' => $group->id,
        'activity_type_id' => $firstCompleted->activity_type_id,
        'activity_type_version_id' => $firstCompleted->activity_type_version_id,
        'starts_at' => now()->subDays(2),
    ]);
    $scheduled = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $firstCompleted->activity_type_id,
        'activity_type_version_id' => $firstCompleted->activity_type_version_id,
        'status' => Activity::STATUS_SCHEDULED,
    ]);
    $cancelled = Activity::factory()->create([
        'group_id' => $group->id,
        'activity_type_id' => $firstCompleted->activity_type_id,
        'activity_type_version_id' => $firstCompleted->activity_type_version_id,
        'status' => Activity::STATUS_CANCELLED,
    ]);
    $missingCompleted = Activity::factory()->complete()->create([
        'group_id' => $group->id,
        'activity_type_id' => $firstCompleted->activity_type_id,
        'activity_type_version_id' => $firstCompleted->activity_type_version_id,
    ]);
    $endedCompleted = Activity::factory()->complete()->create([
        'group_id' => $group->id,
        'activity_type_id' => $firstCompleted->activity_type_id,
        'activity_type_version_id' => $firstCompleted->activity_type_version_id,
    ]);
    $benchCompleted = Activity::factory()->complete()->create([
        'group_id' => $group->id,
        'activity_type_id' => $firstCompleted->activity_type_id,
        'activity_type_version_id' => $firstCompleted->activity_type_version_id,
    ]);
    $otherGroup = Group::factory()->create();
    $otherGroupCompleted = Activity::factory()->complete()->create([
        'group_id' => $otherGroup->id,
        'activity_type_id' => $firstCompleted->activity_type_id,
        'activity_type_version_id' => $firstCompleted->activity_type_version_id,
    ]);

    createAssignmentForMemberCount($firstCompleted, $character, ActivitySlotAssignment::STATUS_CHECKED_IN);
    createAssignmentForMemberCount($firstCompleted, $character, ActivitySlotAssignment::STATUS_LATE);
    assignCurrentSlotForMemberCount($secondCompleted, $character);
    createAssignmentForMemberCount($scheduled, $character, ActivitySlotAssignment::STATUS_CHECKED_IN);
    createAssignmentForMemberCount($cancelled, $character, ActivitySlotAssignment::STATUS_CHECKED_IN);
    createAssignmentForMemberCount($missingCompleted, $character, ActivitySlotAssignment::STATUS_MISSING);
    createAssignmentForMemberCount($endedCompleted, $character, ActivitySlotAssignment::STATUS_CHECKED_IN, endedAt: now()->subDay());
    assignCurrentSlotForMemberCount($endedCompleted, $replacementCharacter);
    createAssignmentForMemberCount($benchCompleted, $character, ActivitySlotAssignment::STATUS_CHECKED_IN, isBench: true);
    createAssignmentForMemberCount($otherGroupCompleted, $character, ActivitySlotAssignment::STATUS_CHECKED_IN);

    $this->actingAs($owner)
        ->get(route('groups.dashboard.members', $group))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Groups/Members/Index')
            ->where('group.permissions.can_view_member_activity_summary', true)
            ->where('members.1.name', 'Completed Runner')
            ->where('members.1.participated_run_count', 2)
        );
});

it('lazy loads member activity summaries scoped to the current group', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['name' => 'History Runner']);
    $group = Group::factory()->create(['owner_id' => $owner->id, 'name' => 'Current Group']);
    $otherGroup = Group::factory()->create(['name' => 'Visible Group', 'is_visible' => true]);

    GroupMembership::query()->firstOrCreate(
        ['group_id' => $group->id, 'user_id' => $member->id],
        ['role' => GroupMembership::ROLE_MEMBER, 'joined_at' => now()->subMonth()]
    );

    $character = Character::factory()->primary()->create([
        'user_id' => $member->id,
        'name' => 'Summary Character',
        'world' => 'Lich',
        'datacenter' => 'Light',
    ]);
    $characterClass = CharacterClass::query()->create([
        'name' => 'Astrologian',
        'shorthand' => 'AST',
        'role' => 'healer',
        'icon_url' => '/class-icons/astrologian.webp',
        'flaticon_url' => '/class-icons/astrologian-flat.webp',
    ]);
    $phantomJob = PhantomJob::query()->create([
        'name' => 'Geomancer',
        'max_level' => 6,
        'icon_url' => '/phantom-jobs/geomancer.webp',
        'transparent_icon_url' => '/phantom-jobs/geomancer-transparent.webp',
    ]);

    $groupRun = Activity::factory()->complete()->create([
        'group_id' => $group->id,
        'title' => 'Current Group Clear',
        'starts_at' => now()->subDays(3),
        'completed_at' => now()->subDays(3)->addHours(2),
    ]);
    $groupRun->activityTypeVersion->update([
        'name' => [
            'en' => 'Forked Tower of Blood',
            'de' => 'Forked Tower of Blood',
            'fr' => 'Tour Bifurquee de Sang',
            'ja' => 'Forked Tower of Blood',
        ],
    ]);

    $otherRun = Activity::factory()->complete()->create([
        'group_id' => $otherGroup->id,
        'activity_type_id' => $groupRun->activity_type_id,
        'activity_type_version_id' => $groupRun->activity_type_version_id,
        'title' => 'Other Group Clear',
        'starts_at' => now()->subDay(),
        'completed_at' => now()->subDay()->addHours(2),
    ]);
    $currentSlotRun = Activity::factory()->complete()->create([
        'group_id' => $group->id,
        'activity_type_id' => $groupRun->activity_type_id,
        'activity_type_version_id' => $groupRun->activity_type_version_id,
        'title' => 'Current Slot Clear',
        'starts_at' => now()->subDays(5),
        'completed_at' => now()->subDays(5)->addHours(2),
    ]);

    createAssignmentForMemberCount(
        $groupRun,
        $character,
        ActivitySlotAssignment::STATUS_CHECKED_IN,
        snapshot: [
            'character_class' => ['id' => $characterClass->id],
            'phantom_job' => ['id' => $phantomJob->id],
        ],
    );
    createAssignmentForMemberCount(
        $otherRun,
        $character,
        ActivitySlotAssignment::STATUS_CHECKED_IN,
        snapshot: [
            'character_class' => ['id' => $characterClass->id],
        ],
    );
    assignCurrentSlotForMemberCount($currentSlotRun, $character);

    $response = $this->actingAs($owner)
        ->getJson(route('groups.dashboard.members.activity-summary', [$group, $member]))
        ->assertOk()
        ->assertJsonPath('data.last_group_run.id', $groupRun->id)
        ->assertJsonPath('data.last_group_run.title', 'Current Group Clear')
        ->assertJsonPath('data.last_group_run.activity_type_name', 'Forked Tower of Blood')
        ->assertJsonPath('data.last_group_run.character.name', 'Summary Character')
        ->assertJsonPath('data.last_group_run.character_class.shorthand', 'AST')
        ->assertJsonPath('data.last_group_run.phantom_job.name', 'Geomancer')
        ->assertJsonPath('data.last_run.id', $groupRun->id)
        ->assertJsonPath('data.last_run.group.name', 'Current Group')
        ->assertJsonCount(2, 'data.recent_runs')
        ->assertJsonPath('data.recent_runs.0.id', $groupRun->id)
        ->assertJsonPath('data.recent_runs.1.id', $currentSlotRun->id)
        ->assertJsonPath('data.last_run.character_class.shorthand', 'AST');

    expect(collect($response->json('data.recent_runs'))->pluck('id')->all())
        ->toBe([$groupRun->id, $currentSlotRun->id])
        ->not->toContain($otherRun->id);
});

it('limits member activity summaries to the thirty latest runs', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['name' => 'Busy Runner']);
    $group = Group::factory()->create(['owner_id' => $owner->id]);

    GroupMembership::query()->firstOrCreate(
        ['group_id' => $group->id, 'user_id' => $member->id],
        ['role' => GroupMembership::ROLE_MEMBER, 'joined_at' => now()->subMonth()]
    );

    $character = Character::factory()->primary()->create([
        'user_id' => $member->id,
        'name' => 'Busy Character',
    ]);

    $runs = collect(range(1, 31))
        ->map(function (int $daysAgo) use ($group, $character) {
            $activity = Activity::factory()->complete()->create([
                'group_id' => $group->id,
                'starts_at' => now()->subDays($daysAgo),
                'completed_at' => now()->subDays($daysAgo)->addHours(2),
            ]);

            createAssignmentForMemberCount($activity, $character, ActivitySlotAssignment::STATUS_CHECKED_IN);

            return $activity;
        });

    $response = $this->actingAs($owner)
        ->getJson(route('groups.dashboard.members.activity-summary', [$group, $member]))
        ->assertOk()
        ->assertJsonCount(30, 'data.recent_runs')
        ->assertJsonPath('data.last_group_run.id', $runs->first()->id)
        ->assertJsonPath('data.last_run.id', $runs->first()->id);

    expect(collect($response->json('data.recent_runs'))->pluck('id')->all())
        ->not->toContain($runs->last()->id);
});

it('does not lazy load activity summaries for users outside the group', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $group = Group::factory()->create(['owner_id' => $owner->id]);

    $this->actingAs($owner)
        ->getJson(route('groups.dashboard.members.activity-summary', [$group, $outsider]))
        ->assertNotFound();
});

it('does not allow regular members to lazy load member activity summaries', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $target = User::factory()->create();
    $group = Group::factory()->create(['owner_id' => $owner->id]);

    GroupMembership::query()->create([
        'group_id' => $group->id,
        'user_id' => $member->id,
        'role' => GroupMembership::ROLE_MEMBER,
        'joined_at' => now(),
    ]);
    GroupMembership::query()->create([
        'group_id' => $group->id,
        'user_id' => $target->id,
        'role' => GroupMembership::ROLE_MEMBER,
        'joined_at' => now(),
    ]);

    $this->actingAs($member)
        ->getJson(route('groups.dashboard.members.activity-summary', [$group, $target]))
        ->assertForbidden();
});

function assignCurrentSlotForMemberCount(Activity $activity, Character $character): void
{
    $activity->slots()
        ->where('group_key', '!=', ActivitySlotBench::GROUP_KEY)
        ->orderBy('sort_order')
        ->firstOrFail()
        ->update([
            'assigned_character_id' => $character->id,
            'assigned_by_user_id' => $character->user_id,
        ]);
}

function createAssignmentForMemberCount(
    Activity $activity,
    Character $character,
    string $attendanceStatus,
    ?Carbon $endedAt = null,
    bool $isBench = false,
    array $snapshot = [],
): ActivitySlotAssignment {
    $slot = $isBench
        ? ActivitySlot::factory()->create([
            'activity_id' => $activity->id,
            'group_key' => ActivitySlotBench::GROUP_KEY,
            'slot_key' => ActivitySlotBench::GROUP_KEY.'-test-slot',
        ])
        : ($activity->slots()->where('group_key', '!=', ActivitySlotBench::GROUP_KEY)->first()
            ?? ActivitySlot::factory()->create(['activity_id' => $activity->id]));

    return ActivitySlotAssignment::query()->create([
        'activity_id' => $activity->id,
        'group_id' => $activity->group_id,
        'activity_slot_id' => $slot->id,
        'character_id' => $character->id,
        'attendance_status' => $attendanceStatus,
        'assigned_at' => $activity->starts_at ?? now(),
        'ended_at' => $endedAt,
        'field_values_snapshot' => $snapshot,
    ]);
}
