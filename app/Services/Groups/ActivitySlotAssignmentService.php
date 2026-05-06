<?php

namespace App\Services\Groups;

use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivitySlotAssignmentService
{
    public function __construct(
        private readonly ActivitySlotBench $slotBench,
        private readonly ActivitySlotAttendanceService $attendanceService,
        private readonly GroupActivityAuditService $activityAuditService,
    ) {}

    /**
     * @param  array<string, mixed>  $fieldSelections
     * @param  array<string, array<string, mixed>>  $fieldDefinitions
     */
    public function assignFromApplication(
        ActivitySlot $targetSlot,
        ActivityApplication $application,
        array $fieldSelections,
        array $fieldDefinitions,
        int $assignedByUserId,
        ?ActivitySlot $sourceSlot = null,
    ): void {
        if (!$application->selected_character_id) {
            throw ValidationException::withMessages([
                'application_id' => 'The application must have a selected character before it can be assigned.',
            ]);
        }

        if ($sourceSlot && (int) $sourceSlot->assigned_character_id !== (int) $application->selected_character_id) {
            throw ValidationException::withMessages([
                'source_slot_id' => 'The source slot does not match the selected application character.',
            ]);
        }

        $applicationAnswers = $application->answers->keyBy('question_key');
        $isTargetBench = $this->slotBench->isBench($targetSlot);
        $isSourceBench = $sourceSlot ? $this->slotBench->isBench($sourceSlot) : false;
        $targetPreviousCharacterId = $targetSlot->assigned_character_id;
        $targetPreviousCharacterName = $targetSlot->assignedCharacter?->name;
        $targetHadDifferentOccupant = $targetPreviousCharacterId !== null
            && (int) $targetPreviousCharacterId !== (int) $application->selected_character_id;

        DB::transaction(function () use (
            $targetSlot,
            $sourceSlot,
            $application,
            $fieldSelections,
            $fieldDefinitions,
            $assignedByUserId,
            $applicationAnswers,
            $isTargetBench,
            $isSourceBench,
            $targetPreviousCharacterId,
            $targetHadDifferentOccupant,
        ) {
            $activity = $targetSlot->activity;
            $displacedApplication = $this->findApplicationForAssignedCharacter($targetSlot);

            if ($sourceSlot && !$isSourceBench && $isTargetBench && $targetSlot->assigned_character_id) {
                throw ValidationException::withMessages([
                    'slot' => 'Promoting a bench player into a filled roster slot must use the reassignment flow.',
                ]);
            }

            $targetSlot->update([
                'assigned_character_id' => $application->selected_character_id,
                'assigned_by_user_id' => $assignedByUserId,
            ]);

            if ($isTargetBench) {
                $this->clearSlotFieldValues($targetSlot);
            } else {
                $this->applySlotFieldSelections($targetSlot, $fieldSelections, $fieldDefinitions, $applicationAnswers->all());
            }

            $application->update([
                'status' => $isTargetBench ? ActivityApplication::STATUS_ON_BENCH : ActivityApplication::STATUS_APPROVED,
                'reviewed_by_user_id' => $assignedByUserId,
                'reviewed_at' => now(),
                'review_reason' => null,
            ]);

            $this->attendanceService->moveOrCreateActiveAssignment(
                $targetSlot,
                (int) $application->selected_character_id,
                $application->id,
                $assignedByUserId,
                $this->attendanceService->buildFieldValueSnapshot($targetSlot),
            );

            if ($sourceSlot && (int) $sourceSlot->id !== (int) $targetSlot->id) {
                if ($isSourceBench && !$isTargetBench && $displacedApplication && $targetHadDifferentOccupant) {
                    $sourceSlot->update([
                        'assigned_character_id' => $displacedApplication->selected_character_id,
                        'assigned_by_user_id' => $assignedByUserId,
                    ]);
                    $this->clearSlotFieldValues($sourceSlot);

                    $displacedApplication->update([
                        'status' => ActivityApplication::STATUS_ON_BENCH,
                        'reviewed_by_user_id' => $assignedByUserId,
                        'reviewed_at' => now(),
                        'review_reason' => null,
                    ]);

                    $this->attendanceService->moveOrCreateActiveAssignment(
                        $sourceSlot,
                        (int) $displacedApplication->selected_character_id,
                        $displacedApplication->id,
                        $assignedByUserId,
                        $this->attendanceService->buildFieldValueSnapshot($sourceSlot),
                    );
                } else {
                    $sourceSlot->update([
                        'assigned_character_id' => null,
                        'assigned_by_user_id' => null,
                    ]);
                    $this->clearSlotFieldValues($sourceSlot);
                }
            } elseif ($displacedApplication && (int) $displacedApplication->id !== (int) $application->id) {
                $displacedApplication->update([
                    'status' => ActivityApplication::STATUS_PENDING,
                    'reviewed_by_user_id' => null,
                    'reviewed_at' => null,
                    'review_reason' => null,
                ]);

                if ($activity && $displacedApplication->selected_character_id) {
                    $this->attendanceService->endActiveAssignment(
                        $activity,
                        (int) $displacedApplication->selected_character_id,
                    );
                }
            }
        });

        $event = match (true) {
            $sourceSlot !== null && $targetHadDifferentOccupant => 'replaced',
            $sourceSlot !== null => 'reassigned',
            $targetPreviousCharacterId !== null && (int) $targetPreviousCharacterId === (int) $application->selected_character_id => 'updated',
            $targetHadDifferentOccupant => 'replaced',
            default => 'assigned',
        };

        $metadata = [
            'application_status' => $application->fresh()?->status,
            'selected_character_name' => $application->selectedCharacter?->name,
            'source_slot_label' => $sourceSlot ? ($sourceSlot->slot_label['en'] ?? $sourceSlot->slot_key) : null,
            'source_group_label' => $sourceSlot ? ($sourceSlot->group_label['en'] ?? $sourceSlot->group_key) : null,
            'displaced_character_name' => $targetPreviousCharacterName,
            'field_assignment_updated' => $event === 'updated',
        ];

        $this->activityAuditService->logRosterEvent(
            $event,
            $targetSlot->fresh(['activity.group', 'assignedCharacter']),
            $assignedByUserId,
            $metadata,
        );
    }

    private function findApplicationForAssignedCharacter(ActivitySlot $slot): ?ActivityApplication
    {
        if (!$slot->assigned_character_id) {
            return null;
        }

        return $slot->activity
            ?->applications()
            ->where('selected_character_id', $slot->assigned_character_id)
            ->whereIn('status', [
                ActivityApplication::STATUS_APPROVED,
                ActivityApplication::STATUS_ON_BENCH,
            ])
            ->latest('reviewed_at')
            ->first();
    }

    private function clearSlotFieldValues(ActivitySlot $slot): void
    {
        foreach ($slot->fieldValues as $fieldValue) {
            $fieldValue->update([
                'value' => null,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $fieldSelections
     * @param  array<string, array<string, mixed>>  $fieldDefinitions
     * @param  array<string, mixed>  $applicationAnswers
     */
    private function applySlotFieldSelections(
        ActivitySlot $slot,
        array $fieldSelections,
        array $fieldDefinitions,
        array $applicationAnswers,
    ): void {
        foreach ($slot->fieldValues as $fieldValue) {
            $definition = $fieldDefinitions[$fieldValue->field_key] ?? null;

            if (!$definition) {
                $fieldValue->update(['value' => null]);
                continue;
            }

            $selectedValue = $fieldSelections[$fieldValue->field_key] ?? null;

            if ($selectedValue === null || $selectedValue === '' || $selectedValue === []) {
                throw ValidationException::withMessages([
                    "field_values.{$fieldValue->field_key}" => 'Please choose a value for every slot field.',
                ]);
            }

            $applicationKey = (string) ($definition['application_key'] ?? '');
            $applicationAnswer = $applicationKey !== '' ? ($applicationAnswers[$applicationKey] ?? null) : null;
            $allowedOptionKeys = $this->normalizeAnswerValues($applicationAnswer?->value);
            $normalizedSelection = $this->normalizeSelection($selectedValue);

            if (count($normalizedSelection) === 0) {
                throw ValidationException::withMessages([
                    "field_values.{$fieldValue->field_key}" => 'Please choose a value for every slot field.',
                ]);
            }

            foreach ($normalizedSelection as $selection) {
                if (!in_array($selection, $allowedOptionKeys, true)) {
                    throw ValidationException::withMessages([
                        "field_values.{$fieldValue->field_key}" => 'Selected slot values must come from the application.',
                    ]);
                }
            }

            $fieldValue->update([
                'value' => $this->resolveStoredValue(
                    $definition,
                    count($normalizedSelection) > 1 ? $normalizedSelection : $normalizedSelection[0],
                ),
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function normalizeAnswerValues(mixed $value): array
    {
        if (is_array($value)) {
            return collect($value)
                ->map(fn ($entry) => is_scalar($entry) ? (string) $entry : null)
                ->filter(fn (?string $entry) => filled($entry))
                ->values()
                ->all();
        }

        if (!filled($value)) {
            return [];
        }

        return [(string) $value];
    }

    /**
     * @return array<int, string>
     */
    private function normalizeSelection(mixed $value): array
    {
        if (is_array($value)) {
            return collect($value)
                ->map(fn ($entry) => is_scalar($entry) ? (string) $entry : null)
                ->filter(fn (?string $entry) => filled($entry))
                ->values()
                ->all();
        }

        if (!filled($value)) {
            return [];
        }

        return [(string) $value];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function resolveStoredValue(array $definition, string|array $selection): ?array
    {
        $optionMap = collect($definition['options'] ?? [])
            ->keyBy(fn (array $option) => (string) ($option['key'] ?? ''));

        if (is_array($selection)) {
            return collect($selection)
                ->map(fn (string $value) => $this->resolveSingleStoredValue($definition, $optionMap->get($value), $value))
                ->filter()
                ->values()
                ->all();
        }

        return $this->resolveSingleStoredValue($definition, $optionMap->get($selection), $selection);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>|null  $option
     * @return array<string, mixed>|null
     */
    private function resolveSingleStoredValue(array $definition, ?array $option, string $selection): ?array
    {
        if (!$option) {
            return null;
        }

        $source = $definition['source'] ?? null;
        $label = $option['label'] ?? null;
        $meta = is_array($option['meta'] ?? null) ? $option['meta'] : [];

        return match ($source) {
            'character_classes' => [
                'id' => (int) $selection,
                'name' => is_array($label) ? ($label['en'] ?? reset($label) ?: '') : (string) $label,
                'role' => $meta['role'] ?? null,
                'shorthand' => $meta['shorthand'] ?? null,
            ],
            'phantom_jobs' => [
                'id' => (int) $selection,
                'name' => is_array($label) ? ($label['en'] ?? reset($label) ?: '') : (string) $label,
            ],
            'static_options' => [
                'key' => $selection,
                'label' => $label,
            ],
            default => [
                'key' => $selection,
                'label' => $label,
            ],
        };
    }
}
