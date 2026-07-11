<?php

namespace App\Services\Groups;

use App\Http\Controllers\Concerns\InteractsWithActivitySlotFieldDisplay;
use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use App\Models\ActivitySlotFieldValue;
use App\Models\ActivityTypeVersion;
use App\Services\Groups\ApplicantQueue\ApplicationAnswerPresenter;
use Illuminate\Support\Collection;

class ActivitySlotSerializer
{
    use InteractsWithActivitySlotFieldDisplay;

    /**
     * @var array<int, array<int, array<string, mixed>>>
     */
    private array $slotSchemaByActivityId = [];

    public function __construct(
        private readonly ActivitySlotBench $slotBench,
        private readonly ActivitySlotStateTokenService $slotStateTokenService,
        private readonly ApplicationAnswerPresenter $applicationAnswerPresenter,
        private readonly ActivitySlotApplicationMatchService $applicationMatchService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function serialize(ActivitySlot $slot): array
    {
        $attendanceAssignment = $this->slotStateTokenService->resolveActiveAssignment($slot);

        return [
            'id' => $slot->id,
            'group_key' => $slot->group_key,
            'group_label' => $slot->group_label,
            'slot_key' => $slot->slot_key,
            'slot_label' => $slot->slot_label,
            'position_in_group' => $slot->position_in_group,
            'sort_order' => $slot->sort_order,
            'is_bench' => $this->slotBench->isBench($slot),
            'is_host' => (bool) $slot->is_host,
            'is_raid_leader' => (bool) $slot->is_raid_leader,
            'assigned_character_id' => $slot->assigned_character_id,
            'assignment_source' => $attendanceAssignment?->assignment_source,
            'assignment_application_id' => $attendanceAssignment?->application_id,
            'can_return_to_queue' => $attendanceAssignment?->application_id !== null,
            'attendance_status' => $attendanceAssignment?->attendance_status ?? ($slot->assigned_character_id ? 'assigned' : null),
            'checked_in_at' => $attendanceAssignment?->checked_in_at?->toIso8601String(),
            'state_token' => $this->slotStateTokenService->generate($slot),
            'composition_hints' => $slot->compositionHints->map(fn ($hint) => [
                'id' => $hint->id,
                'type' => $hint->hint_type,
                'key' => $hint->hint_key,
                'role_key' => $hint->role_key,
                'character_class_id' => $hint->character_class_id,
                'sort_order' => $hint->sort_order,
                'character_class' => $hint->characterClass ? [
                    'id' => $hint->characterClass->id,
                    'name' => $hint->characterClass->name,
                    'shorthand' => $hint->characterClass->shorthand,
                    'role' => $hint->characterClass->role,
                    'icon_url' => $hint->characterClass->icon_url,
                    'flaticon_url' => $hint->characterClass->flaticon_url,
                ] : null,
            ])->values(),
            'assigned_character' => $slot->assignedCharacter ? [
                'id' => $slot->assignedCharacter->id,
                'user_id' => $slot->assignedCharacter->user_id,
                'name' => $slot->assignedCharacter->name,
                'avatar_url' => $slot->assignedCharacter->avatar_url,
                'world' => $slot->assignedCharacter->world,
                'datacenter' => $slot->assignedCharacter->datacenter,
            ] : null,
            'application_field_groups' => $this->serializeApplicationFieldGroups($slot, $attendanceAssignment),
            'application_matches' => $this->applicationMatchService->build($slot, $attendanceAssignment),
            'field_values' => $this->orderedFieldValues($slot)->map(fn ($fieldValue) => [
                'id' => $fieldValue->id,
                'field_key' => $fieldValue->field_key,
                'field_label' => $fieldValue->field_label,
                'field_type' => $fieldValue->field_type,
                'source' => $fieldValue->source,
                'value' => $fieldValue->value,
                'display_value' => $this->resolveSlotFieldDisplayValue($fieldValue),
                'display_meta' => $this->resolveSlotFieldDisplayMeta($fieldValue),
            ])->values(),
        ];
    }

    /**
     * @return Collection<int, ActivitySlotFieldValue>
     */
    private function orderedFieldValues(ActivitySlot $slot): Collection
    {
        $schemaOrder = collect($this->slotSchemaForSlot($slot))
            ->values()
            ->mapWithKeys(fn (array $field, int $index): array => [
                (string) ($field['key'] ?? '') => $index,
            ])
            ->filter(fn (int $index, string $key): bool => $key !== '')
            ->all();

        return $slot->fieldValues
            ->sort(function (ActivitySlotFieldValue $first, ActivitySlotFieldValue $second) use ($schemaOrder): int {
                return [
                    $this->fieldOrder($first, $schemaOrder),
                    (int) $first->id,
                ] <=> [
                    $this->fieldOrder($second, $schemaOrder),
                    (int) $second->id,
                ];
            })
            ->values();
    }

    /**
     * @param  array<string, int>  $schemaOrder
     */
    private function fieldOrder(ActivitySlotFieldValue $fieldValue, array $schemaOrder): int
    {
        return $schemaOrder[$fieldValue->field_key] ?? $this->fallbackFieldOrder($fieldValue);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function slotSchemaForSlot(ActivitySlot $slot): array
    {
        $activityId = (int) $slot->activity_id;

        if (array_key_exists($activityId, $this->slotSchemaByActivityId)) {
            return $this->slotSchemaByActivityId[$activityId];
        }

        $activityTypeVersion = null;

        if ($slot->relationLoaded('activity') && $slot->activity instanceof Activity) {
            $activityTypeVersion = $slot->activity->relationLoaded('activityTypeVersion')
                ? $slot->activity->activityTypeVersion
                : null;

            if (! $activityTypeVersion instanceof ActivityTypeVersion && $slot->activity->activity_type_version_id) {
                $activityTypeVersion = ActivityTypeVersion::query()->find($slot->activity->activity_type_version_id);
            }
        }

        if (! $activityTypeVersion instanceof ActivityTypeVersion) {
            $activityTypeVersionId = Activity::query()
                ->whereKey($activityId)
                ->value('activity_type_version_id');

            $activityTypeVersion = $activityTypeVersionId
                ? ActivityTypeVersion::query()->find($activityTypeVersionId)
                : null;
        }

        return $this->slotSchemaByActivityId[$activityId] = is_array($activityTypeVersion?->slot_schema)
            ? $activityTypeVersion->slot_schema
            : [];
    }

    private function fallbackFieldOrder(ActivitySlotFieldValue $fieldValue): int
    {
        return match ($fieldValue->source) {
            'character_classes' => 10,
            'phantom_jobs' => 20,
            default => 100,
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeApplicationFieldGroups(ActivitySlot $slot, ?ActivitySlotAssignment $attendanceAssignment): array
    {
        if (! $this->slotBench->isBench($slot) || ! $attendanceAssignment?->relationLoaded('application')) {
            return [];
        }

        $application = $attendanceAssignment->application;

        if (! $application || ! $application->relationLoaded('answers')) {
            return [];
        }

        return $application->answers
            ->map(function ($answer) {
                $displayItems = $this->applicationAnswerPresenter->presentDisplayItems($answer->source, $answer->value);

                if ($displayItems === []) {
                    return null;
                }

                return [
                    'question_key' => (string) $answer->question_key,
                    'question_label' => is_array($answer->question_label) ? $answer->question_label : ['en' => (string) $answer->question_key],
                    'source' => $answer->source,
                    'items' => $displayItems,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
