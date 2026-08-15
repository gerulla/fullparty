<?php

namespace App\Http\Controllers;

use App\DTOs\QuotaCheck;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\ScheduledRun;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Notifications\GroupUpdateNotificationService;
use App\Services\Notifications\NotificationPreferenceSettingsService;
use App\Services\Quotas\QuotaService;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use App\Support\Input\RequestTextInputSanitizer;
use App\Support\Quotas\QuotaKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GroupMembershipController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly GroupUpdateNotificationService $groupUpdateNotificationService,
        private readonly NotificationPreferenceSettingsService $notificationPreferenceSettingsService,
        private readonly RequestTextInputSanitizer $requestTextInputSanitizer,
        private readonly QuotaService $quotaService,
    ) {}

    public function join(Group $group): RedirectResponse
    {
        $group->loadMissing('memberships');

        $validated = request()->validate([
            'redirect_to' => ['nullable', Rule::in(['back', 'dashboard'])],
        ]);

        if (! $group->allowsOpenJoin()) {
            return redirect()->back()->withErrors([
                'error' => 'group_join_unavailable',
            ]);
        }

        if ($group->isBanned(auth()->id())) {
            return redirect()->back()->withErrors([
                'error' => 'group_banned',
            ]);
        }

        $user = auth()->user();
        $membership = $this->quotaService->runIf([
            new QuotaCheck(QuotaKey::GROUPS_JOINED, $user),
        ], fn (): bool => ! $group->memberships()
            ->where('user_id', $user->id)
            ->exists(), fn (): GroupMembership => $group->memberships()->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'role' => GroupMembership::ROLE_MEMBER,
                    'joined_at' => now(),
                ],
            ));

        if ($membership->wasRecentlyCreated) {
            $this->auditLogger->log(
                action: 'group.member.joined',
                severity: AuditSeverity::INFO,
                scopeType: AuditScope::GROUP,
                scopeId: $group->id,
                message: 'audit_log.events.group.member.joined',
                actor: auth()->user(),
                subject: auth()->user(),
            );

            $this->groupUpdateNotificationService->notifyMemberJoined(
                $group->fresh(),
                auth()->user(),
                auth()->user(),
            );
        }

        return $this->joinRedirect($group, $validated['redirect_to'] ?? 'dashboard')
            ->with('success', 'group_joined');
    }

    public function leave(Request $request, Group $group): RedirectResponse
    {
        $group->loadMissing('memberships');

        $validated = $request->validate([
            'redirect_to' => ['nullable', Rule::in(['back', 'profile', 'groups'])],
        ]);

        if ($group->isOwnedBy(auth()->id())) {
            return redirect()->back()->withErrors([
                'error' => 'group_owner_cannot_leave',
            ]);
        }

        $membership = $group->memberships()
            ->where('user_id', auth()->id())
            ->first();

        if (! $membership) {
            return redirect()->back()->withErrors([
                'error' => 'group_membership_not_found',
            ]);
        }

        DB::transaction(function () use ($group, $membership) {
            ScheduledRun::query()
                ->where('group_id', $group->id)
                ->where('organized_by_user_id', $membership->user_id)
                ->update(['organized_by_user_id' => $group->owner_id]);

            $membership->delete();
        });

        $this->auditLogger->log(
            action: 'group.member.left',
            severity: AuditSeverity::INFO,
            scopeType: AuditScope::GROUP,
            scopeId: $group->id,
            message: 'audit_log.events.group.member.left',
            actor: auth()->user(),
            subject: auth()->user(),
        );

        $this->groupUpdateNotificationService->notifyMemberLeft(
            $group->fresh(),
            auth()->user(),
            auth()->user(),
        );

        return $this->leaveRedirect($group, $validated['redirect_to'] ?? 'back')
            ->with('success', 'group_left');
    }

    public function updateNotifications(Request $request, Group $group): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'notification_preferences' => ['sometimes', 'array'],
        ]);

        $membership = $group->memberships()
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $membership->update([
            'notifications_enabled' => $validated['enabled'],
        ]);

        if (array_key_exists('notification_preferences', $validated)) {
            $this->notificationPreferenceSettingsService->persistGroupPreferences(
                $request->user(),
                $group->id,
                $validated['notification_preferences'],
            );
        }

        return redirect()->back()->with('success', 'group_notifications_updated');
    }

    private function leaveRedirect(Group $group, string $target): RedirectResponse
    {
        if ($target === 'groups') {
            return redirect()->route('groups.index');
        }

        if ($target === 'profile') {
            return redirect()->route('groups.dashboard', $group);
        }

        return redirect()->back();
    }

    private function joinRedirect(Group $group, string $target): RedirectResponse
    {
        if ($target === 'back') {
            return redirect()->back();
        }

        return redirect()->route('groups.dashboard', $group);
    }

    public function update(Request $request, Group $group, User $user): RedirectResponse
    {
        $group->loadMissing('memberships');

        $validated = $request->validate([
            'role' => ['required', Rule::in([
                GroupMembership::ROLE_ADMIN,
                GroupMembership::ROLE_MODERATOR,
                GroupMembership::ROLE_MEMBER,
            ])],
        ]);

        $membership = $group->memberships()
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($membership->role === GroupMembership::ROLE_OWNER) {
            return redirect()->back()->withErrors([
                'error' => 'group_owner_role_locked',
            ]);
        }

        $this->authorizeRoleUpdate($group, $membership, $validated['role']);

        $previousRole = $membership->role;

        $membership->update([
            'role' => $validated['role'],
        ]);

        $isPromotion = $this->roleRank($validated['role']) < $this->roleRank($previousRole);

        $this->auditLogger->log(
            action: $isPromotion
                ? 'group.member.promoted'
                : 'group.member.demoted',
            severity: AuditSeverity::MODERATION_CHANGE,
            scopeType: AuditScope::GROUP,
            scopeId: $group->id,
            message: $isPromotion
                ? 'audit_log.events.group.member.promoted'
                : 'audit_log.events.group.member.demoted',
            actor: auth()->user(),
            subject: $user,
            metadata: [
                'changes' => [
                    'role' => [
                        'old' => $previousRole,
                        'new' => $validated['role'],
                    ],
                ],
            ],
        );

        if ($isPromotion) {
            $this->groupUpdateNotificationService->notifyMemberPromoted(
                $group->fresh(),
                $user,
                auth()->user(),
                $validated['role'],
            );
        } else {
            $this->groupUpdateNotificationService->notifyMemberDemoted(
                $group->fresh(),
                $user,
                auth()->user(),
                $validated['role'],
            );
        }

        return redirect()->back()->with('success', 'group_member_updated');
    }

    public function destroy(Group $group, User $user): RedirectResponse
    {
        $group->loadMissing('memberships');
        $this->authorizeMemberManagerAccess($group, $user->id);

        $membership = $group->memberships()
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($membership->role === GroupMembership::ROLE_OWNER) {
            return redirect()->back()->withErrors([
                'error' => 'group_owner_cannot_be_removed',
            ]);
        }

        DB::transaction(function () use ($group, $membership) {
            ScheduledRun::query()
                ->where('group_id', $group->id)
                ->where('organized_by_user_id', $membership->user_id)
                ->update(['organized_by_user_id' => $group->owner_id]);

            $membership->delete();
        });

        $this->auditLogger->log(
            action: 'group.member.removed',
            severity: AuditSeverity::SEVERE_CHANGE,
            scopeType: AuditScope::GROUP,
            scopeId: $group->id,
            message: 'audit_log.events.group.member.removed',
            actor: auth()->user(),
            subject: $user,
            metadata: [
                'role' => $membership->role,
            ],
        );

        return redirect()->back()->with('success', 'group_member_removed');
    }

    public function ban(Request $request, Group $group, User $user): RedirectResponse
    {
        $group->loadMissing('memberships');
        $this->authorizeMemberManagerAccess($group, $user->id);
        $this->requestTextInputSanitizer->sanitize($request, [], ['reason']);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $membership = $group->memberships()
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($membership->role === GroupMembership::ROLE_OWNER) {
            return redirect()->back()->withErrors([
                'error' => 'group_owner_cannot_be_removed',
            ]);
        }

        DB::transaction(function () use ($group, $membership, $validated) {
            ScheduledRun::query()
                ->where('group_id', $group->id)
                ->where('organized_by_user_id', $membership->user_id)
                ->update(['organized_by_user_id' => $group->owner_id]);

            $group->bans()->updateOrCreate(
                ['user_id' => $membership->user_id],
                [
                    'banned_by_user_id' => auth()->id(),
                    'reason' => $validated['reason'] ?? null,
                ]
            );

            $membership->delete();
        });

        $this->auditLogger->log(
            action: 'group.member.banned',
            severity: AuditSeverity::SEVERE_CHANGE,
            scopeType: AuditScope::GROUP,
            scopeId: $group->id,
            message: 'audit_log.events.group.member.banned',
            actor: auth()->user(),
            subject: $user,
            metadata: [
                'changes' => [
                    'membership_status' => [
                        'old' => 'active',
                        'new' => 'banned',
                    ],
                    'ban_reason' => [
                        'old' => null,
                        'new' => $validated['reason'] ?? null,
                    ],
                ],
                'previous_role' => $membership->role,
            ],
        );

        $this->groupUpdateNotificationService->notifyMemberBanned($group->fresh(), $user, auth()->user());

        return redirect()->back()->with('success', 'group_member_banned');
    }

    public function unban(Group $group, User $user): RedirectResponse
    {
        $group->loadMissing(['memberships', 'bans']);
        $this->authorizeBanManagerAccess($group);

        $ban = $group->bans()
            ->where('user_id', $user->id)
            ->first();

        if (! $ban) {
            return redirect()->back()->withErrors([
                'error' => 'group_ban_not_found',
            ]);
        }

        $ban->delete();

        $this->auditLogger->log(
            action: 'group.member.unbanned',
            severity: AuditSeverity::MODERATION_CHANGE,
            scopeType: AuditScope::GROUP,
            scopeId: $group->id,
            message: 'audit_log.events.group.member.unbanned',
            actor: auth()->user(),
            subject: $user,
            metadata: [
                'changes' => [
                    'membership_status' => [
                        'old' => 'banned',
                        'new' => 'not_banned',
                    ],
                ],
            ],
        );

        return redirect()->back()->with('success', 'group_member_unbanned');
    }

    public function transferOwnership(Request $request, Group $group): RedirectResponse
    {
        $group->loadMissing('memberships');
        $this->authorizeOwnerAccess($group);

        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
        ]);

        $newOwnerMembership = $group->memberships()
            ->where('user_id', $validated['user_id'])
            ->first();

        if (! $newOwnerMembership) {
            return redirect()->back()->withErrors([
                'error' => 'group_member_not_found',
            ]);
        }

        if ($newOwnerMembership->user_id === $group->owner_id) {
            return redirect()->back()->withErrors([
                'error' => 'group_owner_already_set',
            ]);
        }

        $previousOwnerId = $group->owner_id;

        $newOwner = $newOwnerMembership->user()->firstOrFail();

        $this->quotaService->run([
            new QuotaCheck(QuotaKey::GROUPS_OWNED, $newOwner),
        ], function () use ($group, $newOwnerMembership) {
            $group->memberships()
                ->where('role', GroupMembership::ROLE_OWNER)
                ->update(['role' => GroupMembership::ROLE_ADMIN]);

            $newOwnerMembership->update([
                'role' => GroupMembership::ROLE_OWNER,
            ]);

            $group->update([
                'owner_id' => $newOwnerMembership->user_id,
            ]);
        });

        $this->auditLogger->log(
            action: 'group.ownership.transferred',
            severity: AuditSeverity::SEVERE_CHANGE,
            scopeType: AuditScope::GROUP,
            scopeId: $group->id,
            message: 'audit_log.events.group.ownership.transferred',
            actor: auth()->user(),
            subject: $group,
            metadata: [
                'changes' => [
                    'owner_user_id' => [
                        'old' => $previousOwnerId,
                        'new' => $newOwnerMembership->user_id,
                    ],
                ],
            ],
        );

        $previousOwner = User::query()->findOrFail($previousOwnerId);

        $this->groupUpdateNotificationService->notifyOwnershipTransferred(
            $group->fresh(),
            $previousOwner,
            $newOwnerMembership->user()->firstOrFail(),
            auth()->user(),
        );

        return redirect()->back()->with('success', 'group_ownership_transferred');
    }

    private function authorizeOwnerAccess(Group $group): void
    {
        if (! $group->isOwnedBy(auth()->id())) {
            abort(403);
        }
    }

    private function authorizeRoleUpdate(Group $group, GroupMembership $membership, string $nextRole): void
    {
        $actorId = auth()->id();

        if ($membership->user_id === $actorId) {
            abort(403);
        }

        if ($group->isOwnedBy($actorId)) {
            return;
        }

        if (! $group->hasAdminAccess($actorId)) {
            abort(403);
        }

        $isAllowedAdminRoleChange = (
            $membership->role === GroupMembership::ROLE_MEMBER
            && $nextRole === GroupMembership::ROLE_MODERATOR
        ) || (
            $membership->role === GroupMembership::ROLE_MODERATOR
            && $nextRole === GroupMembership::ROLE_MEMBER
        );

        if (! $isAllowedAdminRoleChange) {
            abort(403);
        }
    }

    private function authorizeMemberManagerAccess(Group $group, int $targetUserId): void
    {
        $actorId = auth()->id();

        if ($group->isOwnedBy($actorId)) {
            return;
        }

        if (! $group->hasModeratorAccess($actorId)) {
            abort(403);
        }

        $targetMembership = $group->memberships
            ->firstWhere('user_id', $targetUserId);

        if (! $targetMembership || $targetMembership->role !== GroupMembership::ROLE_MEMBER) {
            abort(403);
        }
    }

    private function authorizeBanManagerAccess(Group $group): void
    {
        if (! $group->hasModeratorAccess(auth()->id())) {
            abort(403);
        }
    }

    private function roleRank(string $role): int
    {
        return match ($role) {
            GroupMembership::ROLE_OWNER => 0,
            GroupMembership::ROLE_ADMIN => 1,
            GroupMembership::ROLE_MODERATOR => 2,
            default => 3,
        };
    }
}
