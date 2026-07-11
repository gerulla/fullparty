<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\GroupBan;
use App\Models\GroupMembership;
use App\Models\User;
use App\Services\Groups\GroupCompletedParticipationService;
use App\Services\Groups\GroupMemberActivitySummaryService;
use App\Services\Groups\GroupUserNoteVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class GroupMemberController extends Controller
{
    public function index(
        Group $group,
        GroupUserNoteVisibilityService $noteVisibilityService,
        GroupCompletedParticipationService $completedParticipationService,
    ): Response {
        $group->load([
            'owner',
            'memberships.user.characters',
            'memberships.user.homeProfile',
            'bans.user.characters',
            'bans.user.homeProfile',
            'bans.bannedBy',
            'features',
        ]);

        $currentUserId = auth()->id();
        $canManageMembers = $group->hasModeratorAccess($currentUserId);

        if (! $group->hasMember($currentUserId)) {
            abort(403);
        }

        $targetUserIds = $group->memberships
            ->pluck('user_id')
            ->merge($group->bans->pluck('user_id'))
            ->filter()
            ->unique()
            ->values();

        $visibleNotes = $noteVisibilityService->loadVisibleNotesForTargets($group, $currentUserId, $targetUserIds);
        $groupNotesByUserId = $visibleNotes['group_notes_by_user_id'];
        $sharedNotesByUserId = $visibleNotes['shared_notes_by_user_id'];

        return Inertia::render('Dashboard/Groups/Members/Index', [
            'group' => $this->serializeGroup($group, $currentUserId),
            'members' => $this->serializeMembers(
                $group,
                $currentUserId,
                $groupNotesByUserId,
                $sharedNotesByUserId,
                $noteVisibilityService,
                $completedParticipationService->countsByUser($group),
            ),
            'bannedMembers' => $this->serializeBannedMembers($group, $currentUserId, $groupNotesByUserId, $sharedNotesByUserId, $noteVisibilityService),
        ]);
    }

    public function activitySummary(
        Group $group,
        User $user,
        GroupMemberActivitySummaryService $activitySummaryService,
    ): JsonResponse {
        $currentUserId = auth()->id();
        $group->loadMissing('memberships');

        if (! $group->hasModeratorAccess($currentUserId)) {
            abort(403);
        }

        if (! $group->memberships->contains('user_id', $user->id)) {
            abort(404);
        }

        return response()->json([
            'data' => $activitySummaryService->forMember($group, $user),
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
            'features' => $group->featureSettings(),
            'permissions' => [
                'can_manage_group' => $group->isOwnedBy($currentUserId),
                'can_manage_members' => $group->hasModeratorAccess($currentUserId),
                'can_manage_discovery' => $group->hasAdminAccess($currentUserId),
                'can_manage_roles' => $group->isOwnedBy($currentUserId) || $group->hasAdminAccess($currentUserId),
                'can_view_bans' => $group->hasModeratorAccess($currentUserId),
                'can_view_members' => $group->hasMember($currentUserId),
                'can_view_member_activity_summary' => $group->hasModeratorAccess($currentUserId),
                'can_review_membership_applications' => $group->usesMembershipApplications() && $group->hasModeratorAccess($currentUserId),
                'can_manage_membership_application_form' => $group->usesMembershipApplications() && $group->hasAdminAccess($currentUserId),
            ],
        ];
    }

    private function serializeMembers(
        Group $group,
        int $currentUserId,
        Collection $groupNotesByUserId,
        Collection $sharedNotesByUserId,
        GroupUserNoteVisibilityService $noteVisibilityService,
        Collection $completedParticipationCounts,
    ): array {
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
                'home_background_image_url' => $membership->user->homeProfile?->background_image_url,
                'role' => $membership->role,
                'joined_at' => $membership->joined_at,
                'participated_run_count' => (int) ($completedParticipationCounts->get($membership->user_id) ?? 0),
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
                'note_summary' => $this->serializeVisibleNoteSummaryForUser(
                    $group,
                    $membership->user,
                    $currentUserId,
                    $groupNotesByUserId,
                    $sharedNotesByUserId,
                    $noteVisibilityService,
                ),
            ])
            ->all();
    }

    private function serializeVisibleNoteSummaryForUser(
        Group $group,
        ?User $user,
        int $currentUserId,
        Collection $groupNotesByUserId,
        Collection $sharedNotesByUserId,
        GroupUserNoteVisibilityService $noteVisibilityService
    ): array {
        return $noteVisibilityService->serializeVisibleNoteSummaryForUser(
            $group,
            $user,
            $currentUserId,
            $groupNotesByUserId,
            $sharedNotesByUserId,
        );
    }

    private function canPromote(Group $group, GroupMembership $membership, int $currentUserId): bool
    {
        if ($membership->user_id === $currentUserId || $membership->role === GroupMembership::ROLE_OWNER) {
            return false;
        }

        if ($group->isOwnedBy($currentUserId)) {
            return in_array($membership->role, [
                GroupMembership::ROLE_MEMBER,
                GroupMembership::ROLE_MODERATOR,
            ], true);
        }

        return $group->hasAdminAccess($currentUserId)
            && $membership->role === GroupMembership::ROLE_MEMBER;
    }

    private function canDemote(Group $group, GroupMembership $membership, int $currentUserId): bool
    {
        if ($membership->user_id === $currentUserId || $membership->role === GroupMembership::ROLE_OWNER) {
            return false;
        }

        if ($group->isOwnedBy($currentUserId)) {
            return in_array($membership->role, [
                GroupMembership::ROLE_ADMIN,
                GroupMembership::ROLE_MODERATOR,
            ], true);
        }

        return $group->hasAdminAccess($currentUserId)
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

    private function serializeBannedMembers(
        Group $group,
        int $currentUserId,
        Collection $groupNotesByUserId,
        Collection $sharedNotesByUserId,
        GroupUserNoteVisibilityService $noteVisibilityService,
    ): array {
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
                'home_background_image_url' => $ban->user?->homeProfile?->background_image_url,
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
                        'datacenter' => $character->datacenter,
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
                'note_summary' => $this->serializeVisibleNoteSummaryForUser(
                    $group,
                    $ban->user,
                    $currentUserId,
                    $groupNotesByUserId,
                    $sharedNotesByUserId,
                    $noteVisibilityService,
                ),
            ])
            ->all();
    }

    private function serializeVisibleNotesForUser(
        Group $group,
        ?User $user,
        int $currentUserId,
        Collection $groupNotesByUserId,
        Collection $sharedNotesByUserId,
        GroupUserNoteVisibilityService $noteVisibilityService
    ): array {
        return $noteVisibilityService->serializeVisibleNotesForUser(
            $group,
            $user,
            $currentUserId,
            $groupNotesByUserId,
            $sharedNotesByUserId,
        );
    }
}
