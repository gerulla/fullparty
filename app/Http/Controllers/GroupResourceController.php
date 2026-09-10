<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\GroupResource;
use App\Services\Groups\Resources\ResourceReaderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GroupResourceController extends Controller
{
    public function __construct(private readonly ResourceReaderService $reader) {}

    public function index(Request $request, Group $group): Response
    {
        $this->authorizeAccess($group);

        return Inertia::render('Dashboard/Groups/Resources/Index', [
            'group' => $this->navigationGroup($group),
        ] + $this->reader->index($group, $request));
    }

    public function manage(Request $request, Group $group): Response
    {
        $this->authorizeAccess($group, manage: true);

        return Inertia::render('Dashboard/Groups/Resources/Manage', [
            'group' => $this->navigationGroup($group),
        ] + $this->reader->index($group, $request, manage: true));
    }

    public function collection(Request $request, Group $group, string $collectionSlug): Response
    {
        $this->authorizeAccess($group);

        return Inertia::render('Dashboard/Groups/Resources/Index', ['group' => $this->navigationGroup($group)] + $this->reader->index($group, $request, collectionSlug: $collectionSlug));
    }

    public function show(Request $request, Group $group, string $slug): Response|RedirectResponse
    {
        $this->authorizeAccess($group);
        $resource = $this->reader->resolve($group, $slug, $request->user());
        if ($resource->slug !== $slug) {
            return redirect()->route('groups.dashboard.resources.show', ['group' => $group, 'slug' => $resource->slug]);
        }

        return Inertia::render('Dashboard/Groups/Resources/Index', ['group' => $this->navigationGroup($group), 'resource' => $this->reader->detail($resource, $request)] + $this->reader->index($group, $request));
    }

    public function edit(Request $request, Group $group, GroupResource $resource): Response
    {
        $this->authorizeAccess($group, manage: true);

        return Inertia::render('Dashboard/Groups/Resources/Manage', ['group' => $this->navigationGroup($group), 'resource' => $this->reader->managementDetail($group, $resource, $request->user())] + $this->reader->index($group, $request, manage: true));
    }

    private function authorizeAccess(Group $group, bool $manage = false): void
    {
        $group->loadMissing(['memberships', 'features']);

        abort_unless($group->hasMember(auth()->id()), 403);
        abort_if($group->isBanned(auth()->id()), 403);
        abort_unless($group->featureEnabled('resource_hub_enabled'), 404);

        if ($manage) {
            abort_unless($group->hasModeratorAccess(auth()->id()), 403);
        }
    }

    /** @return array<string, mixed> */
    private function navigationGroup(Group $group): array
    {
        $userId = auth()->id();
        $canModerate = $group->hasModeratorAccess($userId);
        $canAdminister = $group->hasAdminAccess($userId);

        return [
            'id' => $group->id,
            'name' => $group->name,
            'slug' => $group->slug,
            'current_user_role' => $group->isOwnedBy($userId)
                ? 'owner'
                : $group->memberships->firstWhere('user_id', $userId)?->role,
            'features' => $group->featureSettings(),
            'permissions' => [
                'can_manage_group' => $group->isOwnedBy($userId),
                'can_update_group_settings' => $canAdminister,
                'can_manage_members' => $canModerate,
                'can_manage_discovery' => $canAdminister,
                'can_manage_activities' => $canModerate,
                'can_view_members' => true,
                'can_review_membership_applications' => $group->usesMembershipApplications() && $canModerate,
                'can_manage_membership_application_form' => $group->usesMembershipApplications() && $canAdminister,
            ],
        ];
    }
}
