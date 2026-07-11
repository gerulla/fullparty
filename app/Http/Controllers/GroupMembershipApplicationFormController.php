<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Services\AuditLogger;
use App\Services\Groups\MembershipApplicationFormSchemaService;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GroupMembershipApplicationFormController extends Controller
{
    public function __construct(
        private readonly MembershipApplicationFormSchemaService $schemaService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function edit(Group $group): Response
    {
        $group->loadMissing(['owner', 'memberships']);
        abort_unless($group->usesMembershipApplications(), 404);

        $this->authorizeAdminAccess($group);
        $this->schemaService->ensureDefaultForm($group);
        $group->refresh();

        return Inertia::render('Dashboard/Groups/MembershipApplicationForm/Edit', [
            'group' => $this->serializeGroup($group),
            'formSchema' => $group->membership_application_schema ?? $this->schemaService->defaultSchema(),
            'locales' => ['en', 'de', 'fr', 'ja'],
            'maxQuestions' => MembershipApplicationFormSchemaService::MAX_FIELDS,
        ]);
    }

    public function update(Request $request, Group $group): RedirectResponse
    {
        $group->loadMissing('memberships');
        abort_unless($group->usesMembershipApplications(), 404);

        $this->authorizeAdminAccess($group);

        $schema = $this->schemaService->normalizeAndValidateSchema($request->input('fields', []));
        $originalSchema = $group->membership_application_schema ?? [];

        $group->update([
            'membership_application_schema' => $schema,
        ]);

        if ($originalSchema !== $schema) {
            $this->auditLogger->log(
                action: 'group.membership_application.form_updated',
                severity: AuditSeverity::MODERATION_CHANGE,
                scopeType: AuditScope::GROUP,
                scopeId: $group->id,
                message: 'audit_log.events.group.membership_application.form_updated',
                actor: auth()->user(),
                subject: $group,
                metadata: [
                    'field_count' => count($schema),
                    'previous_field_count' => count($originalSchema),
                ],
            );
        }

        return redirect()->back()->with('success', 'membership_application_form_updated');
    }

    private function authorizeAdminAccess(Group $group): void
    {
        if (! $group->hasAdminAccess(auth()->id())) {
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
            'join_mode' => $group->join_mode,
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
}
