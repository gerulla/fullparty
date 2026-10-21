<?php

use App\Events\ActivityManagementUpdated;
use App\Events\UserNotificationsUpdated;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivityGroupFollowUp;
use App\Models\ActivityTypeVersion;
use App\Models\AuditLog;
use App\Models\Character;
use App\Models\Group;
use App\Models\GroupInvite;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Groups\ActivityApplicationCharacterRefreshService;
use App\Services\Groups\ActivityCompletionService;
use App\Services\Groups\ActivitySlotAttendanceService;
use App\Services\Groups\GroupPersonalInvitationService;
use App\Services\Notifications\ApplicationNotificationService;
use App\Services\Notifications\AssignmentNotificationService;
use App\Services\Notifications\GroupUpdateNotificationService;
use App\Services\Notifications\RunNotificationService;
use App\Support\Quotas\QuotaKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Event::fake([ActivityManagementUpdated::class, UserNotificationsUpdated::class]);
    Http::preventStrayRequests();
    $this->mock(ActivityApplicationCharacterRefreshService::class)->shouldReceive('refreshSelectedCharacterIfDue')->andReturn([
        'refreshed' => false, 'available_at' => null, 'character' => null, 'fflogs_error' => null,
    ]);
    $this->group = Group::factory()->create(['join_mode' => Group::JOIN_MODE_INVITE_ONLY]);
    $version = ActivityTypeVersion::factory()->create([
        'application_schema' => [], 'slot_schema' => [], 'progress_schema' => ['milestones' => []], 'bench_size' => 0,
        'layout_schema' => ['groups' => [['key' => 'party-a', 'label' => ['en' => 'Party A'], 'size' => 1]]],
    ]);
    $this->run = Activity::factory()->create([
        'group_id' => $this->group->id, 'activity_type_version_id' => $version->id,
        'status' => Activity::STATUS_SCHEDULED, 'is_public' => true, 'needs_application' => true,
    ]);
    $this->participant = User::factory()->create();
    $this->character = Character::factory()->create(['user_id' => $this->participant->id, 'verified_at' => now()]);
});

it('takes a nonmember from public application through attendance completion and a private invitation into membership', function () {
    $this->actingAs($this->participant)->post(route('groups.activities.application.store', [$this->group, $this->run]), [
        'selected_character_id' => $this->character->id, 'answers' => [],
    ])->assertSessionDoesntHaveErrors();
    $application = ActivityApplication::where('activity_id', $this->run->id)->sole();
    expect($application->user_id)->toBe($this->participant->id)
        ->and($this->group->fresh()->hasMember($this->participant->id))->toBeFalse();

    $slot = $this->run->slots()->firstOrFail();
    $this->actingAs($this->group->owner)->postJson(route('groups.dashboard.activities.slot-assignments.store', [$this->group, $this->run, $slot]), [
        'application_id' => $application->id, 'field_values' => [], 'expected_slot_state_token' => activity_slot_state_token($slot),
    ])->assertOk();
    $this->run->update(['status' => Activity::STATUS_ONGOING]);
    app(ActivitySlotAttendanceService::class)->checkInSlot($slot->fresh(), $this->group->owner_id);
    $this->postJson(route('groups.dashboard.activities.complete', [$this->group, $this->run]), [])->assertOk();
    $this->postJson(route('groups.dashboard.activities.complete', [$this->group, $this->run]), [])->assertForbidden();
    expect(GroupInvite::whereNotNull('recipient_user_id')->count())->toBe(0)
        ->and(NotificationEvent::where('type', 'runs.completed')->count())->toBe(1);

    $summary = $this->getJson(route('groups.activities.follow-up.show', [$this->group, $this->run]))->assertOk()->json('data');
    expect(array_column($summary['candidates'], 'user_id'))->toBe([$this->participant->id]);
    $this->postJson($summary['send_url'], ['user_ids' => [$this->participant->id]])->assertOk();
    $event = NotificationEvent::where('type', 'groups.personal_invite')->sole();
    $notification = UserNotification::where('notification_event_id', $event->id)->sole();
    expect($notification->user_id)->toBe($this->participant->id);
    $this->actingAs(User::factory()->create())->get(route('account.notifications.open', $notification))->assertNotFound();
    $this->actingAs($this->participant)->get(route('account.notifications.open', $notification))->assertRedirect();
    $invite = GroupInvite::where('source_activity_id', $this->run->id)->sole();
    $this->get(route('groups.invites.show', $invite->token))->assertOk();
    $this->post(route('groups.invites.accept', $invite->token))->assertRedirect(route('groups.dashboard', $this->group));
    expect($this->group->fresh()->hasMember($this->participant->id))->toBeTrue()
        ->and($invite->fresh()->status)->toBe(GroupInvite::STATUS_ACCEPTED)
        ->and(AuditLog::where('action', 'group.personal_invite.sent')->sole()->metadata['activity_id'])->toBe($this->run->id)
        ->and(AuditLog::where('action', 'group.personal_invite.accepted')->sole()->actor_user_id)->toBe($this->participant->id);
});

it('rechecks membership for quota idempotency even when the caller cached an old invite group', function () {
    config()->set('quotas.mode', 'enforce');
    config()->set('quotas.limits', [...config('quotas.limits'), QuotaKey::GROUPS_JOINED => 1]);
    $service = app(GroupPersonalInvitationService::class);
    $invite = $service->send($this->group, $this->participant, $this->group->owner);
    $invite->load('group.memberships');
    $service->accept($invite, $this->participant);
    $service->accept($invite, $this->participant);
    expect($this->group->memberships()->where('user_id', $this->participant->id)->count())->toBe(1);
});

it('does not send current run details to former members or banned participants', function (string $reason) {
    $application = ActivityApplication::factory()->create([
        'activity_id' => $this->run->id, 'user_id' => $this->participant->id,
        'selected_character_id' => $this->character->id, 'status' => ActivityApplication::STATUS_APPROVED,
    ]);
    $this->run->update(['is_public' => $reason !== 'former_member', 'status' => Activity::STATUS_COMPLETE]);
    if ($reason === 'former_member') {
        $this->group->memberships()->where('user_id', $this->participant->id)->delete();
    }
    if ($reason === 'group_ban') {
        $this->group->bans()->create(['user_id' => $this->participant->id, 'banned_by_user_id' => $this->group->owner_id]);
    }
    if ($reason === 'account_ban') {
        $this->participant->forceFill(['banned_at' => now()])->save();
    }
    app(RunNotificationService::class)->notifyCompleted($this->run->fresh(), $this->group->owner);
    expect(UserNotification::where('user_id', $this->participant->id)->count())->toBe(0)
        ->and(NotificationDelivery::where('user_id', $this->participant->id)->count())->toBe(0);
})->with(['former_member', 'group_ban', 'account_ban']);

it('still sends public run updates to participating nonmembers of invite-only groups', function () {
    ActivityApplication::factory()->create([
        'activity_id' => $this->run->id, 'user_id' => $this->participant->id,
        'selected_character_id' => $this->character->id, 'status' => ActivityApplication::STATUS_APPROVED,
    ]);
    $this->group->memberships()->where('user_id', $this->participant->id)->delete();
    $this->run->update(['status' => Activity::STATUS_COMPLETE]);
    app(RunNotificationService::class)->notifyCompleted($this->run->fresh(), $this->group->owner);
    $event = NotificationEvent::where('type', 'runs.completed')->sole();
    expect(UserNotification::where('notification_event_id', $event->id)->pluck('user_id')->all())->toBe([$this->participant->id]);
});

it('keeps banned group members out of new run announcements', function () {
    $this->group->memberships()->create(['user_id' => $this->participant->id, 'role' => 'member']);
    $this->group->bans()->create(['user_id' => $this->participant->id, 'banned_by_user_id' => $this->group->owner_id]);
    app(GroupUpdateNotificationService::class)->notifyRunCreated($this->run, $this->group->owner);
    expect(UserNotification::where('user_id', $this->participant->id)->count())->toBe(0);
});

it('makes personal invitation audit actions translatable and searchable in every locale', function (string $locale) {
    app(GroupPersonalInvitationService::class)->send($this->group, $this->participant, $this->group->owner);
    $key = 'audit_log.events.group.personal_invite.sent';
    $label = __($key, [], $locale);
    expect($label)->not->toBe($key)
        ->and(__('audit_log.activity.group.personal_invite.sent', [], $locale))->not->toBe('audit_log.activity.group.personal_invite.sent');
    $this->actingAs($this->group->owner)->getJson(route('groups.dashboard.audit-log', [
        'locale' => $locale, 'group' => $this->group, 'search' => $label,
    ]))->assertOk()->assertJsonCount(1, 'auditLogs')->assertJsonPath('auditLogs.0.action', 'group.personal_invite.sent');
})->with(['en', 'de', 'fr', 'ja']);

it('keeps completion followups audit and notifications in one transaction', function () {
    $this->run->update(['status' => Activity::STATUS_ONGOING]);
    $this->mock(RunNotificationService::class)->shouldReceive('notifyCompleted')->andThrow(new RuntimeException('Simulated notification persistence failure'));
    expect(fn () => app(ActivityCompletionService::class)->completeAndNotify($this->run, [], $this->group->owner))->toThrow(RuntimeException::class);
    expect($this->run->fresh()->status)->toBe(Activity::STATUS_ONGOING)
        ->and($this->run->fresh()->group_follow_up_prepared_at)->toBeNull()
        ->and(ActivityGroupFollowUp::where('activity_id', $this->run->id)->exists())->toBeFalse()
        ->and(AuditLog::where('metadata->activity_id', $this->run->id)->exists())->toBeFalse();
});

it('does not repeat completion notifications when a stale completion request is replayed', function () {
    ActivityApplication::factory()->create([
        'activity_id' => $this->run->id, 'user_id' => $this->participant->id,
        'selected_character_id' => $this->character->id, 'status' => ActivityApplication::STATUS_APPROVED,
    ]);
    $this->run->update(['status' => Activity::STATUS_ONGOING]);
    $stale = $this->run->fresh();
    $service = app(ActivityCompletionService::class);
    $service->completeAndNotify($this->run, [], $this->group->owner);
    $service->completeAndNotify($stale, [], $this->group->owner);
    expect(NotificationEvent::where('type', 'runs.completed')->count())->toBe(1);
});

it('rechecks run access when a queued roster notification is handled', function (bool $manual, bool $public) {
    $application = ActivityApplication::factory()->create([
        'activity_id' => $this->run->id, 'user_id' => $this->participant->id,
        'selected_character_id' => $this->character->id, 'status' => ActivityApplication::STATUS_APPROVED,
    ]);
    $this->run->update(['is_public' => $public, 'status' => Activity::STATUS_ASSIGNED]);
    $slot = $this->run->slots()->firstOrFail();
    $slot->update(['assigned_character_id' => $this->character->id]);
    $this->group->memberships()->where('user_id', $this->participant->id)->delete();

    app(AssignmentNotificationService::class)->sendRosterPublishedNotification(
        $slot->id, $manual ? null : $application->id, $manual ? $this->character->id : null, $this->group->owner_id,
    );
    expect(UserNotification::where('user_id', $this->participant->id)->count())->toBe($public ? 1 : 0);
})->with([false, true])->with([false, true]);

it('does not send new applicant details to a banned organizer', function () {
    $this->run->update(['organized_by_user_id' => $this->group->owner_id]);
    $application = ActivityApplication::factory()->create([
        'activity_id' => $this->run->id, 'user_id' => $this->participant->id,
        'selected_character_id' => $this->character->id,
    ]);
    $this->group->owner->forceFill(['banned_at' => now()])->save();
    app(ApplicationNotificationService::class)->notifySubmitted($application, $this->participant);
    expect(UserNotification::where('user_id', $this->group->owner_id)->count())->toBe(0);
});

it('cannot complete a run cancelled after the request loaded it', function () {
    $this->run->update(['status' => Activity::STATUS_ONGOING]);
    $stale = $this->run->fresh();
    $this->run->update(['status' => Activity::STATUS_CANCELLED]);
    expect(fn () => app(ActivityCompletionService::class)->completeAndNotify($stale, [], $this->group->owner))
        ->toThrow(HttpException::class);
    expect($this->run->fresh()->status)->toBe(Activity::STATUS_CANCELLED)
        ->and($this->run->fresh()->group_follow_up_prepared_at)->toBeNull();
});
