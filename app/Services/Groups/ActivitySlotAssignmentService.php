<?php

namespace App\Services\Groups;

use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivitySlotAssignmentService
{
    /**
     * @param  array<string, mixed>  $fieldSelections
     * @param  array<string, array<string, mixed>>  $fieldDefinitions
     */
    public function assignFromApplication(
        ActivitySlot $slot,
        ActivityApplication $application,
        array $fieldSelections,
        array $fieldDefinitions,
        int $assignedByUserId,
    ): void {
        if (!$application->selected_character_id) {
            throw ValidationException::withMessages([
                'application_id' => 'The application must have a selected character before it can be assigned.',
            ]);
        }

        $applicationAnswers = $application->answers->keyBy('question_key');

        DB::transaction(function () use ($slot, $application, $fieldSelections, $fieldDefinitions, $assignedByUserId, $applicationAnswers) {
            $slot->update([
                'assigned_character_id' => $application->selected_character_id,
                'assigned_by_user_id' => $assignedByUserId,
            ]);

            $application->update([
                'status' => ActivityApplication::STATUS_APPROVED,
                'reviewed_by_user_id' => $assignedByUserId,
                'reviewed_at' => now(),
            ]);

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
                $applicationAnswer = $applicationKey !== '' ? $applicationAnswers->get($applicationKey) : null;
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
        });
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
