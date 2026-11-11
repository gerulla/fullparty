<?php

namespace App\Http\Controllers;

use App\Http\Resources\BozjaHolsterResource;
use App\Http\Resources\BozjaItemOptionResource;
use App\Models\BozjaHolster;
use App\Models\BozjaItem;
use App\Models\Group;
use App\Models\PhantomComposition;
use App\Models\PhantomJob;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GroupContentController extends Controller
{
    public function index(Request $request, Group $group): Response
    {
        return $this->renderContentPage($group, 'Dashboard/Groups/Content/Index', [
            'drs' => $request->query('type') === 'drs' ? fn () => [
                'holsters' => BozjaHolsterResource::collection($group->bozjaHolsters()->with('items')->latest('updated_at')->get())->resolve(),
                'bozja_items' => BozjaItemOptionResource::collection(BozjaItem::query()->active()->ordered()->get())->resolve(),
            ] : null,
            'forkedtower' => $request->query('type') === 'forkedtower' ? fn () => [
                'phantom_jobs' => PhantomJob::query()
                    ->select(['id', 'name', 'max_level', 'icon_url', 'black_icon_url', 'transparent_icon_url', 'sprite_url'])
                    ->orderBy('name')
                    ->get()
                    ->toArray(),
            ] : null,
            'content_summary' => function () use ($group): array {
                $holsters = $group->bozjaHolsters()
                    ->where('type', BozjaHolster::TYPE_PREPOP)
                    ->selectRaw('is_active, COUNT(*) as total')
                    ->groupBy('is_active')
                    ->get();
                $compositions = PhantomComposition::query()
                    ->where('group_id', $group->id)
                    ->where('content_key', PhantomComposition::CONTENT_FORKED_TOWER)
                    ->selectRaw('is_active, COUNT(*) as total')
                    ->groupBy('is_active')
                    ->get();

                return [
                    'holsters' => [
                        'total' => (int) $holsters->sum('total'),
                        'active' => (int) ($holsters->firstWhere('is_active', true)?->total ?? 0),
                    ],
                    'compositions' => [
                        'total' => (int) $compositions->sum('total'),
                        'active' => (int) ($compositions->firstWhere('is_active', true)?->total ?? 0),
                    ],
                ];
            },
        ]);
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
