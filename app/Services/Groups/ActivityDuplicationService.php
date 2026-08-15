<?php

namespace App\Services\Groups;

use App\DTOs\QuotaCheck;
use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\User;
use App\Services\Quotas\QuotaService;
use App\Support\Quotas\QuotaKey;
use Carbon\CarbonImmutable;

class ActivityDuplicationService
{
    public function __construct(
        private readonly ActivitySlotAttendanceService $attendanceService,
        private readonly GroupActivityAuditService $activityAuditService,
        private readonly QuotaService $quotaService,
    ) {}

    public function duplicate(
        Activity $source,
        User $actor,
        string $title,
        CarbonImmutable $startsAt,
        string $status,
        bool $copyBench,
        bool $copyFillIns,
    ): Activity {
        $source->load([
            'group',
            'activityType',
            'activityTypeVersion',
            'slots.fieldValues',
            'slots.compositionHints',
        ]);

        $quotaChecks = [
            new QuotaCheck(QuotaKey::FUTURE_RUNS, $source->group),
            new QuotaCheck(QuotaKey::RUNS_PER_DAY, $source->group, ['starts_at' => $startsAt]),
        ];

        return $this->quotaService->run($quotaChecks, function () use (
            $source,
            $actor,
            $title,
            $startsAt,
            $status,
            $copyBench,
            $copyFillIns,
        ): Activity {
            $duplicate = $source->group()->firstOrFail()->activities()->create([
                'activity_type_id' => $source->activity_type_id,
                'activity_type_version_id' => $source->activity_type_version_id,
                'organized_by_user_id' => $source->organized_by_user_id,
                'organized_by_character_id' => $source->organized_by_character_id,
                'status' => $status,
                'title' => $title,
                'description' => $source->description,
                'notes' => $source->notes,
                'starts_at' => $startsAt,
                'duration_hours' => $source->duration_hours,
                'datacenter' => $source->datacenter,
                'intensity' => $source->intensity,
                'min_item_level' => $source->min_item_level,
                'beginner_friendly' => $source->beginner_friendly,
                'run_style' => $source->run_style,
                'target_prog_point_key' => $source->target_prog_point_key,
                'is_public' => $source->is_public,
                'needs_application' => $source->needs_application,
                'allow_guest_applications' => $source->allow_guest_applications,
                'secret_key' => null,
                'settings' => [],
                'progress_entry_mode' => null,
                'progress_link_url' => null,
                'progress_notes' => null,
                'furthest_progress_key' => null,
                'furthest_progress_percent' => null,
                'is_completed' => false,
                'completed_at' => null,
                'progress_recorded_by_user_id' => null,
                'progress_recorded_at' => null,
            ]);

            foreach ($source->slots as $sourceSlot) {
                if ($sourceSlot->slot_kind === ActivitySlot::SLOT_KIND_FILL_IN && ! $copyFillIns) {
                    continue;
                }

                $copyAssignment = $sourceSlot->assigned_character_id !== null
                    && match ($sourceSlot->slot_kind) {
                        ActivitySlot::SLOT_KIND_BENCH => $copyBench,
                        ActivitySlot::SLOT_KIND_FILL_IN => $copyFillIns,
                        default => true,
                    };

                $targetSlot = $duplicate->slots()->create([
                    'slot_kind' => $sourceSlot->slot_kind,
                    'group_key' => $sourceSlot->group_key,
                    'group_label' => $sourceSlot->group_label,
                    'filled_group_key' => $sourceSlot->filled_group_key,
                    'filled_group_label' => $sourceSlot->filled_group_label,
                    'slot_key' => $sourceSlot->slot_key,
                    'slot_label' => $sourceSlot->slot_label,
                    'position_in_group' => $sourceSlot->position_in_group,
                    'sort_order' => $sourceSlot->sort_order,
                    'assigned_character_id' => $copyAssignment ? $sourceSlot->assigned_character_id : null,
                    'assigned_by_user_id' => $copyAssignment ? $actor->id : null,
                    'is_host' => $copyAssignment && $sourceSlot->is_host,
                    'is_raid_leader' => $copyAssignment && $sourceSlot->is_raid_leader,
                ]);

                foreach ($sourceSlot->fieldValues as $sourceFieldValue) {
                    $targetSlot->fieldValues()->create([
                        'field_key' => $sourceFieldValue->field_key,
                        'field_label' => $sourceFieldValue->field_label,
                        'field_type' => $sourceFieldValue->field_type,
                        'source' => $sourceFieldValue->source,
                        'value' => $copyAssignment ? $sourceFieldValue->value : null,
                    ]);
                }

                foreach ($sourceSlot->compositionHints as $sourceHint) {
                    $targetSlot->compositionHints()->create([
                        'hint_type' => $sourceHint->hint_type,
                        'hint_key' => $sourceHint->hint_key,
                        'role_key' => $sourceHint->role_key,
                        'character_class_id' => $sourceHint->character_class_id,
                        'sort_order' => $sourceHint->sort_order,
                    ]);
                }

                if (! $copyAssignment) {
                    continue;
                }

                $targetSlot->load('fieldValues');
                $this->attendanceService->moveOrCreateActiveAssignment(
                    slot: $targetSlot,
                    characterId: (int) $sourceSlot->assigned_character_id,
                    applicationId: null,
                    assignedByUserId: $actor->id,
                    fieldValueSnapshot: $this->attendanceService->buildFieldValueSnapshot($targetSlot),
                );
            }

            $this->materializeProgressMilestones($duplicate, $source);
            $this->activityAuditService->logActivityCreated($duplicate, $actor);

            return $duplicate;
        });
    }

    private function materializeProgressMilestones(Activity $duplicate, Activity $source): void
    {
        $milestones = $source->activityTypeVersion?->progress_schema['milestones'] ?? [];

        foreach ($milestones as $index => $milestone) {
            $duplicate->progressMilestones()->create([
                'milestone_key' => (string) ($milestone['key'] ?? ('milestone-'.($index + 1))),
                'milestone_label' => is_array($milestone['label'] ?? null)
                    ? $milestone['label']
                    : ['en' => (string) ($milestone['key'] ?? 'Milestone')],
                'sort_order' => (int) ($milestone['order'] ?? $index + 1),
                'kills' => 0,
                'best_progress_percent' => null,
                'source' => null,
                'notes' => null,
            ]);
        }
    }
}
