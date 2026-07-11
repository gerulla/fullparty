<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\GroupMembershipApplication;
use App\Services\Groups\MembershipApplicationReviewService;
use App\Support\Input\RequestTextInputSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GroupMembershipApplicationReviewController extends Controller
{
    public function __construct(
        private readonly MembershipApplicationReviewService $reviewService,
        private readonly RequestTextInputSanitizer $requestTextInputSanitizer,
    ) {}

    public function index(Group $group): Response
    {
        $group->loadMissing([
            'owner',
            'memberships',
        ]);

        abort_unless($group->usesMembershipApplications(), 404);

        $this->authorizeModeratorAccess($group);

        $applications = $group->membershipApplications()
            ->with(['user.primaryCharacter', 'reviewedBy'])
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->latest('submitted_at')
            ->latest('id')
            ->get();

        return Inertia::render('Dashboard/Groups/MembershipApplications/Index', [
            'group' => $this->serializeGroup($group),
            'applications' => $applications
                ->map(fn (GroupMembershipApplication $application) => $this->serializeApplication($application))
                ->all(),
        ]);
    }

    public function approve(Group $group, GroupMembershipApplication $application): RedirectResponse
    {
        $group->loadMissing(['memberships', 'bans']);
        abort_unless($group->usesMembershipApplications(), 404);

        $this->authorizeModeratorAccess($group);

        $this->reviewService->approve($group, $application, auth()->user());

        return redirect()->back()->with('success', 'membership_application_approved');
    }

    public function decline(Request $request, Group $group, GroupMembershipApplication $application): RedirectResponse
    {
        $group->loadMissing('memberships');
        abort_unless($group->usesMembershipApplications(), 404);

        $this->authorizeModeratorAccess($group);
        $this->requestTextInputSanitizer->sanitize($request, [], ['review_reason']);

        $validated = $request->validate([
            'review_reason' => ['nullable', 'string', 'max:'.GroupMembershipApplication::REVIEW_REASON_MAX_LENGTH],
        ]);

        $this->reviewService->decline(
            $group,
            $application,
            auth()->user(),
            $validated['review_reason'] ?? null,
        );

        return redirect()->back()->with('success', 'membership_application_declined');
    }

    private function authorizeModeratorAccess(Group $group): void
    {
        if (! $group->hasModeratorAccess(auth()->id())) {
            abort(403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeGroup(Group $group): array
    {
        $currentUserId = auth()->id();

        return [
            'id' => $group->id,
            'name' => $group->name,
            'slug' => $group->slug,
            'current_user_role' => $group->memberships
                ->firstWhere('user_id', $currentUserId)
                ?->role,
            'features' => $group->featureSettings(),
            'permissions' => [
                'can_manage_members' => $group->hasModeratorAccess($currentUserId),
                'can_manage_discovery' => $group->hasAdminAccess($currentUserId),
                'can_review_membership_applications' => $group->usesMembershipApplications() && $group->hasModeratorAccess($currentUserId),
                'can_manage_membership_application_form' => $group->usesMembershipApplications() && $group->hasAdminAccess($currentUserId),
                'can_view_members' => $group->hasMember($currentUserId),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeApplication(GroupMembershipApplication $application): array
    {
        return [
            'id' => $application->id,
            'status' => $application->status,
            'answers' => $application->answers ?? [],
            'form_snapshot' => $application->form_snapshot ?? [],
            'submitted_at' => $application->submitted_at?->toIso8601String(),
            'reviewed_at' => $application->reviewed_at?->toIso8601String(),
            'review_reason' => $application->review_reason,
            'user' => [
                'id' => $application->user?->id,
                'name' => $application->user?->name,
                'avatar_url' => $application->user?->avatar_url,
                'primary_character' => $application->user?->primaryCharacter ? [
                    'id' => $application->user->primaryCharacter->id,
                    'name' => $application->user->primaryCharacter->name,
                    'world' => $application->user->primaryCharacter->world,
                    'avatar_url' => $application->user->primaryCharacter->avatar_url,
                ] : null,
            ],
            'reviewed_by' => $application->reviewedBy ? [
                'id' => $application->reviewedBy->id,
                'name' => $application->reviewedBy->name,
                'avatar_url' => $application->reviewedBy->avatar_url,
            ] : null,
        ];
    }
}
