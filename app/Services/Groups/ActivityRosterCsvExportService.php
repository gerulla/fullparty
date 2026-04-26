<?php

namespace App\Services\Groups;

use App\Http\Controllers\Concerns\InteractsWithActivitySlotFieldDisplay;
use App\Models\Activity;
use App\Models\ActivitySlot;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ActivityRosterCsvExportService
{
    use InteractsWithActivitySlotFieldDisplay;

    public function __construct(
        private readonly ActivitySlotBench $slotBench,
    ) {}

    public function filename(Activity $activity): string
    {
        $baseName = filled($activity->title)
            ? (string) $activity->title
            : sprintf('activity-%d-roster', $activity->id);

        return sprintf('%s.csv', Str::slug($baseName) ?: sprintf('activity-%d-roster', $activity->id));
    }

    /**
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>}
     */
    public function build(Activity $activity): array
    {
        $activity->loadMissing([
            'slots.assignedCharacter',
            'slots.assignments',
            'slots.fieldValues',
        ]);

        $fieldColumns = $this->fieldColumns($activity->slots);
        $headers = [
            __('groups.activities.management.roster.csv_headers.group'),
            __('groups.activities.management.roster.csv_headers.slot'),
            __('groups.activities.management.roster.csv_headers.status'),
            __('groups.activities.management.roster.csv_headers.character'),
            __('groups.activities.management.roster.csv_headers.world'),
            __('groups.activities.management.roster.csv_headers.datacenter'),
            ...$fieldColumns->pluck('label')->all(),
        ];

        $rows = [];
        $groupedSlots = $activity->slots
            ->sortBy('sort_order')
            ->groupBy(fn (ActivitySlot $slot) => $slot->group_key);

        foreach ($groupedSlots as $slots) {
            $groupLabel = $this->localizedText($slots->first()?->group_label)
                ?: $this->localizedText($slots->first()?->slot_label)
                ?: __('groups.activities.management.roster.title');

            $rows[] = [$groupLabel];
            $rows[] = $headers;

            foreach ($slots as $slot) {
                $rows[] = [
                    $groupLabel,
                    $this->localizedText($slot->slot_label) ?: $slot->slot_key,
                    $this->slotStatusLabel($slot),
                    $slot->assignedCharacter?->name ?? '',
                    $slot->assignedCharacter?->world ?? '',
                    $slot->assignedCharacter?->datacenter ?? '',
                    ...$fieldColumns->map(fn (array $column) => $this->slotFieldDisplayValue($slot, $column['key']))->all(),
                ];
            }

            $rows[] = [];
        }

        if (count($rows) > 0 && $rows[array_key_last($rows)] === []) {
            array_pop($rows);
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    /**
     * @param  Collection<int, ActivitySlot>  $slots
     * @return Collection<int, array{key: string, label: string}>
     */
    private function fieldColumns(Collection $slots): Collection
    {
        return $slots
            ->reject(fn (ActivitySlot $slot) => $this->slotBench->isBench($slot))
            ->flatMap(fn (ActivitySlot $slot) => $slot->fieldValues)
            ->unique('field_key')
            ->map(fn ($fieldValue) => [
                'key' => $fieldValue->field_key,
                'label' => $this->localizedText($fieldValue->field_label) ?: $fieldValue->field_key,
            ])
            ->values();
    }

    private function slotFieldDisplayValue(ActivitySlot $slot, string $fieldKey): string
    {
        $fieldValue = $slot->fieldValues->firstWhere('field_key', $fieldKey);
        $displayValue = $this->resolveSlotFieldDisplayValue($fieldValue);

        return $this->stringifyDisplayValue($displayValue);
    }

    private function slotStatusLabel(ActivitySlot $slot): string
    {
        if (!$slot->assigned_character_id) {
            return __('groups.activities.management.roster.open');
        }

        $attendanceAssignment = $slot->assignments
            ->filter(fn ($assignment) => $assignment->ended_at === null
                && (int) $assignment->character_id === (int) $slot->assigned_character_id)
            ->sortByDesc('assigned_at')
            ->first();

        return match ($attendanceAssignment?->attendance_status) {
            'checked_in' => __('groups.activities.management.roster.checked_in'),
            default => __('groups.activities.management.roster.assigned'),
        };
    }

    private function stringifyDisplayValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            $localized = $this->localizedText($value);

            if ($localized !== '') {
                return $localized;
            }

            return collect($value)
                ->filter(fn ($entry) => filled($entry))
                ->map(fn ($entry) => (string) $entry)
                ->implode(', ');
        }

        return filled($value) ? (string) $value : '';
    }

    /**
     * @param  array<string, string|null|mixed>|null  $value
     */
    private function localizedText(?array $value): string
    {
        if (!$value) {
            return '';
        }

        foreach ([app()->getLocale(), config('app.fallback_locale'), 'en'] as $locale) {
            if (is_string($locale) && filled($value[$locale] ?? null)) {
                return trim((string) $value[$locale]);
            }
        }

        foreach ($value as $translation) {
            if (filled($translation)) {
                return trim((string) $translation);
            }
        }

        return '';
    }
}
