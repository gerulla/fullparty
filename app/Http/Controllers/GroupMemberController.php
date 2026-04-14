<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\GroupBan;
use App\Models\GroupMembership;
use Inertia\Inertia;
use Inertia\Response;

class GroupMemberController extends Controller
{
    public function index(Group $group): Response
    {
        $group->load([
            'owner',
            'memberships.user.characters',
            'bans.user.characters',
            'bans.bannedBy',
        ]);

        $currentUserId = auth()->id();

        if (!$group->hasMember($currentUserId)) {
            abort(403);
        }

        return Inertia::render('Dashboard/Groups/Members/Index', [
            'group' => $this->serializeGroup($group, $currentUserId),
            'members' => $this->serializeMembers($group, $currentUserId),
            'bannedMembers' => $this->serializeBannedMembers($group),
        ]);
    }

    private function serializeGroup(Group $group, int $currentUserId): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'profile_picture_url' => $group->profile_picture_url,
            'discord_invite_url' => $group->discord_invite_url,
            'datacenter' => $group->datacenter,
            'is_public' => $group->is_public,
            'is_visible' => $group->is_visible,
            'slug' => $group->slug,
            'owner' => [
                'id' => $group->owner?->id,
                'name' => $group->owner?->name,
                'avatar_url' => $group->owner?->avatar_url,
            ],
            'current_user_role' => $group->memberships
                ->firstWhere('user_id', $currentUserId)
                ?->role,
            'permissions' => [
                'can_manage_group' => $group->isOwnedBy($currentUserId),
                'can_manage_members' => $group->hasModeratorAccess($currentUserId),
                'can_manage_roles' => $group->isOwnedBy($currentUserId),
                'can_view_bans' => $group->hasModeratorAccess($currentUserId),
            ],
        ];
    }

    private function serializeMembers(Group $group, int $currentUserId): array
    {
        return $group->memberships
            ->sort(function (GroupMembership $left, GroupMembership $right) {
                $leftRole = array_search($left->role, GroupMembership::ROLES, true);
                $rightRole = array_search($right->role, GroupMembership::ROLES, true);

                if ($leftRole === $rightRole) {
                    return strcasecmp($left->user?->name ?? '', $right->user?->name ?? '');
                }

                return $leftRole <=> $rightRole;
            })
            ->values()
            ->map(fn (GroupMembership $membership) => [
                'id' => $membership->user->id,
                'name' => $membership->user->name,
                'avatar_url' => $membership->user->avatar_url,
                'role' => $membership->role,
                'joined_at' => $membership->joined_at,
                'participated_run_count' => 0,
                'characters' => $membership->user->characters
                    ->sort(function ($left, $right) {
                        if ($left->is_primary === $right->is_primary) {
                            return strcasecmp($left->name, $right->name);
                        }

                        return $left->is_primary ? -1 : 1;
                    })
                    ->values()
                    ->map(fn ($character) => [
                        'id' => $character->id,
                        'name' => $character->name,
                        'world' => $character->world,
                        'datacenter' => $character->datacenter,
                        'avatar_url' => $character->avatar_url,
                        'is_primary' => $character->is_primary,
                    ])
                    ->all(),
                'permissions' => [
                    'can_promote' => $this->canPromote($group, $membership, $currentUserId),
                    'can_demote' => $this->canDemote($group, $membership, $currentUserId),
                    'can_kick' => $this->canKick($group, $membership, $currentUserId),
                    'can_ban' => $this->canBan($group, $membership, $currentUserId),
                ],
            ])
            ->all();
    }

    private function canPromote(Group $group, GroupMembership $membership, int $currentUserId): bool
    {
        return $group->isOwnedBy($currentUserId)
            && $membership->user_id !== $currentUserId
            && $membership->role === GroupMembership::ROLE_MEMBER;
    }

    private function canDemote(Group $group, GroupMembership $membership, int $currentUserId): bool
    {
        return $group->isOwnedBy($currentUserId)
            && $membership->user_id !== $currentUserId
            && $membership->role === GroupMembership::ROLE_MODERATOR;
    }

    private function canKick(Group $group, GroupMembership $membership, int $currentUserId): bool
    {
        if ($membership->user_id === $currentUserId || $membership->role === GroupMembership::ROLE_OWNER) {
            return false;
        }

        if ($group->isOwnedBy($currentUserId)) {
            return true;
        }

        return $group->hasModeratorAccess($currentUserId)
            && $membership->role === GroupMembership::ROLE_MEMBER;
    }

    private function canBan(Group $group, GroupMembership $membership, int $currentUserId): bool
    {
        return $this->canKick($group, $membership, $currentUserId);
    }

    private function serializeBannedMembers(Group $group): array
    {
        return $group->bans
            ->sort(function (GroupBan $left, GroupBan $right) {
                return $right->created_at <=> $left->created_at;
            })
            ->values()
            ->map(fn (GroupBan $ban) => [
                'id' => $ban->id,
                'user_id' => $ban->user?->id,
                'name' => $ban->user?->name,
                'avatar_url' => $ban->user?->avatar_url,
                'characters' => $ban->user?->characters
                    ?->sort(function ($left, $right) {
                        if ($left->is_primary === $right->is_primary) {
                            return strcasecmp($left->name, $right->name);
                        }

                        return $left->is_primary ? -1 : 1;
                    })
                    ->values()
                    ->map(fn ($character) => [
                        'id' => $character->id,
                        'name' => $character->name,
                        'world' => $character->world,
                        'avatar_url' => $character->avatar_url,
                        'is_primary' => $character->is_primary,
                    ])
                    ->all() ?? [],
                'reason' => $ban->reason,
                'banned_at' => $ban->created_at,
                'banned_by' => $ban->bannedBy ? [
                    'id' => $ban->bannedBy->id,
                    'name' => $ban->bannedBy->name,
                    'avatar_url' => $ban->bannedBy->avatar_url,
                ] : null,
                'permissions' => [
                    'can_unban' => $group->hasModeratorAccess(auth()->id()),
                ],
            ])
            ->all();
    }
}
