<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\ScheduledRun;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GroupMembershipController extends Controller
{
    public function join(Group $group): RedirectResponse
    {
        $group->loadMissing('memberships');

        if (!$group->is_public) {
            return redirect()->back()->withErrors([
                'error' => 'group_not_public',
            ]);
        }

        if ($group->isBanned(auth()->id())) {
            return redirect()->back()->withErrors([
                'error' => 'group_banned',
            ]);
        }

        $group->memberships()->firstOrCreate(
            ['user_id' => auth()->id()],
            [
                'role' => GroupMembership::ROLE_MEMBER,
                'joined_at' => now(),
            ]
        );

        return redirect()->route('groups.show', $group)->with('success', 'group_joined');
    }

    public function leave(Group $group): RedirectResponse
    {
        $group->loadMissing('memberships');

        if ($group->isOwnedBy(auth()->id())) {
            return redirect()->back()->withErrors([
                'error' => 'group_owner_cannot_leave',
            ]);
        }

        $membership = $group->memberships()
            ->where('user_id', auth()->id())
            ->first();

        if (!$membership) {
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

        return redirect()->route('groups.index')->with('success', 'group_left');
    }

    public function update(Request $request, Group $group, User $user): RedirectResponse
    {
        $group->loadMissing('memberships');
        $this->authorizeOwnerAccess($group);

        $validated = $request->validate([
            'role' => ['required', Rule::in([
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

        $membership->update([
            'role' => $validated['role'],
        ]);

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

        return redirect()->back()->with('success', 'group_member_removed');
    }

    public function ban(Request $request, Group $group, User $user): RedirectResponse
    {
        $group->loadMissing('memberships');
        $this->authorizeMemberManagerAccess($group, $user->id);

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

        return redirect()->back()->with('success', 'group_member_banned');
    }

    public function unban(Group $group, User $user): RedirectResponse
    {
        $group->loadMissing(['memberships', 'bans']);
        $this->authorizeBanManagerAccess($group);

        $ban = $group->bans()
            ->where('user_id', $user->id)
            ->first();

        if (!$ban) {
            return redirect()->back()->withErrors([
                'error' => 'group_ban_not_found',
            ]);
        }

        $ban->delete();

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

        if (!$newOwnerMembership) {
            return redirect()->back()->withErrors([
                'error' => 'group_member_not_found',
            ]);
        }

        if ($newOwnerMembership->user_id === $group->owner_id) {
            return redirect()->back()->withErrors([
                'error' => 'group_owner_already_set',
            ]);
        }

        DB::transaction(function () use ($group, $newOwnerMembership) {
            $group->memberships()
                ->where('role', GroupMembership::ROLE_OWNER)
                ->update(['role' => GroupMembership::ROLE_MODERATOR]);

            $newOwnerMembership->update([
                'role' => GroupMembership::ROLE_OWNER,
            ]);

            $group->update([
                'owner_id' => $newOwnerMembership->user_id,
            ]);
        });

        return redirect()->back()->with('success', 'group_ownership_transferred');
    }

    private function authorizeOwnerAccess(Group $group): void
    {
        if (!$group->isOwnedBy(auth()->id())) {
            abort(403);
        }
    }

    private function authorizeMemberManagerAccess(Group $group, int $targetUserId): void
    {
        $actorId = auth()->id();

        if ($group->isOwnedBy($actorId)) {
            return;
        }

        if (!$group->hasModeratorAccess($actorId)) {
            abort(403);
        }

        $targetMembership = $group->memberships
            ->firstWhere('user_id', $targetUserId);

        if (!$targetMembership || $targetMembership->role !== GroupMembership::ROLE_MEMBER) {
            abort(403);
        }
    }

    private function authorizeBanManagerAccess(Group $group): void
    {
        if (!$group->hasModeratorAccess(auth()->id())) {
            abort(403);
        }
    }
}
