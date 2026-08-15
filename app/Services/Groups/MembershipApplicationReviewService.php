<?php

namespace App\Services\Groups;

use App\DTOs\QuotaCheck;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupMembershipApplication;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Notifications\GroupUpdateNotificationService;
use App\Services\Quotas\QuotaService;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use App\Support\Quotas\QuotaKey;
use Illuminate\Validation\ValidationException;

final class MembershipApplicationReviewService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly GroupUpdateNotificationService $groupUpdateNotificationService,
        private readonly QuotaService $quotaService,
    ) {}

    public function approve(Group $group, GroupMembershipApplication $application, User $reviewer): GroupMembershipApplication
    {
        $this->ensureApplicationBelongsToGroup($group, $application);
        $this->ensurePending($application);

        if ($group->isBanned($application->user_id)) {
            throw ValidationException::withMessages([
                'application' => __('groups.membership_applications.review.validation.applicant_banned'),
            ]);
        }

        $applicant = User::query()->findOrFail($application->user_id);

        $this->quotaService->runIf([
            new QuotaCheck(QuotaKey::GROUPS_JOINED, $applicant),
        ], fn (): bool => ! $group->memberships()
            ->where('user_id', $applicant->id)
            ->exists(), function () use ($group, $application, $reviewer, $applicant): void {
                $group->memberships()->firstOrCreate(
                    ['user_id' => $applicant->id],
                    [
                        'role' => GroupMembership::ROLE_MEMBER,
                        'joined_at' => now(),
                    ],
                );

                $application->update([
                    'status' => GroupMembershipApplication::STATUS_APPROVED,
                    'reviewed_by_user_id' => $reviewer->id,
                    'reviewed_at' => now(),
                    'review_reason' => null,
                ]);
            });

        $application->refresh();

        $this->auditLogger->log(
            action: 'group.membership_application.approved',
            severity: AuditSeverity::MODERATION_CHANGE,
            scopeType: AuditScope::GROUP,
            scopeId: $group->id,
            message: 'audit_log.events.group.membership_application.approved',
            actor: $reviewer,
            subject: $application,
            metadata: $this->auditMetadata($application),
        );

        $application->loadMissing('user');

        if ($application->user instanceof User) {
            $this->groupUpdateNotificationService->notifyMemberJoined($group->fresh(), $application->user, $reviewer);
        }

        return $application;
    }

    public function decline(Group $group, GroupMembershipApplication $application, User $reviewer, ?string $reason = null): GroupMembershipApplication
    {
        $this->ensureApplicationBelongsToGroup($group, $application);
        $this->ensurePending($application);

        $application->update([
            'status' => GroupMembershipApplication::STATUS_DECLINED,
            'reviewed_by_user_id' => $reviewer->id,
            'reviewed_at' => now(),
            'review_reason' => $reason,
        ]);

        $this->auditLogger->log(
            action: 'group.membership_application.declined',
            severity: AuditSeverity::MODERATION_CHANGE,
            scopeType: AuditScope::GROUP,
            scopeId: $group->id,
            message: 'audit_log.events.group.membership_application.declined',
            actor: $reviewer,
            subject: $application,
            metadata: $this->auditMetadata($application),
        );

        return $application;
    }

    private function ensureApplicationBelongsToGroup(Group $group, GroupMembershipApplication $application): void
    {
        if ((int) $application->group_id !== (int) $group->id) {
            abort(404);
        }
    }

    private function ensurePending(GroupMembershipApplication $application): void
    {
        if (! $application->isPending()) {
            throw ValidationException::withMessages([
                'application' => __('groups.membership_applications.review.validation.already_reviewed'),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditMetadata(GroupMembershipApplication $application): array
    {
        $application->loadMissing('user');

        return [
            'membership_application_id' => $application->id,
            'applicant_user_id' => $application->user_id,
            'applicant_name' => $application->user?->name,
            'status' => $application->status,
        ];
    }
}
