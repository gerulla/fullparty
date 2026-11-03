<?php

namespace App\Http\Controllers;

use App\Http\Requests\AuditLogIndexRequest;
use App\Models\AuditLog;
use App\Models\Group;
use App\Services\Audit\AuditLogFeedService;
use App\Services\Audit\GroupAuditLogPresenter;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class GroupAuditLogController extends Controller
{
    public function index(Group $group, AuditLogIndexRequest $request, AuditLogFeedService $feed, GroupAuditLogPresenter $presenter): Response|JsonResponse
    {
        $this->authorizeModeratorAccess($group);

        $query = AuditLog::query()
            ->where('scope_type', 'group')
            ->where('scope_id', $group->id);
        $auditLogs = $feed->paginate($query, $request->filters(), $request->validated('cursor'));
        $payload = [
            'nextCursor' => $auditLogs->nextCursor()?->encode(),
            'auditLogs' => $presenter->rows($auditLogs->getCollection(), $group),
        ];

        if ($request->wantsJson()) {
            if ($request->boolean('include_options')) {
                $payload['filters'] = $feed->options($query, $group);
            }

            return response()->json($payload);
        }

        $group->load(['owner', 'memberships', 'features']);

        return Inertia::render('Dashboard/Groups/AuditLog/Index', [
            ...$payload,
            'selectedFilters' => $request->filters(),
            'filters' => $feed->options($query, $group),
            'group' => [
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
                    ->firstWhere('user_id', auth()->id())
                    ?->role,
                'features' => $group->featureSettings(),
                'permissions' => [
                    'can_manage_group' => $group->isOwnedBy(auth()->id()),
                    'can_manage_members' => $group->hasModeratorAccess(auth()->id()),
                    'can_manage_discovery' => $group->hasAdminAccess(auth()->id()),
                    'can_manage_roles' => $group->isOwnedBy(auth()->id()),
                    'can_view_bans' => $group->hasModeratorAccess(auth()->id()),
                    'can_view_members' => $group->hasMember(auth()->id()),
                    'can_review_membership_applications' => $group->usesMembershipApplications() && $group->hasModeratorAccess(auth()->id()),
                    'can_manage_membership_application_form' => $group->usesMembershipApplications() && $group->hasAdminAccess(auth()->id()),
                ],
            ],
        ]);
    }

    public function show(Group $group, AuditLog $auditLog, GroupAuditLogPresenter $presenter): JsonResponse
    {
        $this->authorizeModeratorAccess($group);
        abort_unless($auditLog->scope_type === 'group' && (int) $auditLog->scope_id === (int) $group->id, 404);

        return response()->json(['event' => $presenter->detail($auditLog, $group)]);
    }

    private function authorizeModeratorAccess(Group $group): void
    {
        if (! $group->hasModeratorAccess(auth()->id())) {
            abort(403);
        }
    }
}
