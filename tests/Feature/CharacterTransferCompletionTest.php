<?php

use App\Events\ActivityManagementUpdated;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlotAssignment;
use App\Models\AuditLog;
use App\Models\Character;
use App\Models\CharacterTransfer;
use App\Models\Group;
use App\Models\User;
use App\Services\Characters\CharacterOwnershipTransferService;
use App\Services\Characters\CharacterTransferCompletionService;
use App\Services\Dashboard\HomeBannerDataService;
use App\Services\Dashboard\V2HomeDataService;
use App\Services\Groups\ActivitySlotAttendanceService;
use App\Services\Groups\CharacterActivityHistoryService;
use App\Services\Groups\GroupMemberActivitySummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()->instance('env', 'local');
    Queue::fake();
    Event::fake([ActivityManagementUpdated::class]);
    Http::preventStrayRequests();
    $this->owner = User::factory()->create();
    $this->recipient = User::factory()->create();
    $this->character = Character::factory()->for($this->owner)->unverified()->create();
    $this->group = Group::factory()->create(['is_visible' => true]);
    $this->history = Activity::factory()->for($this->group)->create(['status' => 'complete', 'starts_at' => now()->subDay()]);
    $this->upcoming = Activity::factory()->for($this->group)->create(['status' => 'assigned', 'starts_at' => now()->addDay()]);
    $makeApp = fn ($activity) => ActivityApplication::factory()->create([
        'activity_id' => $activity->id, 'user_id' => $this->owner->id,
        'selected_character_id' => $this->character->id, 'applicant_lodestone_id' => $this->character->lodestone_id,
        'status' => 'approved', 'notes' => 'PRIVATE ORIGINAL NOTE', 'review_reason' => 'PRIVATE REVIEW',
    ]);
    $this->pastApp = $makeApp($this->history);
    $this->futureApp = $makeApp($this->upcoming);
    $this->futureApp->answers()->create(['question_key' => 'private', 'question_label' => ['en' => 'Private'], 'question_type' => 'text', 'value' => 'PRIVATE ANSWER']);
    $this->slot = $this->upcoming->slots()->firstOrFail();
    $this->slot->update(['assigned_character_id' => $this->character->id]);
    $this->assignment = ActivitySlotAssignment::create([
        'activity_id' => $this->upcoming->id, 'group_id' => $this->group->id, 'activity_slot_id' => $this->slot->id,
        'character_id' => $this->character->id, 'application_id' => $this->futureApp->id,
        'attendance_status' => 'assigned', 'assigned_at' => now(),
    ]);
    $this->actingAs($this->recipient)->withSession(['_token' => 'transfer-completion'])
        ->withHeader('X-CSRF-TOKEN', 'transfer-completion');
});

function pendingCharacterTransfer($test): CharacterTransfer
{
    DB::transaction(fn () => app(CharacterOwnershipTransferService::class)->transfer($test->character, $test->recipient, 'xivauth'));

    return CharacterTransfer::sole();
}

it('persists an unresolved step and resumes it on the claim page without leaking private data', function () {
    $transfer = pendingCharacterTransfer($this);
    expect($transfer->completed_at)->toBeNull()->and($transfer->runs()->count())->toBe(2);
    $summary = app(CharacterTransferCompletionService::class)->summary($transfer, $this->recipient);
    expect(json_encode($summary))->not->toContain('PRIVATE')->and($summary['pending'])->toBeTrue();
    expect(collect($summary['runs'])->pluck('category')->sort()->values()->all())->toBe(['history', 'upcoming']);
    $this->get(route('characters.claim', $this->character))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('transfer.id', $transfer->id)->where('transfer.pending', true));
    expect(app(V2HomeDataService::class)->applicationHistory($this->recipient))->toBe([]);
});

it('offers links only when the actual overview policy allows them', function () {
    $transfer = pendingCharacterTransfer($this);
    $this->group->activities()->update(['is_public' => false]);
    $summary = app(CharacterTransferCompletionService::class)->summary($transfer, $this->recipient);
    expect(collect($summary['runs'])->pluck('url')->all())->toBe([null, null]);
    $this->group->activities()->update(['is_public' => true]);
    $summary = app(CharacterTransferCompletionService::class)->summary($transfer->fresh(), $this->recipient);
    expect(collect($summary['runs'])->every(fn ($run) => str_contains($run['url'], '/activities/')))->toBeTrue();
    $this->upcoming->update(['status' => 'draft']);
    $summary = app(CharacterTransferCompletionService::class)->summary($transfer->fresh(), $this->recipient);
    expect(collect($summary['runs'])->firstWhere('category', 'upcoming')['url'])->toBeNull();
});

it('keeps or discards each category independently without reparenting private records', function (string $history, string $upcoming) {
    $transfer = pendingCharacterTransfer($this);
    $this->postJson(route('characters.transfer.complete', $transfer), compact('history', 'upcoming'))->assertOk();
    expect($transfer->fresh()->completed_at)->not->toBeNull()
        ->and($transfer->fresh()->history_choice)->toBe($history)
        ->and($this->pastApp->fresh()->user_id)->toBe($this->owner->id)
        ->and($this->futureApp->fresh()->user_id)->toBe($this->owner->id)
        ->and($this->futureApp->fresh()->notes)->toBe('PRIVATE ORIGINAL NOTE')
        ->and($this->futureApp->fresh()->review_reason)->toBe('PRIVATE REVIEW')
        ->and($this->futureApp->fresh()->status)->toBe('withdrawn')
        ->and($this->assignment->fresh()->ended_at)->not->toBeNull();
    $newApp = ActivityApplication::where('user_id', $this->recipient->id)->first();
    if ($upcoming === 'keep') {
        expect($newApp)->not->toBeNull()->and($newApp->notes)->toBeNull()->and($newApp->review_reason)->toBeNull()
            ->and($newApp->answers()->count())->toBe(0)->and($newApp->status)->toBe('approved')
            ->and($newApp->created_at->equalTo($this->futureApp->created_at))->toBeTrue()
            ->and($this->slot->fresh()->assigned_character_id)->toBe($this->character->id);
        $this->assertDatabaseHas('activity_slot_assignments', ['application_id' => $newApp->id, 'ended_at' => null]);
    } else {
        expect($newApp)->toBeNull()->and($this->slot->fresh()->assigned_character_id)->toBeNull();
    }
    $historyItems = app(V2HomeDataService::class)->applicationHistory($this->recipient);
    expect(count($historyItems))->toBe($history === 'keep' ? 1 : 0);
    if ($history === 'keep') {
        expect($historyItems[0]['href'])->toContain('/activities/')->not->toContain('/apply');
    }
    $this->postJson(route('characters.transfer.complete', $transfer), compact('history', 'upcoming'))->assertOk();
    expect(AuditLog::where('action', 'character.transfer_completed')->count())->toBe(1);
    Event::assertDispatched(ActivityManagementUpdated::class);
})->with([['keep', 'keep'], ['keep', 'discard'], ['discard', 'keep'], ['discard', 'discard']]);

it('requires both explicit choices and authorizes the current receiving account', function () {
    $transfer = pendingCharacterTransfer($this);
    $this->postJson(route('characters.transfer.complete', $transfer), ['history' => 'keep'])
        ->assertUnprocessable()->assertJsonValidationErrors('upcoming');
    $this->postJson(route('characters.transfer.complete', $transfer), ['history' => 'later', 'upcoming' => 'keep'])
        ->assertUnprocessable()->assertJsonValidationErrors('history');
    $this->actingAs($this->owner)->postJson(route('characters.transfer.complete', $transfer), ['history' => 'keep', 'upcoming' => 'keep'])->assertNotFound();
    expect($transfer->fresh()->completed_at)->toBeNull();
});

it('rolls back every choice when the receiving account already applied to the same run', function () {
    $transfer = pendingCharacterTransfer($this);
    $other = Character::factory()->for($this->recipient)->create();
    ActivityApplication::factory()->create(['activity_id' => $this->upcoming->id, 'user_id' => $this->recipient->id,
        'selected_character_id' => $other->id, 'applicant_lodestone_id' => $other->lodestone_id]);
    $this->postJson(route('characters.transfer.complete', $transfer), ['history' => 'keep', 'upcoming' => 'keep'])
        ->assertUnprocessable()->assertJsonPath('errors.transfer.0', 'duplicate');
    expect($transfer->fresh()->completed_at)->toBeNull()->and($transfer->runs()->whereNotNull('choice')->count())->toBe(0)
        ->and($this->futureApp->fresh()->status)->toBe('approved')->and($this->assignment->fresh()->ended_at)->toBeNull();
});

it('blocks inherited participation in members-only runs without membership', function () {
    $transfer = pendingCharacterTransfer($this);
    $this->upcoming->update(['is_public' => false]);
    $this->postJson(route('characters.transfer.complete', $transfer), ['history' => 'keep', 'upcoming' => 'keep'])
        ->assertUnprocessable()->assertJsonPath('errors.transfer.0', 'ineligible');
    $this->postJson(route('characters.transfer.complete', $transfer), ['history' => 'keep', 'upcoming' => 'discard'])->assertOk();
});

it('does not clear a replacement player or edit a run that has since completed', function (bool $completed) {
    $transfer = pendingCharacterTransfer($this);
    if ($completed) {
        $this->upcoming->update(['status' => 'complete']);
        $expected = $this->character->id;
    } else {
        $expected = Character::factory()->create()->id;
        $this->assignment->update(['ended_at' => now()]);
        $this->slot->update(['assigned_character_id' => $expected]);
    }
    $this->postJson(route('characters.transfer.complete', $transfer), ['history' => 'discard', 'upcoming' => 'discard'])->assertOk();
    expect($this->slot->fresh()->assigned_character_id)->toBe($expected);
})->with([true, false]);

it('honours the history decision for progression and member summaries without removing roster records', function (string $choice) {
    $pastSlot = $this->history->slots()->firstOrFail();
    $pastSlot->update(['assigned_character_id' => $this->character->id]);
    ActivitySlotAssignment::create([
        'activity_id' => $this->history->id, 'group_id' => $this->group->id, 'activity_slot_id' => $pastSlot->id,
        'character_id' => $this->character->id, 'application_id' => $this->pastApp->id,
        'attendance_status' => 'checked_in', 'assigned_at' => now()->subDays(2),
    ]);
    $transfer = pendingCharacterTransfer($this);
    $progress = app(CharacterActivityHistoryService::class);
    $members = app(GroupMemberActivitySummaryService::class);
    expect($progress->completedRuns($this->history, $this->character->id))->toHaveCount(0);
    expect($members->forMember($this->group, $this->recipient)['recent_runs'])->toHaveCount(0);
    $this->postJson(route('characters.transfer.complete', $transfer), ['history' => $choice, 'upcoming' => 'discard'])->assertOk();
    expect($progress->completedRuns($this->history, $this->character->id))->toHaveCount($choice === 'keep' ? 1 : 0);
    expect($members->forMember($this->group, $this->recipient)['recent_runs'])->toHaveCount($choice === 'keep' ? 1 : 0);
    $banner = app(HomeBannerDataService::class)->detailsForUser($this->recipient);
    expect($banner['last_run'] !== null)->toBe($choice === 'keep');
    expect($pastSlot->fresh()->assigned_character_id)->toBe($this->character->id);
})->with(['keep', 'discard']);

it('handles manual assignments and clears designations when discarding them', function (string $choice) {
    $this->futureApp->update(['status' => 'withdrawn']);
    $this->assignment->update(['application_id' => null, 'assignment_source' => 'manual']);
    $this->slot->update(['is_raid_leader' => true]);
    $transfer = pendingCharacterTransfer($this);
    $this->postJson(route('characters.transfer.complete', $transfer), ['history' => 'discard', 'upcoming' => $choice])->assertOk();
    expect($this->slot->fresh()->assigned_character_id)->toBe($choice === 'keep' ? $this->character->id : null);
    expect((bool) $this->slot->fresh()->is_raid_leader)->toBe($choice === 'keep');
    expect(ActivityApplication::where('user_id', $this->recipient->id)->count())->toBe(0);
})->with(['keep', 'discard']);

it('blocks banned recipients even when a group is public and hides the summary links', function () {
    $transfer = pendingCharacterTransfer($this);
    $this->group->bans()->create(['user_id' => $this->recipient->id, 'banned_by_user_id' => $this->owner->id, 'reason' => 'Test']);
    $summary = app(CharacterTransferCompletionService::class)->summary($transfer, $this->recipient);
    expect(collect($summary['runs'])->pluck('url')->all())->toBe([null, null]);
    $this->postJson(route('characters.transfer.complete', $transfer), ['history' => 'keep', 'upcoming' => 'keep'])
        ->assertUnprocessable()->assertJsonPath('errors.transfer.0', 'ineligible');
});

it('does not revive a withdrawn signup or touch a new assignment made after the transfer', function () {
    $transfer = pendingCharacterTransfer($this);
    $this->assignment->update(['ended_at' => now()]);
    $this->futureApp->update(['status' => 'withdrawn']);
    $this->slot->update(['assigned_character_id' => null]);
    $newSlot = $this->upcoming->slots()->whereKeyNot($this->slot->id)->firstOrFail();
    $newSlot->update(['assigned_character_id' => $this->character->id]);
    app(ActivitySlotAttendanceService::class)
        ->moveOrCreateActiveAssignment($newSlot, $this->character->id, null, $this->owner->id);
    $this->postJson(route('characters.transfer.complete', $transfer), ['history' => 'discard', 'upcoming' => 'discard'])->assertOk();
    expect($newSlot->fresh()->assigned_character_id)->toBe($this->character->id);
    expect(ActivityApplication::where('user_id', $this->recipient->id)->count())->toBe(0);
});

it('requires no fabricated decision when there are no records but still records both choices', function () {
    $this->character = Character::factory()->for($this->owner)->unverified()->create();
    $transfer = pendingCharacterTransfer($this);
    expect($transfer->runs)->toHaveCount(0);
    $this->postJson(route('characters.transfer.complete', $transfer), ['history' => 'keep', 'upcoming' => 'discard'])->assertOk();
    expect($transfer->fresh()->completed_at)->not->toBeNull();
});

it('does not allow an already completed decision to be changed or a former recipient to submit it', function () {
    $transfer = pendingCharacterTransfer($this);
    $this->postJson(route('characters.transfer.complete', $transfer), ['history' => 'keep', 'upcoming' => 'keep'])->assertOk();
    $this->postJson(route('characters.transfer.complete', $transfer), ['history' => 'discard', 'upcoming' => 'discard'])
        ->assertUnprocessable()->assertJsonPath('errors.transfer.0', 'already_completed');
    $this->character->update(['user_id' => $this->owner->id]);
    $this->postJson(route('characters.transfer.complete', $transfer), ['history' => 'keep', 'upcoming' => 'keep'])->assertNotFound();
});
