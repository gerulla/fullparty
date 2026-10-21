<?php

namespace App\Services\Groups;

use App\DTOs\QuotaCheck;
use App\Models\Activity;
use App\Models\ActivityGroupFollowUp;
use App\Models\Group;
use App\Models\GroupInvite;
use App\Models\GroupMembership;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Notifications\GroupInvitationNotificationService;
use App\Services\Notifications\GroupUpdateNotificationService;
use App\Services\Quotas\QuotaService;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use App\Support\Quotas\QuotaKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GroupPersonalInvitationService
{
    public function __construct(
        private readonly QuotaService $quotas,
        private readonly ActivityFollowUpParticipants $participants,
        private readonly AuditLogger $audit,
        private readonly GroupInvitationNotificationService $notifications,
        private readonly GroupUpdateNotificationService $groupNotifications,
    ) {}

    public function authorizeSender(Group $group, User $actor): void
    {
        abort_unless($actor->banned_at === null && ! $group->isBanned($actor->id) && $group->hasModeratorAccess($actor->id), 403);
    }

    public function send(Group $group, User $recipient, User $actor, ?Activity $source = null): GroupInvite
    {
        $this->authorizeSender($group, $actor);

        return $this->quotas->runIf([
            new QuotaCheck(QuotaKey::ACTIVE_INVITES, $group),
        ], fn () => ! $this->pending($group, $recipient)?->canBeAccepted(), function () use ($group, $recipient, $actor, $source) {
            $group = $group->fresh();
            $recipient = $recipient->fresh();
            $this->authorizeSender($group, $actor->fresh());
            if (! $recipient || ! $this->participants->canJoin($group, $recipient)) {
                $this->invalid('ineligible');
            }
            if ($source !== null) {
                abort_unless((int) $source->group_id === (int) $group->id, 404);
                if ($group->join_mode !== Group::JOIN_MODE_INVITE_ONLY
                    || ! $this->participants->eligible($source->fresh(), ActivityGroupFollowUp::INVITE_CANDIDATE, $recipient->id)->has($recipient->id)) {
                    $this->invalid('ineligible');
                }
                // Respect a decline from this run instead of treating another POST as a resend.
                if ($group->invites()->where('recipient_user_id', $recipient->id)->where('source_activity_id', $source->id)
                    ->where('status', GroupInvite::STATUS_DECLINED)->exists()) {
                    $this->invalid('declined');
                }
            }

            $existing = $this->pending($group, $recipient);
            if ($existing?->canBeAccepted()) {
                return $existing;
            }
            if ($existing) {
                $existing->update(['status' => GroupInvite::STATUS_EXPIRED]);
            }

            $invite = $group->invites()->create([
                'created_by' => $actor->id,
                'recipient_user_id' => $recipient->id,
                'source_activity_id' => $source?->id,
                'token' => GroupInvite::generateUniqueToken(),
                'max_uses' => 1,
                'expires_at' => now()->addHours(72),
                'is_system' => false,
                'status' => GroupInvite::STATUS_PENDING,
            ]);
            $this->log($invite, $actor, 'sent', ['replaces_invite_id' => $existing?->id]);
            $this->notifications->sent($invite, $actor);

            return $invite;
        });
    }

    public function resend(Group $group, GroupInvite $invite, User $actor): GroupInvite
    {
        abort_unless($invite->isPersonal() && (int) $invite->group_id === (int) $group->id, 404);
        $this->authorizeSender($group, $actor);
        if (! $invite->isExpired() || ! in_array($invite->status, [GroupInvite::STATUS_PENDING, GroupInvite::STATUS_EXPIRED], true)) {
            $this->invalid('resend_expired_only');
        }

        return $this->send($group, $invite->recipient, $actor, $invite->sourceActivity);
    }

    public function accept(GroupInvite $invite, User $recipient): Group
    {
        $this->authorizeRecipient($invite, $recipient);

        return $this->quotas->runIf([
            new QuotaCheck(QuotaKey::GROUPS_JOINED, $recipient),
        ], fn () => ! $invite->group()->firstOrFail()->hasMember($recipient->id), function () use ($invite, $recipient) {
            $invite = GroupInvite::query()->lockForUpdate()->findOrFail($invite->id);
            $this->authorizeRecipient($invite, $recipient);
            $group = $invite->group;
            if ($recipient->fresh()->banned_at !== null || $group->isBanned($recipient->id)) {
                $this->invalid('banned');
            }
            if ($invite->status === GroupInvite::STATUS_ACCEPTED && $group->hasMember($recipient->id)) {
                return $group;
            }
            if (! $invite->canBeAccepted()) {
                $this->invalid('unavailable');
            }

            $alreadyMember = $group->hasMember($recipient->id);
            if (! $alreadyMember) {
                $group->memberships()->create([
                    'user_id' => $recipient->id, 'role' => GroupMembership::ROLE_MEMBER, 'joined_at' => now(),
                ]);
            }
            $invite->update(['status' => GroupInvite::STATUS_ACCEPTED, 'accepted_at' => now(), 'uses' => 1]);
            $this->log($invite, $recipient, 'accepted', ['joined' => ! $alreadyMember]);
            if (! $alreadyMember) {
                $this->audit->log(
                    action: 'group.member.joined_via_invite', severity: AuditSeverity::INFO,
                    scopeType: AuditScope::GROUP, scopeId: $group->id,
                    message: 'audit_log.events.group.member.joined_via_invite', actor: $recipient, subject: $recipient,
                    metadata: ['invite_id' => $invite->id, 'recipient_user_id' => $recipient->id, 'activity_id' => $invite->source_activity_id],
                );
                $this->groupNotifications->notifyMemberJoined($group->fresh(), $recipient, $recipient);
            }

            return $group;
        });
    }

    public function decline(GroupInvite $invite, User $recipient): void
    {
        $this->authorizeRecipient($invite, $recipient);
        DB::transaction(function () use ($invite, $recipient) {
            $invite = GroupInvite::query()->lockForUpdate()->findOrFail($invite->id);
            $this->authorizeRecipient($invite, $recipient);
            if ($invite->status === GroupInvite::STATUS_DECLINED) {
                return;
            }
            if (! $invite->canBeAccepted()) {
                $this->invalid('unavailable');
            }
            $invite->update(['status' => GroupInvite::STATUS_DECLINED, 'declined_at' => now()]);
            $this->log($invite, $recipient, 'declined');
        });
    }

    public function revoke(Group $group, GroupInvite $invite, User $actor): void
    {
        abort_unless($invite->isPersonal() && (int) $invite->group_id === (int) $group->id, 404);
        $this->authorizeSender($group, $actor);
        DB::transaction(function () use ($invite, $actor) {
            $invite = GroupInvite::query()->lockForUpdate()->findOrFail($invite->id);
            $this->authorizeSender($invite->group, $actor->fresh());
            if ($invite->status !== GroupInvite::STATUS_PENDING) {
                return;
            }
            $invite->update(['status' => GroupInvite::STATUS_REVOKED, 'revoked_at' => now()]);
            $this->log($invite, $actor, 'revoked');
        });
    }

    public function authorizeRecipient(GroupInvite $invite, User $recipient): void
    {
        abort_unless($invite->isPersonal() && (int) $invite->recipient_user_id === (int) $recipient->id, 404);
    }

    private function pending(Group $group, User $recipient): ?GroupInvite
    {
        return $group->invites()->where('recipient_user_id', $recipient->id)->where('status', GroupInvite::STATUS_PENDING)->first();
    }

    private function invalid(string $reason): never
    {
        throw ValidationException::withMessages(['invite' => __('group_invitations.'.$reason)]);
    }

    private function log(GroupInvite $invite, User $actor, string $event, array $metadata = []): void
    {
        $this->audit->log(
            action: 'group.personal_invite.'.$event, severity: AuditSeverity::INFO,
            scopeType: AuditScope::GROUP, scopeId: $invite->group_id,
            message: 'audit_log.events.group.personal_invite.'.$event, actor: $actor, subject: $invite,
            metadata: [...$metadata, 'invite_id' => $invite->id, 'recipient_user_id' => $invite->recipient_user_id,
                'created_by_user_id' => $invite->created_by, 'activity_id' => $invite->source_activity_id,
                'expires_at' => $invite->expires_at?->toIso8601String()],
        );
    }
}
