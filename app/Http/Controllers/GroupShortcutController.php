<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateGroupQuickCreateShortcutsRequest;
use App\Http\Resources\GroupQuickCreateShortcutResource;
use App\Models\Group;
use App\Services\Groups\GroupQuickCreateShortcutService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class GroupShortcutController extends Controller
{
    public function __construct(private readonly GroupQuickCreateShortcutService $shortcutService) {}

    public function index(Group $group): Response
    {
        $group->load(['memberships', 'features', 'quickCreateShortcuts']);
        $this->authorizeAdminAccess($group);

        return Inertia::render('Dashboard/Groups/Settings/Shortcuts', [
            'group' => $this->groupPayload($group),
            'shortcuts' => GroupQuickCreateShortcutResource::collection(
                $group->resolvedQuickCreateShortcuts(),
            )->resolve(),
        ]);
    }

    public function update(UpdateGroupQuickCreateShortcutsRequest $request, Group $group): RedirectResponse
    {
        $group->loadMissing(['memberships', 'quickCreateShortcuts']);
        $this->authorizeAdminAccess($group);

        $this->shortcutService->replace(
            $group,
            $request->validated('shortcuts'),
            $request->user(),
        );

        return redirect()->back();
    }

    private function authorizeAdminAccess(Group $group): void
    {
        if (! $group->hasAdminAccess(auth()->id())) {
            abort(403);
        }
    }

    /** @return array<string, mixed> */
    private function groupPayload(Group $group): array
    {
        $currentUserId = auth()->id();
        $canModerateGroup = $group->hasModeratorAccess($currentUserId);
        $isMember = $group->hasMember($currentUserId);

        return [
            'id' => $group->id,
            'name' => $group->name,
            'slug' => $group->slug,
            'current_user_role' => $group->memberships->firstWhere('user_id', $currentUserId)?->role,
            'features' => $group->featureSettings(),
            'permissions' => [
                'can_manage_group' => $group->isOwnedBy($currentUserId),
                'can_update_group_settings' => $group->hasAdminAccess($currentUserId),
                'can_manage_members' => $canModerateGroup,
                'can_manage_discovery' => $group->hasAdminAccess($currentUserId),
                'can_manage_activities' => $canModerateGroup,
                'can_view_members' => $isMember,
                'can_review_membership_applications' => $group->usesMembershipApplications() && $canModerateGroup,
                'can_manage_membership_application_form' => $group->usesMembershipApplications() && $group->hasAdminAccess($currentUserId),
            ],
        ];
    }
}
