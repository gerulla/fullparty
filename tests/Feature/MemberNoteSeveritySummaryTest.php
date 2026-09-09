<?php

use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupUserNote;
use App\Models\User;
use App\Services\Groups\GroupUserNoteVisibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->group = Group::factory()->create(['owner_id' => $this->owner->id]);
    $this->group->memberships()->create(['user_id' => $this->member->id, 'role' => GroupMembership::ROLE_MEMBER, 'joined_at' => now()]);
    $this->visibility = app(GroupUserNoteVisibilityService::class);
    $this->addNote = fn ($severity, $group = null, $shared = false) => GroupUserNote::create([
        'group_id' => ($group ?? $this->group)->id, 'user_id' => $this->member->id,
        'author_user_id' => $this->owner->id, 'severity' => $severity,
        'body' => 'Sample context.', 'is_shared_with_groups' => $shared,
    ]);
    $this->summary = function ($viewer = null, $target = null) {
        $viewer ??= $this->owner;
        $target ??= $this->member;
        $notes = $this->visibility->loadVisibleNotesForTargets($this->group, $viewer->id, collect([$target->id]));

        return $this->visibility->serializeVisibleNoteSummaryForUser(
            $this->group, $target, $viewer->id, $notes['group_notes_by_user_id'], $notes['shared_notes_by_user_id'],
        );
    };
});

it('chooses the highest visible non-info severity', function ($severities, $expected) {
    foreach ($severities as $severity) {
        ($this->addNote)($severity);
    }
    $summary = ($this->summary)();
    expect($summary['highest_severity'])->toBe($expected);
    expect($summary['current_group_count'])->toBe(count($severities));
    expect($summary['severities'])->toBe(array_values(array_intersect(['info', 'commendation', 'warning', 'critical'], $severities)));
})->with([
    'empty' => [[], null],
    'info only' => [['info'], null],
    'commendation' => [['info', 'commendation'], 'commendation'],
    'warning' => [['warning'], 'warning'],
    'warning over commendation' => [['warning', 'commendation', 'info'], 'warning'],
    'critical' => [['critical'], 'critical'],
    'critical over all' => [['critical', 'warning', 'commendation', 'info'], 'critical'],
    'order independent' => [['info', 'commendation', 'warning', 'critical'], 'critical'],
    'repeated severities' => [['info', 'info', 'warning', 'warning', 'warning', 'critical'], 'critical'],
]);

it('includes shared notes but excludes private notes from other groups', function () {
    $otherGroup = Group::factory()->create();
    ($this->addNote)('commendation');
    ($this->addNote)('critical', $otherGroup);
    expect(($this->summary)()['highest_severity'])->toBe('commendation');
    expect(($this->summary)()['severities'])->toBe(['commendation']);
    ($this->addNote)('warning', $otherGroup, true);
    expect(($this->summary)()['highest_severity'])->toBe('warning');
    expect(($this->summary)()['severities'])->toBe(['commendation', 'warning']);
    ($this->addNote)('critical', $otherGroup, true);
    expect(($this->summary)()['highest_severity'])->toBe('critical');
    expect(($this->summary)()['severities'])->toBe(['commendation', 'warning', 'critical']);
});

it('does not expose severity for unauthorized viewers or self-notes', function () {
    ($this->addNote)('critical');
    expect(($this->summary)($this->member)['highest_severity'])->toBeNull();
    expect(($this->summary)($this->owner, $this->owner)['highest_severity'])->toBeNull();
    expect($this->visibility->emptyVisibleNoteSummary()['highest_severity'])->toBeNull();
    expect(($this->summary)($this->member)['severities'])->toBe([]);
    expect(($this->summary)($this->owner, $this->owner)['severities'])->toBe([]);
    expect($this->visibility->emptyVisibleNotes()['severities'])->toBe([]);
});

it('downgrades the indicator after note edits and deletes and includes it in the detail payload', function () {
    $critical = ($this->addNote)('critical');
    ($this->addNote)('commendation');
    $critical->update(['severity' => 'info']);
    expect(($this->summary)()['highest_severity'])->toBe('commendation');
    $notes = $this->visibility->loadVisibleNotesForTargets($this->group, $this->owner->id, collect([$this->member->id]));
    $payload = $this->visibility->serializeVisibleNotesForUser(
        $this->group, $this->member, $this->owner->id, $notes['group_notes_by_user_id'], $notes['shared_notes_by_user_id'],
    );
    expect($payload['highest_severity'])->toBe('commendation');
    expect($payload['severities'])->toBe(['info', 'commendation']);
    GroupUserNote::where('severity', 'commendation')->delete();
    expect(($this->summary)()['highest_severity'])->toBeNull();
    expect(($this->summary)()['severities'])->toBe(['info']);
});
