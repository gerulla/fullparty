<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\Character;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupUserNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    Character::factory()->primary()->create(['user_id' => $this->owner->id]);
    $this->group = Group::factory()->open()->create(['owner_id' => $this->owner->id]);
    $this->activity = Activity::factory()->create(['group_id' => $this->group->id]);
    $this->applicant = User::factory()->create();
    $this->application = ActivityApplication::factory()->create([
        'activity_id' => $this->activity->id,
        'user_id' => $this->applicant->id,
    ]);
    $this->notesUrl = route('groups.dashboard.activities.applicant-queue.application-notes', [
        'group' => $this->group->slug,
        'activity' => $this->activity->id,
        'application' => $this->application->id,
    ]);
});

it('shows group and explicitly shared notes with addenda for a nonmember applicant', function () {
    $this->group->memberships()->where('user_id', $this->applicant->id)->delete();
    $note = GroupUserNote::create([
        'group_id' => $this->group->id, 'user_id' => $this->applicant->id,
        'author_user_id' => $this->owner->id, 'severity' => 'warning',
        'body' => 'Group context', 'is_shared_with_groups' => false,
    ]);
    $note->addenda()->create(['author_user_id' => $this->owner->id, 'body' => 'Follow-up context']);
    $otherGroup = Group::factory()->create();
    foreach ([true, false] as $shared) {
        GroupUserNote::create([
            'group_id' => $otherGroup->id, 'user_id' => $this->applicant->id,
            'author_user_id' => $this->owner->id, 'severity' => 'info',
            'body' => $shared ? 'Shared context' : 'Private context', 'is_shared_with_groups' => $shared,
        ]);
    }
    $this->actingAs($this->owner)->getJson($this->notesUrl)->assertOk()
        ->assertJsonPath('notes.can_view', true)
        ->assertJsonCount(1, 'notes.current_group')
        ->assertJsonPath('notes.current_group.0.addenda.0.body', 'Follow-up context')
        ->assertJsonCount(1, 'notes.shared')
        ->assertJsonPath('notes.shared.0.body', 'Shared context')
        ->assertJsonPath('notes.shared.0.source_group.id', $otherGroup->id)
        ->assertJsonMissing(['body' => 'Private context']);
});

it('allows group moderators to inspect notes', function () {
    $moderator = User::factory()->create();
    Character::factory()->primary()->create(['user_id' => $moderator->id]);
    $this->group->memberships()->create(['user_id' => $moderator->id, 'role' => GroupMembership::ROLE_MODERATOR, 'joined_at' => now()]);
    $this->actingAs($moderator)->getJson($this->notesUrl)->assertOk()->assertJsonPath('notes.can_view', true);
});

it('forbids ordinary group members from reading applicant notes', function () {
    $member = User::factory()->create();
    Character::factory()->primary()->create(['user_id' => $member->id]);
    $this->group->memberships()->create(['user_id' => $member->id, 'role' => GroupMembership::ROLE_MEMBER, 'joined_at' => now()]);
    $this->actingAs($member)->getJson($this->notesUrl)->assertForbidden();
});

it('keeps notes about the current reviewer hidden', function () {
    $this->application->update(['user_id' => $this->owner->id]);
    $this->actingAs($this->owner)->getJson($this->notesUrl)->assertOk()
        ->assertJsonPath('notes.can_view', false)->assertJsonCount(0, 'notes.current_group')->assertJsonCount(0, 'notes.shared');
});

it('returns no player notes for guest applications', function () {
    $this->application->update(['user_id' => null]);
    $this->actingAs($this->owner)->getJson($this->notesUrl)->assertOk()->assertJsonPath('notes.can_view', false);
});

it('rejects applications belonging to a different activity', function () {
    $this->application->update(['activity_id' => Activity::factory()->create(['group_id' => $this->group->id])->id]);
    $this->actingAs($this->owner)->getJson($this->notesUrl)->assertNotFound();
});

it('rejects mismatched group and activity pairs', function () {
    $this->activity->update(['group_id' => Group::factory()->create()->id]);
    $this->actingAs($this->owner)->getJson($this->notesUrl)->assertNotFound();
});
