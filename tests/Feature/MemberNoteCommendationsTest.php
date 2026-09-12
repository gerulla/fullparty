<?php

use App\Models\AuditLog;
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
    $this->member = User::factory()->create();
    $this->group->memberships()->create([
        'user_id' => $this->member->id, 'role' => GroupMembership::ROLE_MEMBER, 'joined_at' => now(),
    ]);
});

it('creates and displays a commendation and records an informational audit event', function () {
    $this->actingAs($this->owner)->post(route('groups.members.notes.store', [$this->group, $this->member]), [
        'severity' => 'commendation', 'body' => 'Patient and helpful with new players.', 'is_shared_with_groups' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $note = GroupUserNote::query()->sole();
    expect($note->severity)->toBe(GroupUserNote::SEVERITY_COMMENDATION);
    $this->getJson(route('groups.members.notes.show', [$this->group, $this->member]))
        ->assertOk()->assertJsonPath('member.notes.current_group.0.severity', 'commendation');
    $audit = AuditLog::query()->where('action', 'group.member.note.created')->sole();
    expect($audit->severity)->toBe('info');
    expect($audit->metadata['note_severity'])->toBe('commendation');
});

it('allows authors to change an existing note to and from a commendation', function () {
    $note = GroupUserNote::create([
        'group_id' => $this->group->id, 'user_id' => $this->member->id, 'author_user_id' => $this->owner->id,
        'severity' => 'info', 'body' => 'Original context.', 'is_shared_with_groups' => false,
    ]);
    foreach (['commendation', 'info'] as $severity) {
        $this->actingAs($this->owner)->put(route('groups.members.notes.update', [$this->group, $note]), [
            'severity' => $severity, 'body' => 'Updated context.', 'is_shared_with_groups' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();
        expect($note->fresh()->severity)->toBe($severity);
    }
    expect(AuditLog::query()->where('action', 'group.member.note.updated')->count())->toBe(2);
});

it('shares only explicitly shared commendations from other groups', function () {
    $other = Group::factory()->create();
    foreach ([true, false] as $shared) {
        GroupUserNote::create([
            'group_id' => $other->id, 'user_id' => $this->member->id, 'author_user_id' => $this->owner->id,
            'severity' => 'commendation', 'body' => $shared ? 'Shared praise.' : 'Private praise.', 'is_shared_with_groups' => $shared,
        ]);
    }
    $this->actingAs($this->owner)->getJson(route('groups.members.notes.show', [$this->group, $this->member]))
        ->assertOk()->assertJsonCount(1, 'member.notes.shared')
        ->assertJsonPath('member.notes.shared.0.severity', 'commendation')
        ->assertJsonPath('member.notes.shared.0.body', 'Shared praise.')
        ->assertJsonMissing(['body' => 'Private praise.']);
});

it('does not allow ordinary members to create commendations', function () {
    Character::factory()->primary()->create(['user_id' => $this->member->id]);
    $this->actingAs($this->member)->post(route('groups.members.notes.store', [$this->group, $this->owner]), [
        'severity' => 'commendation', 'body' => 'Praise.',
    ])->assertForbidden();
    $this->assertDatabaseCount('group_user_notes', 0);
});
