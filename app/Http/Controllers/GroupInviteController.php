<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\GroupInvite;
use App\Models\GroupMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class GroupInviteController extends Controller
{
    public function show(string $token): Response
    {
        $invite = GroupInvite::query()
            ->with('group.owner')
            ->where('token', $token)
            ->firstOrFail();

        $group = $invite->group->loadMissing('memberships');

        return Inertia::render('Groups/Invite', [
            'invite' => [
                'token' => $invite->token,
                'is_system' => $invite->is_system,
                'uses' => $invite->uses,
                'max_uses' => $invite->max_uses,
                'expires_at' => $invite->expires_at,
                'can_accept' => $this->canAcceptInvite($invite),
            ],
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'description' => $group->description,
                'profile_picture_url' => $group->profile_picture_url,
                'datacenter' => $group->datacenter,
                'is_public' => $group->is_public,
                'is_visible' => $group->is_visible,
                'slug' => $group->slug,
                'owner' => [
                    'id' => $group->owner?->id,
                    'name' => $group->owner?->name,
                    'avatar_url' => $group->owner?->avatar_url,
                ],
                'current_user_is_member' => $group->hasMember(auth()->id()),
            ],
        ]);
    }

    public function store(Request $request, Group $group): RedirectResponse
    {
        $group->loadMissing('memberships');
        $this->authorizeModeratorAccess($group);

        $validated = $request->validate([
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $group->invites()->create([
            'created_by' => auth()->id(),
            'token' => GroupInvite::generateUniqueToken(),
            'max_uses' => $validated['max_uses'] ?? null,
            'expires_at' => $validated['expires_at'] ?? null,
            'is_system' => false,
        ]);

        return redirect()->back()->with('success', 'group_invite_created');
    }

    public function accept(string $token): RedirectResponse
    {
        $invite = GroupInvite::query()
            ->with('group')
            ->where('token', $token)
            ->firstOrFail();

        if (!$this->canAcceptInvite($invite)) {
            return redirect()->route('groups.index')->withErrors([
                'error' => 'group_invite_invalid',
            ]);
        }

        DB::transaction(function () use ($invite) {
            $invite->group->memberships()->firstOrCreate(
                ['user_id' => auth()->id()],
                [
                    'role' => GroupMembership::ROLE_MEMBER,
                    'joined_at' => now(),
                ]
            );

            $invite->increment('uses');
        });

        return redirect()->route('groups.show', $invite->group)->with('success', 'group_joined');
    }

    public function destroy(Group $group, GroupInvite $invite): RedirectResponse
    {
        $group->loadMissing('memberships');
        $this->authorizeModeratorAccess($group);

        if ($invite->group_id !== $group->id) {
            abort(404);
        }

        if ($invite->is_system) {
            return redirect()->back()->withErrors([
                'error' => 'group_system_invite_locked',
            ]);
        }

        $invite->delete();

        return redirect()->back()->with('success', 'group_invite_deleted');
    }

    private function authorizeModeratorAccess(Group $group): void
    {
        if (!$group->hasModeratorAccess(auth()->id())) {
            abort(403);
        }
    }

    private function canAcceptInvite(GroupInvite $invite): bool
    {
        if ($invite->is_system && !$invite->group->is_public) {
            return false;
        }

        return $invite->canBeAccepted();
    }
}
