<?php

namespace App\Http\Controllers;

use App\DTOs\QuotaCheck;
use App\Models\Group;
use App\Models\GroupMembershipApplication;
use App\Services\AuditLogger;
use App\Services\Groups\MembershipApplicationFormSchemaService;
use App\Services\Quotas\QuotaService;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use App\Support\Quotas\QuotaKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GroupMembershipApplicationController extends Controller
{
    public function __construct(
        private readonly MembershipApplicationFormSchemaService $schemaService,
        private readonly AuditLogger $auditLogger,
        private readonly QuotaService $quotaService,
    ) {}

    public function create(Request $request, Group $group): Response|RedirectResponse
    {
        $group->loadMissing(['owner', 'memberships']);
        $this->authorizeApplicationAccess($request, $group);
        $this->schemaService->ensureDefaultForm($group);
        $group->refresh();
        $application = $group->membershipApplications()
            ->where('user_id', $request->user()->id)
            ->latest('submitted_at')
            ->latest('id')
            ->first();

        if ($group->hasMember($request->user()->id)) {
            return redirect()->route('groups.dashboard', $group);
        }

        return Inertia::render('Groups/MembershipApplications/Create', [
            'group' => $this->serializeGroup($group),
            'formSchema' => $application?->isPending()
                ? ($application->form_snapshot ?? $this->schemaService->defaultSchema())
                : ($group->membership_application_schema ?? $this->schemaService->defaultSchema()),
            'existingApplication' => $this->serializeApplication($application),
        ]);
    }

    public function store(Request $request, Group $group): RedirectResponse
    {
        $group->loadMissing('memberships');
        $this->authorizeApplicationAccess($request, $group);
        $this->schemaService->ensureDefaultForm($group);
        $group->refresh();

        $user = $request->user();

        if ($group->hasMember($user->id)) {
            return redirect()->route('groups.dashboard', $group);
        }

        if ($this->pendingApplicationExists($group, $user->id)) {
            return redirect()->back()->withErrors([
                'application' => __('groups.membership_applications.apply.validation.pending_exists'),
            ]);
        }

        $answers = $this->schemaService->normalizeAndValidateAnswers(
            $request->input('answers', []),
            $group->membership_application_schema ?? $this->schemaService->defaultSchema(),
        );

        $application = $this->quotaService->run([
            new QuotaCheck(QuotaKey::PENDING_MEMBERSHIP_APPLICATIONS, $user),
        ], fn (): GroupMembershipApplication => $group->membershipApplications()->create([
            'user_id' => $user->id,
            'status' => GroupMembershipApplication::STATUS_PENDING,
            'answers' => $answers,
            'form_snapshot' => $group->membership_application_schema ?? $this->schemaService->defaultSchema(),
            'submitted_at' => now(),
        ]));

        $this->auditLogger->log(
            action: 'group.membership_application.submitted',
            severity: AuditSeverity::INFO,
            scopeType: AuditScope::GROUP,
            scopeId: $group->id,
            message: 'audit_log.events.group.membership_application.submitted',
            actor: $user,
            subject: $application,
            metadata: [
                'membership_application_id' => $application->id,
                'applicant_user_id' => $user->id,
                'applicant_name' => $user->name,
            ],
        );

        return redirect()
            ->route('groups.membership-applications.create', $group)
            ->with('success', 'membership_application_submitted');
    }

    public function update(Request $request, Group $group): RedirectResponse
    {
        $group->loadMissing('memberships');
        $this->authorizeApplicationAccess($request, $group);

        $user = $request->user();

        if ($group->hasMember($user->id)) {
            return redirect()->route('groups.dashboard', $group);
        }

        $application = $group->membershipApplications()
            ->where('user_id', $user->id)
            ->where('status', GroupMembershipApplication::STATUS_PENDING)
            ->latest('submitted_at')
            ->latest('id')
            ->firstOrFail();

        $schema = $application->form_snapshot ?? $group->membership_application_schema ?? $this->schemaService->defaultSchema();
        $answers = $this->schemaService->normalizeAndValidateAnswers(
            $request->input('answers', []),
            $schema,
        );

        $application->forceFill([
            'answers' => $answers,
        ])->save();

        $this->auditLogger->log(
            action: 'group.membership_application.updated',
            severity: AuditSeverity::INFO,
            scopeType: AuditScope::GROUP,
            scopeId: $group->id,
            message: 'audit_log.events.group.membership_application.updated',
            actor: $user,
            subject: $application,
            metadata: [
                'membership_application_id' => $application->id,
                'applicant_user_id' => $user->id,
                'applicant_name' => $user->name,
            ],
        );

        return redirect()
            ->route('groups.membership-applications.create', $group)
            ->with('success', 'membership_request_updated');
    }

    private function authorizeApplicationAccess(Request $request, Group $group): void
    {
        abort_unless($group->is_visible, 404);
        abort_unless($group->usesMembershipApplications(), 404);

        if ($group->isBanned($request->user()?->id)) {
            abort(403);
        }
    }

    private function pendingApplicationExists(Group $group, int $userId): bool
    {
        return $group->membershipApplications()
            ->where('user_id', $userId)
            ->where('status', GroupMembershipApplication::STATUS_PENDING)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeGroup(Group $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'profile_picture_url' => $group->profile_picture_url,
            'banner_image_url' => $group->banner_image_url,
            'datacenter' => $group->datacenter,
            'slug' => $group->slug,
            'group_type' => $group->group_type,
            'join_mode' => $group->join_mode,
            'owner' => [
                'id' => $group->owner?->id,
                'name' => $group->owner?->name,
                'avatar_url' => $group->owner?->avatar_url,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeApplication(?GroupMembershipApplication $application): ?array
    {
        if (! $application instanceof GroupMembershipApplication) {
            return null;
        }

        return [
            'id' => $application->id,
            'status' => $application->status,
            'answers' => $application->answers ?? [],
            'form_snapshot' => $application->form_snapshot ?? [],
            'submitted_at' => $application->submitted_at?->toIso8601String(),
            'reviewed_at' => $application->reviewed_at?->toIso8601String(),
            'review_reason' => $application->review_reason,
        ];
    }
}
