<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Services\Groups\FtelLegacyLeaderboardService;
use Inertia\Inertia;
use Inertia\Response;

class GroupLegacyLeaderboardController extends Controller
{
    public function __invoke(Group $group, FtelLegacyLeaderboardService $leaderboardService): Response
    {
        abort_unless($group->slug === 'ftel', 404);

        $group->loadMissing(['memberships', 'features']);

        $currentUserId = auth()->id();

        if (! $group->hasMember($currentUserId)) {
            abort(403);
        }

        abort_unless($group->featureEnabled('leaderboard_enabled'), 404);

        return Inertia::render('Dashboard/Groups/LegacyLeaderboard', [
            'group' => $this->serializeNavigationGroup($group, $currentUserId),
            'legacy_leaderboard' => $leaderboardService->payload(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeNavigationGroup(Group $group, ?int $currentUserId): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'slug' => $group->slug,
            'current_user_role' => $group->memberships
                ->firstWhere('user_id', $currentUserId)
                ?->role,
            'features' => $group->featureSettings(),
            'permissions' => [
                'can_manage_group' => $group->isOwnedBy($currentUserId),
                'can_manage_members' => $group->hasModeratorAccess($currentUserId),
                'can_manage_discovery' => $group->hasAdminAccess($currentUserId),
                'can_manage_activities' => $group->hasModeratorAccess($currentUserId),
                'can_view_members' => true,
                'can_review_membership_applications' => $group->usesMembershipApplications() && $group->hasModeratorAccess($currentUserId),
                'can_manage_membership_application_form' => $group->usesMembershipApplications() && $group->hasAdminAccess($currentUserId),
            ],
        ];
    }
}
