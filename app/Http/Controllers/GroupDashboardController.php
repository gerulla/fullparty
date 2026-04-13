<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\ScheduledRun;
use Inertia\Inertia;
use Inertia\Response;

class GroupDashboardController extends Controller
{
    public function show(Group $group): Response
    {
        $group->load([
            'owner',
            'memberships.user',
            'scheduledRuns.organizer',
        ]);

        if (!$group->hasMember(auth()->id())) {
            abort(403);
        }

        return Inertia::render('Dashboard/Groups/Dashboard', [
            'group' => [
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
                    ->firstWhere('user_id', auth()->id())
                    ?->role,
                'permissions' => [
                    'can_manage_group' => $group->isOwnedBy(auth()->id()),
                    'can_manage_members' => $group->hasModeratorAccess(auth()->id()),
                    'can_manage_runs' => $group->hasModeratorAccess(auth()->id()),
                ],
                'stats' => [
                    'member_count' => $group->memberships->count(),
                    'moderator_count' => $group->memberships
                        ->where('role', GroupMembership::ROLE_MODERATOR)
                        ->count(),
                    'run_count' => $group->scheduledRuns->count(),
                    'completed_run_count' => $group->scheduledRuns
                        ->where('status', ScheduledRun::STATUS_COMPLETE)
                        ->count(),
                ],
                'members_preview' => $group->memberships
                    ->sortBy(function (GroupMembership $membership) {
                        return array_search($membership->role, GroupMembership::ROLES, true);
                    })
                    ->take(8)
                    ->values()
                    ->map(fn (GroupMembership $membership) => [
                        'id' => $membership->user->id,
                        'name' => $membership->user->name,
                        'avatar_url' => $membership->user->avatar_url,
                        'role' => $membership->role,
                    ]),
                'recent_runs' => $group->scheduledRuns
                    ->sortByDesc('updated_at')
                    ->take(8)
                    ->values()
                    ->map(fn (ScheduledRun $scheduledRun) => [
                        'id' => $scheduledRun->id,
                        'status' => $scheduledRun->status,
                        'organized_by' => $scheduledRun->organizer ? [
                            'id' => $scheduledRun->organizer->id,
                            'name' => $scheduledRun->organizer->name,
                            'avatar_url' => $scheduledRun->organizer->avatar_url,
                        ] : null,
                        'created_at' => $scheduledRun->created_at,
                        'updated_at' => $scheduledRun->updated_at,
                    ]),
            ],
        ]);
    }
}
