<?php

namespace App\Http\Controllers;

use App\Http\Resources\BozjaHolsterResource;
use App\Http\Resources\BozjaItemOptionResource;
use App\Models\BozjaItem;
use App\Models\Group;
use App\Models\PhantomJob;
use Inertia\Inertia;
use Inertia\Response;

class GroupContentController extends Controller
{
    public function delubrumReginaeSavage(Group $group): Response
    {
        $holsters = $group->bozjaHolsters()
            ->with('items')
            ->latest('updated_at')
            ->get();
        $bozjaItems = BozjaItem::query()->active()->ordered()->get();

        return $this->renderContentPage(
            $group,
            'Dashboard/Groups/Content/DelubrumReginaeSavage',
            [
                'holsters' => BozjaHolsterResource::collection($holsters)->resolve(),
                'bozja_items' => BozjaItemOptionResource::collection($bozjaItems)->resolve(),
            ],
        );
    }

    public function forkedTowerBlood(Group $group): Response
    {
        $phantomJobs = PhantomJob::query()
            ->select(['id', 'name', 'max_level', 'icon_url', 'black_icon_url', 'transparent_icon_url', 'sprite_url'])
            ->orderBy('name')
            ->get()
            ->map(fn (PhantomJob $phantomJob) => [
                'id' => $phantomJob->id,
                'name' => $phantomJob->name,
                'max_level' => $phantomJob->max_level,
                'icon_url' => $phantomJob->icon_url,
                'black_icon_url' => $phantomJob->black_icon_url,
                'transparent_icon_url' => $phantomJob->transparent_icon_url,
                'sprite_url' => $phantomJob->sprite_url,
            ])
            ->values()
            ->all();

        return $this->renderContentPage(
            $group,
            'Dashboard/Groups/Content/ForkedTowerBlood',
            [
                'phantom_jobs' => $phantomJobs,
            ],
        );
    }

    private function renderContentPage(Group $group, string $component, array $props = []): Response
    {
        $group->loadMissing(['memberships', 'features']);

        abort_unless($group->hasModeratorAccess(auth()->id()), 403);

        $currentUserId = auth()->id();

        return Inertia::render($component, [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'current_user_role' => $group->isOwnedBy($currentUserId)
                    ? 'owner'
                    : $group->memberships->firstWhere('user_id', $currentUserId)?->role,
                'features' => $group->featureSettings(),
                'permissions' => [
                    'can_manage_group' => $group->isOwnedBy($currentUserId),
                    'can_update_group_settings' => $group->hasAdminAccess($currentUserId),
                    'can_manage_members' => true,
                    'can_manage_discovery' => $group->hasAdminAccess($currentUserId),
                    'can_manage_activities' => true,
                    'can_view_members' => true,
                    'can_review_membership_applications' => $group->usesMembershipApplications(),
                    'can_manage_membership_application_form' => $group->usesMembershipApplications()
                        && $group->hasAdminAccess($currentUserId),
                ],
            ],
            ...$props,
        ]);
    }
}
