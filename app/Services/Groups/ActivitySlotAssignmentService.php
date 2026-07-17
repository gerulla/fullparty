<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use App\Models\Character;
use App\Services\Notifications\AssignmentNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivitySlotAssignmentService
{
    private const ANY_OPTION_KEY = 'any';

    public function __construct(
        private readonly ActivitySlotBench $slotBench,
        private readonly ActivitySlotKind $slotKind,
        private readonly ActivitySlotAttendanceService $attendanceService,
        private readonly ActivitySlotDesignationService $slotDesignationService,
        private readonly GroupActivityAuditService $activityAuditService,
        private readonly AssignmentNotificationService $assignmentNotificationService,
        private readonly BozjaHolsterPairService $bozjaHolsterPairService,
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
        bool $ignoreApplicationChoices = false,
    ): void {
        $targetSlot->loadMissing('fieldValues');

        if (! $application->selected_character_id) {
            throw ValidationException::withMessages([
                'application_id' => 'The application must have a selected character before it can be assigned.',
            ]);
        }

        if ($sourceSlot && (int) $sourceSlot->assigned_character_id !== (int) $application->selected_character_id) {
            throw ValidationException::withMessages([
                'source_slot_id' => 'The source slot does not match the selected application character.',
            ]);
        }

        $conflictingSlot = $targetSlot->activity?->slots()
            ->where('assigned_character_id', $application->selected_character_id)
            ->when($sourceSlot, fn ($query) => $query->where('id', '!=', $sourceSlot->id))
            ->where('id', '!=', $targetSlot->id)
            ->first();

        if ($conflictingSlot) {
            throw ValidationException::withMessages([
                'application_id' => 'This character is already assigned to another slot in this run.',
            ]);
        }

        $applicationAnswers = $application->answers->keyBy('question_key');
        $isTargetBench = $this->slotBench->isBench($targetSlot);
        $isSourceBench = $sourceSlot ? $this->slotBench->isBench($sourceSlot) : false;
        $targetCanCarryDesignation = $this->slotKind->isMainRoster($targetSlot);
        $targetPreviousCharacterId = $targetSlot->assigned_character_id;
        $targetPreviousCharacterName = $targetSlot->assignedCharacter?->name;
        $targetHadDifferentOccupant = $targetPreviousCharacterId !== null
            && (int) $targetPreviousCharacterId !== (int) $application->selected_character_id;
        $displacedApplication = $this->findApplicationForAssignedCharacter($targetSlot);
        $originalTargetFieldValueSnapshot = $this->attendanceService->buildFieldValueSnapshot($targetSlot);

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
            $targetCanCarryDesignation,
            $targetHadDifferentOccupant,
            $displacedApplication,
            $ignoreApplicationChoices,
        ) {
            $activity = $targetSlot->activity;
            $targetDesignationState = $this->designationState($targetSlot);
            $sourceDesignationState = $sourceSlot ? $this->designationState($sourceSlot) : $this->emptyDesignationState();

            if ($sourceSlot && ! $isSourceBench && $isTargetBench && $targetSlot->assigned_character_id) {
                throw ValidationException::withMessages([
                    'slot' => 'Promoting a bench player into a filled roster slot must use the reassignment flow.',
                ]);
            }

            $targetSlot->update([
                'assigned_character_id' => $application->selected_character_id,
                'assigned_by_user_id' => $assignedByUserId,
            ]);
            $this->applyDesignationState(
                $targetSlot,
                $sourceSlot ? $sourceDesignationState : $this->emptyDesignationState(),
                $targetCanCarryDesignation,
            );

            if ($isTargetBench) {
                $this->clearSlotFieldValues($targetSlot);
            } else {
                $this->applySlotFieldSelections(
                    $targetSlot,
                    $fieldSelections,
                    $fieldDefinitions,
                    $applicationAnswers->all(),
                    $application->selectedCharacter,
                    $ignoreApplicationChoices,
                );
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
                if ($isSourceBench && ! $isTargetBench && $displacedApplication && $targetHadDifferentOccupant) {
                    $sourceSlot->update([
                        'assigned_character_id' => $displacedApplication->selected_character_id,
                        'assigned_by_user_id' => $assignedByUserId,
                    ]);
                    $this->applyDesignationState($sourceSlot, $targetDesignationState, false);
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
                    $this->applyDesignationState($sourceSlot, $this->emptyDesignationState(), false);
                    $this->clearSlotFieldValues($sourceSlot);
                }
            } elseif ($displacedApplication && (int) $displacedApplication->id !== (int) $application->id) {
                $this->applyDesignationState($targetSlot, $this->emptyDesignationState(), $targetCanCarryDesignation);
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
            'application_choices_ignored' => $ignoreApplicationChoices,
        ];

        $this->activityAuditService->logRosterEvent(
            $event,
            $targetSlot->fresh(['activity.group', 'assignedCharacter']),
            $assignedByUserId,
            $metadata,
        );

        $slotsNeedingCleanup = array_filter([
            $targetSlot,
            $sourceSlot,
        ]);

        if ($slotsNeedingCleanup !== []) {
            $this->slotDesignationService->clearInvalidDesignations($slotsNeedingCleanup, $assignedByUserId);
        }

        $updatedTargetSlot = $targetSlot->fresh(['fieldValues']);
        $targetFieldValuesChanged = $updatedTargetSlot
            ? $this->attendanceService->buildFieldValueSnapshot($updatedTargetSlot) !== $originalTargetFieldValueSnapshot
            : false;

        if (
            $targetSlot->activity?->status !== Activity::STATUS_ASSIGNED
            || ($event === 'updated' && ! $targetFieldValuesChanged)
        ) {
            return;
        }

        $updatedApplication = $application->fresh(['activity.group', 'user', 'selectedCharacter']);

        if ($updatedApplication && $updatedTargetSlot) {
            $this->assignmentNotificationService->notifyPlacementChanged(
                $updatedApplication,
                $updatedTargetSlot,
                $assignedByUserId,
            );
        }

        if ($displacedApplication && (int) $displacedApplication->id !== (int) $application->id) {
            $updatedDisplacedApplication = $displacedApplication->fresh(['activity.group', 'user', 'selectedCharacter']);
            $displacedSlot = $updatedDisplacedApplication?->status === ActivityApplication::STATUS_ON_BENCH && $sourceSlot
                ? $sourceSlot->fresh()
                : null;

            if ($updatedDisplacedApplication) {
                $this->assignmentNotificationService->notifyPlacementChanged(
                    $updatedDisplacedApplication,
                    $displacedSlot,
                    $assignedByUserId,
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $fieldSelections
     * @param  array<string, array<string, mixed>>  $fieldDefinitions
     */
    public function assignManualCharacter(
        ActivitySlot $targetSlot,
        Character $character,
        array $fieldSelections,
        array $fieldDefinitions,
        int $assignedByUserId,
        ?ActivitySlot $sourceSlot = null,
    ): void {
        $targetSlot->loadMissing('fieldValues');
        $character->loadMissing(['user', 'classes', 'phantomJobs']);

        $activity = $targetSlot->activity;

        if (! $activity) {
            throw ValidationException::withMessages([
                'slot' => 'The selected slot is not attached to an activity.',
            ]);
        }

        if ($sourceSlot && (int) $sourceSlot->assigned_character_id !== (int) $character->id) {
            throw ValidationException::withMessages([
                'source_slot_id' => 'The source slot does not match the selected character.',
            ]);
        }

        if (
            $targetSlot->assigned_character_id !== null
            && (int) $targetSlot->assigned_character_id !== (int) $character->id
        ) {
            throw ValidationException::withMessages([
                'slot' => 'Manual assignment is only available for empty slots or the currently assigned manual character.',
            ]);
        }

        $conflictingSlot = $activity->slots()
            ->where('assigned_character_id', $character->id)
            ->when($sourceSlot, fn ($query) => $query->where('id', '!=', $sourceSlot->id))
            ->where('id', '!=', $targetSlot->id)
            ->first();

        if ($conflictingSlot) {
            throw ValidationException::withMessages([
                'character_id' => 'This character is already assigned to another slot in this run.',
            ]);
        }

        $activeApplicationExists = $activity->applications()
            ->where('selected_character_id', $character->id)
            ->whereIn('status', ActivityApplication::ACTIVE_STATUSES)
            ->exists();

        if ($activeApplicationExists) {
            throw ValidationException::withMessages([
                'character_id' => 'This character already has an active application for this run.',
            ]);
        }

        $missingAssignmentExists = ActivitySlotAssignment::query()
            ->where('activity_id', $activity->id)
            ->where('character_id', $character->id)
            ->where('attendance_status', ActivitySlotAssignment::STATUS_MISSING)
            ->exists();

        if ($missingAssignmentExists) {
            throw ValidationException::withMessages([
                'character_id' => 'This character is currently marked missing for this run and must be restored before being assigned again.',
            ]);
        }

        $targetPreviousCharacterId = $targetSlot->assigned_character_id;
        $originalTargetFieldValueSnapshot = $this->attendanceService->buildFieldValueSnapshot($targetSlot);
        $isTargetBench = $this->slotBench->isBench($targetSlot);
        $targetCanCarryDesignation = $this->slotKind->isMainRoster($targetSlot);

        DB::transaction(function () use (
            $targetSlot,
            $sourceSlot,
            $character,
            $fieldSelections,
            $fieldDefinitions,
            $assignedByUserId,
            $isTargetBench,
            $targetCanCarryDesignation,
            $activity,
        ) {
            $targetDesignationState = $this->designationState($targetSlot);
            $sourceDesignationState = $sourceSlot ? $this->designationState($sourceSlot) : $this->emptyDesignationState();

            $targetSlot->update([
                'assigned_character_id' => $character->id,
                'assigned_by_user_id' => $assignedByUserId,
            ]);
            $this->applyDesignationState(
                $targetSlot,
                $sourceSlot ? $sourceDesignationState : $targetDesignationState,
                $targetCanCarryDesignation,
            );

            if ($isTargetBench) {
                $this->clearSlotFieldValues($targetSlot);
            } else {
                $this->applyManualSlotFieldSelections($targetSlot, $fieldSelections, $fieldDefinitions, $character);
            }

            $this->attendanceService->moveOrCreateActiveAssignment(
                $targetSlot,
                (int) $character->id,
                null,
                $assignedByUserId,
                $this->attendanceService->buildFieldValueSnapshot($targetSlot),
            );

            if ($sourceSlot && (int) $sourceSlot->id !== (int) $targetSlot->id) {
                $sourceSlot->update([
                    'assigned_character_id' => null,
                    'assigned_by_user_id' => null,
                ]);
                $this->applyDesignationState($sourceSlot, $this->emptyDesignationState(), false);
                $this->clearSlotFieldValues($sourceSlot);

                $sourceAssignment = ActivitySlotAssignment::query()
                    ->where('activity_id', $activity->id)
                    ->where('character_id', $character->id)
                    ->whereNull('ended_at')
                    ->latest('assigned_at')
                    ->first();

                if ($sourceAssignment) {
                    $sourceAssignment->update([
                        'assignment_source' => ActivitySlotAssignment::SOURCE_MANUAL,
                    ]);
                }
            }
        });

        $updatedTargetSlot = $targetSlot->fresh(['fieldValues']);
        $targetFieldValuesChanged = $updatedTargetSlot
            ? $this->attendanceService->buildFieldValueSnapshot($updatedTargetSlot) !== $originalTargetFieldValueSnapshot
            : false;
        $event = $targetPreviousCharacterId === null ? 'manual_assigned' : 'manual_updated';

        $this->activityAuditService->logRosterEvent(
            $event,
            $targetSlot->fresh(['activity.group', 'assignedCharacter']),
            $assignedByUserId,
            [
                'selected_character_name' => $character->name,
                'assignment_source' => ActivitySlotAssignment::SOURCE_MANUAL,
                'source_slot_label' => $sourceSlot ? ($sourceSlot->slot_label['en'] ?? $sourceSlot->slot_key) : null,
                'source_group_label' => $sourceSlot ? ($sourceSlot->group_label['en'] ?? $sourceSlot->group_key) : null,
                'field_assignment_updated' => $targetPreviousCharacterId === $character->id,
            ],
        );

        $slotsNeedingCleanup = array_filter([
            $targetSlot,
            $sourceSlot,
        ]);

        if ($slotsNeedingCleanup !== []) {
            $this->slotDesignationService->clearInvalidDesignations($slotsNeedingCleanup, $assignedByUserId);
        }

        if (
            $targetSlot->activity?->status !== Activity::STATUS_ASSIGNED
            || ($targetPreviousCharacterId === $character->id && ! $targetFieldValuesChanged)
        ) {
            return;
        }

        if ($updatedTargetSlot) {
            $this->assignmentNotificationService->notifyManualPlacementChanged(
                $activity,
                $character->fresh('user') ?? $character,
                $updatedTargetSlot,
                $assignedByUserId,
            );
        }
    }

    private function findApplicationForAssignedCharacter(ActivitySlot $slot): ?ActivityApplication
    {
        if (! $slot->assigned_character_id) {
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
     */
    private function applyManualSlotFieldSelections(
        ActivitySlot $slot,
        array $fieldSelections,
        array $fieldDefinitions,
        Character $character,
    ): void {
        foreach ($slot->fieldValues as $fieldValue) {
            $definition = $fieldDefinitions[$fieldValue->field_key] ?? null;

            if (! $definition) {
                $fieldValue->update(['value' => null]);

                continue;
            }

            $selectedValue = $fieldSelections[$fieldValue->field_key] ?? null;

            if ($selectedValue === null || $selectedValue === '' || $selectedValue === []) {
                throw ValidationException::withMessages([
                    "field_values.{$fieldValue->field_key}" => 'Please choose a value for every slot field.',
                ]);
            }

            if ($this->isHolsterPairField($definition)) {
                $fieldValue->update([
                    'value' => $this->resolveHolsterPairStoredValue(
                        $definition,
                        $selectedValue,
                        "field_values.{$fieldValue->field_key}",
                    ),
                ]);

                continue;
            }

            $normalizedSelection = $this->normalizeSelection($selectedValue);

            if (count($normalizedSelection) === 0) {
                throw ValidationException::withMessages([
                    "field_values.{$fieldValue->field_key}" => 'Please choose a value for every slot field.',
                ]);
            }

            $allowedOptionKeys = collect($definition['options'] ?? [])
                ->map(fn (array $option) => (string) ($option['key'] ?? ''))
                ->filter()
                ->values()
                ->all();

            foreach ($normalizedSelection as $selection) {
                if (! in_array($selection, $allowedOptionKeys, true)) {
                    throw ValidationException::withMessages([
                        "field_values.{$fieldValue->field_key}" => 'Selected slot values must be valid options for this field.',
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
     * @param  array<string, mixed>  $fieldSelections
     * @param  array<string, array<string, mixed>>  $fieldDefinitions
     * @param  array<string, mixed>  $applicationAnswers
     */
    private function applySlotFieldSelections(
        ActivitySlot $slot,
        array $fieldSelections,
        array $fieldDefinitions,
        array $applicationAnswers,
        ?Character $character,
        bool $ignoreApplicationChoices,
    ): void {
        foreach ($slot->fieldValues as $fieldValue) {
            $definition = $fieldDefinitions[$fieldValue->field_key] ?? null;

            if (! $definition) {
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

            if ($this->isHolsterPairField($definition)) {
                $pair = $this->resolveHolsterPairStoredValue(
                    $definition,
                    $selectedValue,
                    "field_values.{$fieldValue->field_key}",
                );
                $submittedPairKeys = collect($this->bozjaHolsterPairService->normalizePairs($applicationAnswer?->value))
                    ->map(fn (array $submittedPair) => $this->bozjaHolsterPairService->pairKey($submittedPair));

                if (! $submittedPairKeys->contains($this->bozjaHolsterPairService->pairKey($pair))) {
                    throw ValidationException::withMessages([
                        "field_values.{$fieldValue->field_key}" => 'Selected holster pairs must come from the application.',
                    ]);
                }

                $fieldValue->update(['value' => $pair]);

                continue;
            }

            $choicesIgnoredForField = $ignoreApplicationChoices && $this->canIgnoreApplicationChoicesForField($definition);
            $allowedOptionKeys = $choicesIgnoredForField
                ? $this->allowedOptionKeysWhenIgnoringApplicationChoices($definition, $applicationAnswer?->value, $character)
                : $this->allowedOptionKeysForApplicationAnswer($definition, $applicationAnswer?->value);
            $normalizedSelection = $this->normalizeSelection($selectedValue);

            if (count($normalizedSelection) === 0) {
                throw ValidationException::withMessages([
                    "field_values.{$fieldValue->field_key}" => 'Please choose a value for every slot field.',
                ]);
            }

            foreach ($normalizedSelection as $selection) {
                if (! in_array($selection, $allowedOptionKeys, true)) {
                    throw ValidationException::withMessages([
                        "field_values.{$fieldValue->field_key}" => $choicesIgnoredForField
                            ? $this->ignoredApplicationChoicesValidationMessage($definition)
                            : 'Selected slot values must come from the application.',
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
     * @param  array<string, mixed>  $definition
     */
    private function canIgnoreApplicationChoicesForField(array $definition): bool
    {
        return in_array($definition['source'] ?? null, ['character_classes', 'phantom_jobs', 'raid_positions'], true)
            || $this->isStaticRaidPositionField($definition);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function isHolsterPairField(array $definition): bool
    {
        return ($definition['source'] ?? null) === 'bozja_holsters'
            && ($definition['type'] ?? null) === 'holster_pair';
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{prepop_id: int, refill_id: int, prepop_label: mixed, refill_label: mixed}
     */
    private function resolveHolsterPairStoredValue(array $definition, mixed $selection, string $attribute): array
    {
        $pair = $this->bozjaHolsterPairService->normalizePair($selection);

        if ($pair === null) {
            throw ValidationException::withMessages([
                $attribute => 'Select both a Prepop and Refill holster.',
            ]);
        }

        $options = collect($definition['options'] ?? [])->keyBy(
            fn (array $option) => (string) ($option['key'] ?? ''),
        );
        $prepop = $options->get((string) $pair['prepop_id']);
        $refill = $options->get((string) $pair['refill_id']);

        if (! is_array($prepop)
            || ! is_array($refill)
            || ($prepop['meta']['holster_type'] ?? null) !== 'prepop'
            || ($refill['meta']['holster_type'] ?? null) !== 'refill'
            || (int) ($refill['meta']['parent_holster_id'] ?? 0) !== $pair['prepop_id']) {
            throw ValidationException::withMessages([
                $attribute => 'Select a valid Prepop and Refill holster pair.',
            ]);
        }

        return [
            ...$pair,
            'prepop_label' => $prepop['label'] ?? null,
            'refill_label' => $refill['label'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function ignoredApplicationChoicesValidationMessage(array $definition): string
    {
        if ($this->isRaidPositionField($definition)) {
            return 'Selected raid position values must be valid options for this field.';
        }

        return 'Selected class and phantom job values must be available to the character.';
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<int, string>
     */
    private function allowedOptionKeysWhenIgnoringApplicationChoices(
        array $definition,
        mixed $applicationAnswer,
        ?Character $character,
    ): array {
        if ($this->isRaidPositionField($definition)) {
            return $this->definedOptionKeys($definition);
        }

        if (! $character) {
            return [];
        }

        $availableOptionKeys = match ($definition['source'] ?? null) {
            'character_classes' => $character->classes
                ->filter(fn ($characterClass) => (int) ($characterClass->pivot?->level ?? 0) > 0)
                ->pluck('id'),
            'phantom_jobs' => $character->phantomJobs
                ->filter(fn ($phantomJob) => (int) ($phantomJob->pivot?->current_level ?? 0) > 0)
                ->pluck('id'),
            default => null,
        };

        if ($availableOptionKeys === null) {
            return $this->allowedOptionKeysForApplicationAnswer($definition, $applicationAnswer);
        }

        $definedOptionKeys = $this->definedOptionKeys($definition);

        return $availableOptionKeys
            ->map(fn ($id) => (string) $id)
            ->filter(fn (string $id) => in_array($id, $definedOptionKeys, true))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function isRaidPositionField(array $definition): bool
    {
        return ($definition['source'] ?? null) === 'raid_positions'
            || $this->isStaticRaidPositionField($definition);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function isStaticRaidPositionField(array $definition): bool
    {
        if (($definition['source'] ?? null) !== 'static_options') {
            return false;
        }

        $key = strtolower((string) ($definition['key'] ?? ''));
        $applicationKey = strtolower((string) ($definition['application_key'] ?? ''));

        return str_contains($key, 'position') || str_contains($applicationKey, 'position');
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<int, string>
     */
    private function definedOptionKeys(array $definition): array
    {
        return collect($definition['options'] ?? [])
            ->map(fn (array $option) => (string) ($option['key'] ?? $option['value'] ?? ''))
            ->filter(fn (string $key) => $key !== '' && $key !== self::ANY_OPTION_KEY)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<int, string>
     */
    private function allowedOptionKeysForApplicationAnswer(array $definition, mixed $value): array
    {
        $answerOptionKeys = $this->normalizeAnswerValues($value);

        if (! in_array(self::ANY_OPTION_KEY, $answerOptionKeys, true)) {
            return $answerOptionKeys;
        }

        return collect($definition['options'] ?? [])
            ->map(fn (array $option) => (string) ($option['key'] ?? $option['value'] ?? ''))
            ->filter(fn (string $key) => $key !== '' && $key !== self::ANY_OPTION_KEY)
            ->values()
            ->all();
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

        if (! filled($value)) {
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

        if (! filled($value)) {
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
        if (! $option) {
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
            'raid_positions' => [
                'key' => $selection,
                'label' => $label,
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

    /**
     * @return array{is_host: bool, is_raid_leader: bool}
     */
    private function designationState(ActivitySlot $slot): array
    {
        return [
            'is_host' => (bool) $slot->is_host,
            'is_raid_leader' => (bool) $slot->is_raid_leader,
        ];
    }

    /**
     * @return array{is_host: bool, is_raid_leader: bool}
     */
    private function emptyDesignationState(): array
    {
        return [
            'is_host' => false,
            'is_raid_leader' => false,
        ];
    }

    /**
     * @param  array{is_host: bool, is_raid_leader: bool}  $designationState
     */
    private function applyDesignationState(ActivitySlot $slot, array $designationState, bool $canCarryDesignation): void
    {
        $slot->update([
            'is_host' => $canCarryDesignation ? $designationState['is_host'] : false,
            'is_raid_leader' => $canCarryDesignation ? $designationState['is_raid_leader'] : false,
        ]);
    }
}
