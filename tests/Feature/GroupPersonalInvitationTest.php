<?php

use App\Events\UserNotificationsUpdated;
use App\Models\AuditLog;
use App\Models\DiscordUserIntegration;
use App\Models\Group;
use App\Models\GroupInvite;
use App\Models\GroupMembership;
use App\Models\IntegrationClient;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Groups\GroupPersonalInvitationService;
use App\Services\Notifications\NotificationMessageRenderer;
use App\Support\Integrations\IntegrationPermissions;
use App\Support\Quotas\QuotaKey;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Event::fake([UserNotificationsUpdated::class]);
    Http::preventStrayRequests();
    $this->group = Group::factory()->create(['join_mode' => Group::JOIN_MODE_INVITE_ONLY]);
    $this->recipient = User::factory()->create();
    $this->invitations = app(GroupPersonalInvitationService::class);
});

it('sends a single account-bound 72-hour invitation with one notification and audit on repeated requests', function () {
    $this->travelTo(now()->startOfSecond());
    $url = route('groups.personal-invites.store', $this->group);
    $response = $this->actingAs($this->group->owner)->postJson($url, ['recipient_user_id' => $this->recipient->id])->assertOk();
    $invite = GroupInvite::findOrFail($response->json('data.id'));
    $this->postJson($url, ['recipient_user_id' => $this->recipient->id])->assertOk()->assertJsonPath('data.id', $invite->id);
    expect($invite->recipient_user_id)->toBe($this->recipient->id)
        ->and($invite->max_uses)->toBe(1)
        ->and($invite->expires_at->equalTo(now()->addHours(72)))->toBeTrue()
        ->and(GroupInvite::whereNotNull('recipient_user_id')->count())->toBe(1)
        ->and(AuditLog::where('action', 'group.personal_invite.sent')->count())->toBe(1);
    $event = NotificationEvent::where('type', 'groups.personal_invite')->sole();
    expect(UserNotification::where('notification_event_id', $event->id)->pluck('user_id')->all())->toBe([$this->recipient->id])
        ->and($event->action_url)->toBe(route('groups.invites.show', $invite->token))
        ->and(AuditLog::where('action', 'group.personal_invite.sent')->sole()->metadata['recipient_user_id'])->toBe($this->recipient->id);
});

it('requires sign-in and refuses forwarded personal links without exposing group details', function () {
    $invite = $this->invitations->send($this->group, $this->recipient, $this->group->owner);
    $this->get(route('groups.invites.show', $invite->token))->assertRedirect(route('login', ['invite' => $invite->token]));
    $this->actingAs(User::factory()->create())->get(route('groups.invites.show', $invite->token))->assertNotFound();
    $this->postJson(route('groups.invites.accept', $invite->token))->assertNotFound();
    $this->postJson(route('groups.invites.decline', $invite->token))->assertNotFound();
    $this->actingAs($this->recipient)->get(route('groups.invites.show', $invite->token))->assertInertia(fn (Assert $page) => $page
        ->component('Groups/Invite')->where('invite.is_personal', true)->where('invite.status', 'pending')->where('invite.can_accept', true));
    expect($invite->fresh()->uses)->toBe(0);
});

it('allows only owners admins and moderators to send invitations', function (string $role, bool $allowed) {
    $actor = $role === 'owner' ? $this->group->owner : User::factory()->create();
    if (in_array($role, ['admin', 'moderator', 'member'])) {
        $this->group->memberships()->create(['user_id' => $actor->id, 'role' => $role, 'joined_at' => now()]);
    }
    $response = $this->actingAs($actor)->postJson(route('groups.personal-invites.store', $this->group), ['recipient_user_id' => $this->recipient->id]);
    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with([['owner', true], ['admin', true], ['moderator', true], ['member', false], ['outsider', false]]);

it('accepts once and preserves quota idempotency but does not let a used link rejoin after leaving', function () {
    config()->set('quotas.mode', 'enforce');
    config()->set('quotas.limits', [...config('quotas.limits'), QuotaKey::GROUPS_JOINED => 1]);
    $invite = $this->invitations->send($this->group, $this->recipient, $this->group->owner);
    $this->actingAs($this->recipient)->post(route('groups.invites.accept', $invite->token))->assertRedirect(route('groups.dashboard', $this->group));
    $this->post(route('groups.invites.accept', $invite->token))->assertSessionDoesntHaveErrors();
    expect($this->group->memberships()->where('user_id', $this->recipient->id)->count())->toBe(1)
        ->and($invite->fresh()->status)->toBe(GroupInvite::STATUS_ACCEPTED)
        ->and($invite->fresh()->uses)->toBe(1)
        ->and(AuditLog::where('action', 'group.personal_invite.accepted')->count())->toBe(1)
        ->and(AuditLog::where('action', 'group.member.joined_via_invite')->where('actor_user_id', $this->recipient->id)->count())->toBe(1);
    $this->group->memberships()->where('user_id', $this->recipient->id)->delete();
    $this->postJson(route('groups.invites.accept', $invite->token))->assertUnprocessable()->assertJsonValidationErrors('invite');
});

it('consumes a valid invitation without duplicating an existing membership', function () {
    $invite = $this->invitations->send($this->group, $this->recipient, $this->group->owner);
    $this->group->memberships()->create(['user_id' => $this->recipient->id, 'role' => GroupMembership::ROLE_MEMBER]);
    $this->invitations->accept($invite, $this->recipient);
    expect($invite->fresh()->status)->toBe(GroupInvite::STATUS_ACCEPTED)
        ->and($this->group->memberships()->where('user_id', $this->recipient->id)->count())->toBe(1);
});

it('checks current bans before accepting a previously issued invitation', function () {
    $invite = $this->invitations->send($this->group, $this->recipient, $this->group->owner);
    $this->group->bans()->create(['user_id' => $this->recipient->id, 'banned_by_user_id' => $this->group->owner_id]);
    $this->actingAs($this->recipient)->postJson(route('groups.invites.accept', $invite->token))->assertUnprocessable();
    expect($invite->fresh()->status)->toBe(GroupInvite::STATUS_PENDING)
        ->and($this->group->hasMember($this->recipient->id))->toBeFalse();
});

it('rejects members and banned accounts at send time', function (string $reason) {
    if ($reason === 'member') {
        $this->group->memberships()->create(['user_id' => $this->recipient->id, 'role' => 'member']);
    }
    if ($reason === 'group_ban') {
        $this->group->bans()->create(['user_id' => $this->recipient->id, 'banned_by_user_id' => $this->group->owner_id]);
    }
    if ($reason === 'account_ban') {
        $this->recipient->forceFill(['banned_at' => now()])->save();
    }
    $this->actingAs($this->group->owner)->postJson(route('groups.personal-invites.store', $this->group), ['recipient_user_id' => $this->recipient->id])->assertUnprocessable();
    expect(GroupInvite::whereNotNull('recipient_user_id')->count())->toBe(0);
})->with(['member', 'group_ban', 'account_ban']);

it('persists a decline idempotently and makes the link unusable', function () {
    $invite = $this->invitations->send($this->group, $this->recipient, $this->group->owner);
    $this->actingAs($this->recipient)->postJson(route('groups.invites.decline', $invite->token))->assertOk();
    $this->postJson(route('groups.invites.decline', $invite->token))->assertOk();
    $this->postJson(route('groups.invites.accept', $invite->token))->assertUnprocessable();
    $this->get(route('groups.invites.show', $invite->token))->assertInertia(fn (Assert $page) => $page->where('invite.status', 'declined')->where('invite.can_accept', false));
    expect($invite->fresh()->declined_at)->not->toBeNull()
        ->and(AuditLog::where('action', 'group.personal_invite.declined')->count())->toBe(1);
});

it('revokes personal invitations while retaining their audit history and enforcing group boundaries', function () {
    $invite = $this->invitations->send($this->group, $this->recipient, $this->group->owner);
    $otherGroup = Group::factory()->create(['owner_id' => $this->group->owner_id]);
    $this->actingAs($this->group->owner)->delete(route('groups.invites.destroy', [$otherGroup, $invite]))->assertNotFound();
    $this->delete(route('groups.invites.destroy', [$this->group, $invite]))->assertRedirect();
    expect($invite->fresh()->status)->toBe(GroupInvite::STATUS_REVOKED);
    $this->actingAs($this->recipient)->postJson(route('groups.invites.accept', $invite->token))->assertUnprocessable();
});

it('expires at exactly 72 hours and resends with a new link without reviving the old one', function () {
    $this->freezeTime();
    $invite = $this->invitations->send($this->group, $this->recipient, $this->group->owner);
    $this->actingAs($this->group->owner)->postJson(route('groups.invites.resend', [$this->group, $invite]))->assertUnprocessable();
    $this->travelTo($invite->expires_at);
    $this->actingAs($this->recipient)->postJson(route('groups.invites.accept', $invite->token))->assertUnprocessable();
    $response = $this->actingAs($this->group->owner)->postJson(route('groups.invites.resend', [$this->group, $invite]))->assertOk();
    $new = GroupInvite::findOrFail($response->json('data.id'));
    $this->postJson(route('groups.invites.resend', [$this->group, $invite]))->assertOk()->assertJsonPath('data.id', $new->id);
    expect($new->token)->not->toBe($invite->token)
        ->and($new->expires_at->equalTo(now()->addHours(72)))->toBeTrue()
        ->and($invite->fresh()->status)->toBe(GroupInvite::STATUS_EXPIRED);
    $this->actingAs($this->recipient)->postJson(route('groups.invites.accept', $invite->token))->assertUnprocessable();
    $this->post(route('groups.invites.accept', $new->token))->assertSessionDoesntHaveErrors();
});

it('enforces invitation and membership quotas without charging duplicate sends or declined invitations', function () {
    config()->set('quotas.mode', 'enforce');
    config()->set('quotas.limits', [...config('quotas.limits'), QuotaKey::ACTIVE_INVITES => 1, QuotaKey::GROUPS_JOINED => 1]);
    Group::factory()->create()->memberships()->create(['user_id' => $this->recipient->id, 'role' => 'member']);
    $invite = $this->invitations->send($this->group, $this->recipient, $this->group->owner);
    expect($this->invitations->send($this->group, $this->recipient, $this->group->owner)->id)->toBe($invite->id);
    $this->actingAs($this->recipient)->postJson(route('groups.invites.accept', $invite->token))->assertUnprocessable();
    $other = User::factory()->create();
    $this->actingAs($this->group->owner)->postJson(route('groups.personal-invites.store', $this->group), ['recipient_user_id' => $other->id])->assertUnprocessable();
    $this->invitations->decline($invite, $this->recipient);
    $this->postJson(route('groups.personal-invites.store', $this->group), ['recipient_user_id' => $other->id])->assertOk();
});

it('cannot turn a personal link into a public link when its recipient is deleted', function () {
    $invite = $this->invitations->send($this->group, $this->recipient, $this->group->owner);
    $this->recipient->delete();
    expect(GroupInvite::find($invite->id))->toBeNull();
    $this->get(route('groups.invites.show', $invite->token))->assertNotFound();
});

it('enforces one pending personal invitation per group and account at database level', function () {
    $invite = $this->invitations->send($this->group, $this->recipient, $this->group->owner);
    expect(fn () => DB::transaction(fn () => $invite->replicate()->fill(['token' => GroupInvite::generateUniqueToken()])->save()))->toThrow(QueryException::class);
});

it('has localized recipient and notification copy in every supported language', function (string $locale) {
    expect(__('group_invitations.unavailable', [], $locale))->not->toBe('group_invitations.unavailable')
        ->and(__('group_invitations.notification_title', [], $locale))->not->toBe('group_invitations.notification_title');
})->with(['en', 'de', 'fr', 'ja']);

it('uses notification preferences and renders invitation details through the standard renderer', function () {
    $this->recipient->update(['group_update_notifications' => false]);
    $this->invitations->send($this->group, $this->recipient, $this->group->owner);
    $event = NotificationEvent::where('type', 'groups.personal_invite')->sole();
    expect(UserNotification::where('notification_event_id', $event->id)->exists())->toBeFalse();
    $message = app(NotificationMessageRenderer::class)->render($event, $this->recipient, 'en');
    expect($message['subject'])->toContain($this->group->name)->not->toContain('{group}')
        ->and($message['body'])->toContain('72 hours');
});

it('enforces the recipient binding through the member API as well', function () {
    $invite = $this->invitations->send($this->group, $this->recipient, $this->group->owner);
    $outsider = User::factory()->create();
    DiscordUserIntegration::create(['user_id' => $outsider->id, 'discord_user_id' => '123456789012345678', 'user_app_installed_at' => now()]);
    DiscordUserIntegration::create(['user_id' => $this->recipient->id, 'discord_user_id' => '123456789012345679', 'user_app_installed_at' => now()]);
    $token = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()->withApiToken($token)->create(['scopes' => [IntegrationPermissions::MEMBERS_WRITE]]);
    $headers = ['Authorization' => 'Bearer '.$token, 'X-FullParty-Discord-User-Id' => '123456789012345678'];
    $this->postJson('/api/integrations/v1/invites/'.$invite->token.'/accept', [], $headers)->assertNotFound();
    $headers['X-FullParty-Discord-User-Id'] = '123456789012345679';
    $this->postJson('/api/integrations/v1/invites/'.$invite->token.'/accept', [], $headers)->assertOk();
    expect($invite->fresh()->status)->toBe(GroupInvite::STATUS_ACCEPTED);
});
