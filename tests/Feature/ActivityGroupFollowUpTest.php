<?php

use App\Events\UserNotificationsUpdated;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivityGroupFollowUp;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use App\Models\Character;
use App\Models\Group;
use App\Models\GroupInvite;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Groups\ActivityCompletionService;
use App\Services\Groups\ActivityGroupFollowUpService;
use App\Services\Groups\GroupPersonalInvitationService;
use App\Support\Quotas\QuotaKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Event::fake([UserNotificationsUpdated::class]);
    Http::preventStrayRequests();
    $this->group = Group::factory()->create(['join_mode' => Group::JOIN_MODE_INVITE_ONLY]);
    $this->activity = Activity::factory()->create(['group_id' => $this->group->id, 'status' => Activity::STATUS_ONGOING, 'is_public' => true]);
    $this->followUps = app(ActivityGroupFollowUpService::class);
});

function followUpAttendee(Activity $activity, ?User $user = null, string $attendance = 'checked_in', string $kind = 'roster', ?string $applicationStatus = null): ActivitySlotAssignment
{
    $user ??= User::factory()->create();
    $character = Character::factory()->create(['user_id' => $user->id]);
    $slot = ActivitySlot::factory()->create([
        'activity_id' => $activity->id, 'slot_key' => 'follow-up-'.$character->id,
        'slot_kind' => $kind, 'assigned_character_id' => $character->id,
    ]);
    $application = $applicationStatus === null ? null : ActivityApplication::query()->create([
        'activity_id' => $activity->id, 'user_id' => $user->id,
        'selected_character_id' => $character->id, 'status' => $applicationStatus,
    ]);

    return ActivitySlotAssignment::create([
        'activity_id' => $activity->id, 'group_id' => $activity->group_id,
        'activity_slot_id' => $slot->id, 'character_id' => $character->id,
        'application_id' => $application?->id, 'attendance_status' => $attendance,
        'assigned_at' => now()->subHour(), 'checked_in_at' => $attendance === 'assigned' ? null : now(),
    ]);
}

function finishFollowUpRun(Activity $activity): void
{
    app(ActivityCompletionService::class)->complete($activity, [], $activity->group->owner_id);
}

it('prepares followups through both completion paths once without automatically sending invitations', function (bool $milestones) {
    $assignment = followUpAttendee($this->activity);
    if (! $milestones) {
        $this->activity->progressMilestones()->delete();
    } else {
        $this->activity->progressMilestones()->firstOrCreate(['milestone_key' => 'test'], ['milestone_label' => ['en' => 'Test'], 'sort_order' => 99, 'kills' => 0]);
    }
    finishFollowUpRun($this->activity);
    $count = ActivityGroupFollowUp::count();
    finishFollowUpRun($this->activity->fresh());
    expect($this->activity->fresh()->group_follow_up_prepared_at)->not->toBeNull()
        ->and(ActivityGroupFollowUp::count())->toBe($count)
        ->and(ActivityGroupFollowUp::where('kind', ActivityGroupFollowUp::INVITE_CANDIDATE)->count())->toBe(1)
        ->and(GroupInvite::whereNotNull('recipient_user_id')->count())->toBe(0)
        ->and(NotificationEvent::where('type', 'groups.personal_invite')->count())->toBe(0);
    $entry = ActivityGroupFollowUp::where('kind', ActivityGroupFollowUp::INVITE_CANDIDATE)->sole();
    expect($entry->user_id)->toBe($assignment->character->user_id)->and($entry->characters[0]['name'])->toBe($assignment->character->name);
})->with([false, true]);

it('includes checked-in late and fill-in attendees but excludes unused benches no-shows and withdrawn applications', function (string $attendance, string $kind, ?string $applicationStatus, bool $eligible) {
    $assignment = followUpAttendee($this->activity, attendance: $attendance, kind: $kind, applicationStatus: $applicationStatus);
    finishFollowUpRun($this->activity);
    expect(ActivityGroupFollowUp::where('user_id', $assignment->character->user_id)->where('kind', ActivityGroupFollowUp::INVITE_CANDIDATE)->exists())->toBe($eligible);
})->with([
    ['checked_in', 'roster', null, true], ['late', 'roster', 'assigned', true], ['checked_in', 'fill_in', null, true],
    ['assigned', 'roster', null, false], ['missing', 'roster', null, false], ['assigned', 'bench', null, false],
    ['checked_in', 'bench', null, false], ['checked_in', 'roster', 'withdrawn', false], ['checked_in', 'roster', 'declined', false],
]);

it('excludes members banned accounts and anonymous guests even when their characters have account owners', function () {
    $member = User::factory()->create();
    $banned = User::factory()->create();
    $globalBan = User::factory()->create(['banned_at' => now()]);
    $this->group->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
    $this->group->bans()->create(['user_id' => $banned->id, 'banned_by_user_id' => $this->group->owner_id]);
    foreach ([$member, $banned, $globalBan] as $user) {
        followUpAttendee($this->activity, $user);
    }
    $guest = followUpAttendee($this->activity, applicationStatus: 'assigned');
    $guest->application->update(['user_id' => null]);
    finishFollowUpRun($this->activity);
    expect(ActivityGroupFollowUp::count())->toBe(0);
});

it('deduplicates accounts and uses the latest assignment for each character', function () {
    $user = User::factory()->create();
    $first = followUpAttendee($this->activity, $user);
    followUpAttendee($this->activity, $user, 'late');
    $first->replicate()->fill(['attendance_status' => 'checked_in', 'assigned_at' => now()])->save();
    $missing = followUpAttendee($this->activity);
    $missing->replicate()->fill(['attendance_status' => 'missing', 'assigned_at' => now()])->save();
    finishFollowUpRun($this->activity);
    $entry = ActivityGroupFollowUp::where('kind', ActivityGroupFollowUp::INVITE_CANDIDATE)->sole();
    expect($entry->user_id)->toBe($user->id)->and($entry->characters)->toHaveCount(2)->and($entry->assignment_ids)->toHaveCount(2);
});

it('snapshots the participating account so later character transfers cannot redirect invitations', function () {
    $assignment = followUpAttendee($this->activity);
    $original = $assignment->character->user;
    finishFollowUpRun($this->activity);
    $newOwner = User::factory()->create();
    $assignment->character->update(['user_id' => $newOwner->id]);
    $summary = $this->followUps->forRun($this->group, $this->activity->fresh(), $this->group->owner);
    expect(array_column($summary['candidates'], 'user_id'))->toBe([$original->id]);
    $this->followUps->send($this->group, $this->activity->fresh(), $this->group->owner, [$original->id]);
    expect(GroupInvite::whereNotNull('recipient_user_id')->sole()->recipient_user_id)->toBe($original->id);
});

it('offers optional open-group joining only to eligible attendees and consumes the automatic prompt once', function () {
    $this->group->update(['join_mode' => Group::JOIN_MODE_OPEN]);
    $assignment = followUpAttendee($this->activity);
    $viewer = $assignment->character->user;
    finishFollowUpRun($this->activity);
    $url = route('groups.activities.follow-up.show', [$this->group, $this->activity]);
    $summary = $this->actingAs($viewer)->getJson($url)->assertOk()->assertJsonPath('data.kind', 'join_group')->assertJsonPath('data.should_prompt', true)->json('data');
    $this->getJson($url)->assertJsonPath('data.should_prompt', true);
    $this->getJson(route('account.run-follow-ups.index'))->assertJsonCount(1, 'data');
    $this->postJson($summary['presented_url'])->assertOk()->assertJsonPath('show_prompt', true);
    $this->postJson($summary['presented_url'])->assertOk()->assertJsonPath('show_prompt', false);
    $this->getJson(route('account.run-follow-ups.index'))->assertJsonCount(0, 'data');
    $this->postJson($summary['dismiss_url'])->assertOk();
    $this->getJson($url)->assertJsonPath('data.should_prompt', false)->assertJsonPath('data.join_url', route('groups.join', $this->group));
    $this->actingAs(User::factory()->create())->getJson($url)->assertJsonPath('data', null);
    $this->postJson($summary['presented_url'])->assertNotFound();
    $this->actingAs($viewer)->post($summary['join_url'])->assertSessionDoesntHaveErrors();
    $this->getJson($url)->assertJsonPath('data', null);
});

it('lets authorized hosts dismiss and reopen selection without showing the automatic prompt again', function () {
    followUpAttendee($this->activity);
    finishFollowUpRun($this->activity);
    $url = route('groups.activities.follow-up.show', [$this->group, $this->activity]);
    $summary = $this->actingAs($this->group->owner)->getJson($url)->assertJsonPath('data.should_prompt', true)->json('data');
    $this->postJson($summary['dismiss_url'])->assertOk();
    $this->postJson($summary['presented_url'])->assertJsonPath('show_prompt', false);
    finishFollowUpRun($this->activity->fresh());
    $this->getJson($url)->assertJsonPath('data.should_prompt', false)->assertJsonCount(1, 'data.candidates');
    $this->getJson(route('account.run-follow-ups.index'))->assertJsonCount(0, 'data');
});

it('sends only explicitly selected attendees and deduplicates repeated sends', function () {
    $selected = followUpAttendee($this->activity)->character->user;
    $unselected = followUpAttendee($this->activity)->character->user;
    finishFollowUpRun($this->activity);
    $url = route('groups.activities.follow-up.invites.store', [$this->group, $this->activity]);
    $first = $this->actingAs($this->group->owner)->postJson($url, ['user_ids' => [$selected->id]])->assertOk()->assertJsonCount(1, 'data')->json('data.0.id');
    $this->postJson($url, ['user_ids' => [$selected->id]])->assertOk()->assertJsonPath('data.0.id', $first);
    expect(GroupInvite::whereNotNull('recipient_user_id')->count())->toBe(1)
        ->and(GroupInvite::where('recipient_user_id', $unselected->id)->exists())->toBeFalse()
        ->and(NotificationEvent::where('type', 'groups.personal_invite')->count())->toBe(1);
});

it('does not let host designation grant group invitation authority or expose candidate lists', function () {
    $host = followUpAttendee($this->activity)->character->user;
    $this->activity->slots()->whereHas('assignedCharacter', fn ($q) => $q->where('user_id', $host->id))->update(['is_host' => true]);
    $attendee = followUpAttendee($this->activity)->character->user;
    finishFollowUpRun($this->activity);
    $this->actingAs($host)->getJson(route('groups.activities.follow-up.show', [$this->group, $this->activity]))->assertJsonPath('data', null);
    $this->postJson(route('groups.activities.follow-up.invites.store', [$this->group, $this->activity]), ['user_ids' => [$attendee->id]])->assertForbidden();
    expect(ActivityGroupFollowUp::where('user_id', $host->id)->where('kind', ActivityGroupFollowUp::INVITE_PARTICIPANTS)->exists())->toBeFalse();
});

it('rechecks candidate membership bans withdrawal and attendance before sending', function (string $change) {
    $assignment = followUpAttendee($this->activity, applicationStatus: 'assigned');
    $recipient = $assignment->character->user;
    finishFollowUpRun($this->activity);
    if ($change === 'member') {
        $this->group->memberships()->create(['user_id' => $recipient->id, 'role' => 'member']);
    }
    if ($change === 'ban') {
        $this->group->bans()->create(['user_id' => $recipient->id, 'banned_by_user_id' => $this->group->owner_id]);
    }
    if ($change === 'withdrawn') {
        $assignment->application->update(['status' => 'withdrawn']);
    }
    if ($change === 'missing') {
        $assignment->update(['attendance_status' => 'missing']);
    }
    $this->actingAs($this->group->owner)->postJson(route('groups.activities.follow-up.invites.store', [$this->group, $this->activity]), ['user_ids' => [$recipient->id]])->assertUnprocessable();
    $this->getJson(route('account.run-follow-ups.index'))->assertJsonCount(0, 'data');
    expect(GroupInvite::whereNotNull('recipient_user_id')->count())->toBe(0);
})->with(['member', 'ban', 'withdrawn', 'missing']);

it('rejects unrelated accounts mismatched groups and uncompleted runs', function () {
    $attendee = followUpAttendee($this->activity)->character->user;
    $url = route('groups.activities.follow-up.invites.store', [$this->group, $this->activity]);
    $this->actingAs($this->group->owner)->postJson($url, ['user_ids' => [$attendee->id]])->assertNotFound();
    finishFollowUpRun($this->activity);
    $this->postJson($url, ['user_ids' => [User::factory()->create()->id]])->assertUnprocessable();
    $other = Group::factory()->create(['owner_id' => $this->group->owner_id]);
    $this->postJson(route('groups.activities.follow-up.invites.store', [$other, $this->activity]), ['user_ids' => [$attendee->id]])->assertNotFound();
    $this->getJson(route('groups.activities.follow-up.show', [$other, $this->activity]))->assertNotFound();
});

it('rolls back the entire selected batch including notifications if its quota is exceeded', function () {
    $one = followUpAttendee($this->activity)->character->user;
    $two = followUpAttendee($this->activity)->character->user;
    finishFollowUpRun($this->activity);
    config()->set('quotas.mode', 'enforce');
    config()->set('quotas.limits', [...config('quotas.limits'), QuotaKey::ACTIVE_INVITES => 1]);
    $this->actingAs($this->group->owner)->postJson(route('groups.activities.follow-up.invites.store', [$this->group, $this->activity]), ['user_ids' => [$one->id, $two->id]])->assertUnprocessable();
    expect(GroupInvite::whereNotNull('recipient_user_id')->count())->toBe(0)
        ->and(NotificationEvent::where('type', 'groups.personal_invite')->count())->toBe(0)
        ->and(UserNotification::whereIn('user_id', [$one->id, $two->id])->count())->toBe(0)
        ->and(ActivityGroupFollowUp::where('kind', ActivityGroupFollowUp::INVITE_PARTICIPANTS)->sole()->dismissed_at)->toBeNull();
});

it('respects declined invitations from the same run and existing pending invitations from another run', function () {
    $recipient = followUpAttendee($this->activity)->character->user;
    finishFollowUpRun($this->activity);
    $service = app(GroupPersonalInvitationService::class);
    $generic = $service->send($this->group, $recipient, $this->group->owner);
    $url = route('groups.activities.follow-up.invites.store', [$this->group, $this->activity]);
    $this->actingAs($this->group->owner)->postJson($url, ['user_ids' => [$recipient->id]])->assertOk()->assertJsonPath('data.0.id', $generic->id);
    $service->revoke($this->group, $generic, $this->group->owner);
    $this->postJson($url, ['user_ids' => [$recipient->id]])->assertOk();
    $invite = GroupInvite::where('source_activity_id', $this->activity->id)->sole();
    $service->decline($invite, $recipient);
    $this->postJson($url, ['user_ids' => [$recipient->id]])->assertUnprocessable();
    $this->getJson(route('groups.activities.follow-up.show', [$this->group, $this->activity]))->assertJsonPath('data.candidates.0.can_invite', false);
});

it('removes host prompts after permission is lost and requires authentication for the feed', function () {
    $mod = User::factory()->create();
    $membership = $this->group->memberships()->create(['user_id' => $mod->id, 'role' => 'moderator']);
    $this->activity->update(['organized_by_user_id' => $mod->id]);
    followUpAttendee($this->activity);
    finishFollowUpRun($this->activity);
    $this->getJson(route('account.run-follow-ups.index'))->assertUnauthorized();
    $this->actingAs($mod)->getJson(route('account.run-follow-ups.index'))->assertJsonCount(1, 'data');
    $membership->update(['role' => 'member']);
    $this->getJson(route('account.run-follow-ups.index'))->assertJsonCount(0, 'data');
});

it('integrates with the actual completion endpoint without sending membership invitations', function () {
    followUpAttendee($this->activity, applicationStatus: 'assigned');
    $this->actingAs($this->group->owner)->postJson(route('groups.dashboard.activities.complete', [$this->group, $this->activity]), [])->assertOk()->assertJsonPath('status', 'completed');
    expect(ActivityGroupFollowUp::where('kind', ActivityGroupFollowUp::INVITE_CANDIDATE)->count())->toBe(1)
        ->and(GroupInvite::whereNotNull('recipient_user_id')->count())->toBe(0);
});

it('keeps followups invisible when the viewer loses run access', function () {
    $this->group->update(['join_mode' => Group::JOIN_MODE_OPEN]);
    $viewer = followUpAttendee($this->activity)->character->user;
    finishFollowUpRun($this->activity);
    $prompt = ActivityGroupFollowUp::where('user_id', $viewer->id)->sole();
    $this->group->bans()->create(['user_id' => $viewer->id, 'banned_by_user_id' => $this->group->owner_id]);
    $this->actingAs($viewer)->getJson(route('account.run-follow-ups.index'))->assertJsonCount(0, 'data');
    $this->getJson(route('groups.activities.follow-up.show', [$this->group, $this->activity]))->assertNotFound();
    $this->postJson(route('account.run-follow-ups.presented', $prompt))->assertNotFound();
});

it('does not automatically replay historical completion prompts', function () {
    $this->activity->update(['status' => Activity::STATUS_COMPLETE, 'completed_at' => now()->subWeek()]);
    followUpAttendee($this->activity);
    $this->actingAs($this->group->owner)->getJson(route('groups.activities.follow-up.show', [$this->group, $this->activity]))->assertJsonPath('data', null);
    $this->getJson(route('account.run-follow-ups.index'))->assertJsonCount(0, 'data');
});
