<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\Group;
use App\Services\AuditLogger;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Database\Eloquent\Model;

class GroupActivityAuditService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly ActivitySlotBench $slotBench,
    ) {}

    public function logActivityCreated(Activity $activity, mixed $actor): void
    {
        $activity->loadMissing(['group', 'activityType']);

        $this->log(
            action: 'group.activity.created',
            severity: AuditSeverity::MODERATION_CHANGE,
            group: $activity->group,
            activity: $activity,
            message: 'audit_log.events.group.activity.created',
            actor: $actor,
            metadata: [
                'activity_title' => $this->activityTitle($activity),
                'activity_type_slug' => $activity->activityType?->slug,
                'status' => $activity->status,
                'starts_at' => $activity->starts_at?->toIso8601String(),
                'duration_hours' => $activity->duration_hours,
                'is_public' => $activity->is_public,
                'needs_application' => $activity->needs_application,
            ],
        );
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    public function logActivityUpdated(Activity $activity, mixed $actor, array $changes): void
    {
        if ($changes === []) {
            return;
        }

        $activity->loadMissing('group');

        $this->log(
            action: 'group.activity.updated',
            severity: AuditSeverity::MODERATION_CHANGE,
            group: $activity->group,
            activity: $activity,
            message: 'audit_log.events.group.activity.updated',
            actor: $actor,
            metadata: [
                'activity_title' => $this->activityTitle($activity),
                'changes' => $changes,
            ],
        );
    }

    public function logActivityDeleted(Group $group, Activity $activity, mixed $actor): void
    {
        $this->log(
            action: 'group.activity.deleted',
            severity: AuditSeverity::SEVERE_CHANGE,
            group: $group,
            activity: $activity,
            message: 'audit_log.events.group.activity.deleted',
            actor: $actor,
            subject: [
                'subject_type' => Activity::class,
                'subject_id' => $activity->id,
            ],
            metadata: [
                'activity_title' => $this->activityTitle($activity),
                'status' => $activity->status,
                'starts_at' => $activity->starts_at?->toIso8601String(),
            ],
        );
    }

    public function logApplicationSubmitted(ActivityApplication $application, mixed $actor): void
    {
        $application->loadMissing(['activity.group', 'selectedCharacter']);

        $this->log(
            action: 'group.activity.application.submitted',
            severity: AuditSeverity::INFO,
            group: $application->activity?->group,
            activity: $application->activity,
            message: 'audit_log.events.group.activity.application.submitted',
            actor: $actor,
            subject: $application->user,
            metadata: [
                'activity_title' => $this->activityTitle($application->activity),
                'selected_character_name' => $application->selectedCharacter?->name,
                'application_status' => $application->status,
            ],
        );
    }

    public function logApplicationUpdated(ActivityApplication $application, mixed $actor): void
    {
        $application->loadMissing(['activity.group', 'selectedCharacter']);

        $this->log(
            action: 'group.activity.application.updated',
            severity: AuditSeverity::INFO,
            group: $application->activity?->group,
            activity: $application->activity,
            message: 'audit_log.events.group.activity.application.updated',
            actor: $actor,
            subject: $application->user,
            metadata: [
                'activity_title' => $this->activityTitle($application->activity),
                'selected_character_name' => $application->selectedCharacter?->name,
                'application_status' => $application->status,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function logRosterEvent(
        string $event,
        ActivitySlot $slot,
        mixed $actor,
        array $metadata = [],
        ?string $severity = null,
    ): void {
        $slot->loadMissing(['activity.group', 'assignedCharacter']);

        $defaultMetadata = [
            'activity_title' => $this->activityTitle($slot->activity),
            'slot_label' => $this->slotLabel($slot),
            'group_label' => $this->groupLabel($slot),
            'character_name' => $slot->assignedCharacter?->name,
            'is_bench' => $this->slotBench->isBench($slot),
        ];

        $this->log(
            action: sprintf('group.activity.roster.%s', $event),
            severity: $severity ?? AuditSeverity::MODERATION_CHANGE,
            group: $slot->activity?->group,
            activity: $slot->activity,
            message: sprintf('audit_log.events.group.activity.roster.%s', $event),
            actor: $actor,
            subject: $slot->activity,
            metadata: array_merge($defaultMetadata, $metadata),
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function logAttendanceEvent(
        string $event,
        ActivitySlot $slot,
        mixed $actor,
        array $metadata = [],
        ?string $severity = null,
    ): void {
        $slot->loadMissing(['activity.group', 'assignedCharacter']);

        $defaultMetadata = [
            'activity_title' => $this->activityTitle($slot->activity),
            'slot_label' => $this->slotLabel($slot),
            'group_label' => $this->groupLabel($slot),
            'character_name' => $slot->assignedCharacter?->name,
            'is_bench' => $this->slotBench->isBench($slot),
        ];

        $this->log(
            action: sprintf('group.activity.attendance.%s', $event),
            severity: $severity ?? AuditSeverity::MODERATION_CHANGE,
            group: $slot->activity?->group,
            activity: $slot->activity,
            message: sprintf('audit_log.events.group.activity.attendance.%s', $event),
            actor: $actor,
            subject: $slot->activity,
            metadata: array_merge($defaultMetadata, $metadata),
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function logGroupAttendanceEvent(
        string $event,
        Activity $activity,
        string $groupLabel,
        mixed $actor,
        array $metadata = [],
        ?string $severity = null,
    ): void {
        $activity->loadMissing('group');

        $this->log(
            action: sprintf('group.activity.attendance.%s', $event),
            severity: $severity ?? AuditSeverity::MODERATION_CHANGE,
            group: $activity->group,
            activity: $activity,
            message: sprintf('audit_log.events.group.activity.attendance.%s', $event),
            actor: $actor,
            subject: $activity,
            metadata: array_merge([
                'activity_title' => $this->activityTitle($activity),
                'group_label' => $groupLabel,
            ], $metadata),
        );
    }

    private function log(
        string $action,
        string $severity,
        ?Group $group,
        ?Activity $activity,
        string $message,
        mixed $actor,
        Model|array|null $subject = null,
        ?array $metadata = null,
    ): void {
        if (!$group) {
            return;
        }

        $this->auditLogger->log(
            action: $action,
            severity: $severity,
            scopeType: AuditScope::GROUP,
            scopeId: $group->id,
            message: $message,
            actor: $actor,
            subject: $subject ?? $activity,
            metadata: $metadata,
        );
    }

    private function activityTitle(?Activity $activity): ?string
    {
        if (!$activity) {
            return null;
        }

        if (filled($activity->title)) {
            return $activity->title;
        }

        return sprintf('Activity #%d', $activity->id);
    }

    private function slotLabel(ActivitySlot $slot): string
    {
        return $this->localizedLabel($slot->slot_label, $slot->slot_key);
    }

    private function groupLabel(ActivitySlot $slot): string
    {
        return $this->localizedLabel($slot->group_label, $slot->group_key);
    }

    /**
     * @param  array<string, mixed>|string|null  $label
     */
    private function localizedLabel(array|string|null $label, string $fallback): string
    {
        if (is_string($label) && trim($label) !== '') {
            return $label;
        }

        if (is_array($label)) {
            $preferred = $label['en'] ?? null;

            if (is_string($preferred) && trim($preferred) !== '') {
                return $preferred;
            }

            foreach ($label as $value) {
                if (is_string($value) && trim($value) !== '') {
                    return $value;
                }
            }
        }

        return $fallback;
    }
}
