<?php

namespace App\Services\Notifications;

use App\Http\Controllers\Concerns\InteractsWithActivitySlotFieldDisplay;
use App\Jobs\DispatchRosterPublishedAssignmentNotificationJob;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotFieldValue;
use App\Models\Character;
use App\Models\User;
use App\Support\Activities\ActivityDisplayName;
use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\NotificationTopic;

class AssignmentNotificationService
{
    use InteractsWithActivitySlotFieldDisplay;

    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function notifyRosterPublished(Activity $activity, mixed $actor): void
    {
        $activity->loadMissing([
            'group',
            'applications.user',
            'applications.selectedCharacter',
            'slots',
        ]);

        $applicationsByCharacterId = $activity->applications
            ->filter(fn (ActivityApplication $application) => $application->selected_character_id !== null)
            ->filter(fn (ActivityApplication $application) => in_array($application->status, [
                ActivityApplication::STATUS_APPROVED,
                ActivityApplication::STATUS_ON_BENCH,
            ], true))
            ->keyBy('selected_character_id');

        foreach ($activity->slots as $slot) {
            if (! $slot->assigned_character_id) {
                continue;
            }

            $application = $applicationsByCharacterId->get($slot->assigned_character_id);

            if ($application) {
                DispatchRosterPublishedAssignmentNotificationJob::dispatch(
                    slotId: $slot->id,
                    applicationId: $application->id,
                    characterId: null,
                    actorId: $actor instanceof User ? $actor->id : null,
                )->afterCommit();

                continue;
            }

            DispatchRosterPublishedAssignmentNotificationJob::dispatch(
                slotId: $slot->id,
                applicationId: null,
                characterId: $slot->assigned_character_id,
                actorId: $actor instanceof User ? $actor->id : null,
            )->afterCommit();
        }
    }

    public function sendRosterPublishedNotification(
        int $slotId,
        ?int $applicationId,
        ?int $characterId,
        ?int $actorId,
    ): void {
        $slot = ActivitySlot::query()
            ->with([
                'activity.group',
                'assignedCharacter.user',
                'fieldValues',
            ])
            ->find($slotId);

        $activity = $slot?->activity;

        if (! $slot || ! $activity instanceof Activity || $activity->status !== Activity::STATUS_ASSIGNED) {
            return;
        }

        $actor = $actorId ? User::query()->find($actorId) : null;

        if ($applicationId !== null) {
            $application = ActivityApplication::query()
                ->with(['activity.group', 'user', 'selectedCharacter'])
                ->find($applicationId);

            if ($application && $application->activity_id === $activity->id) {
                $this->notifyApplicationPlacement(
                    $application,
                    $slot,
                    $actor,
                    published: true,
                );
            }

            return;
        }

        $character = $characterId !== null
            ? Character::query()->with('user')->find($characterId)
            : $slot->assignedCharacter;

        if (! $character || (int) $slot->assigned_character_id !== (int) $character->id) {
            return;
        }

        $this->notifyCharacterPlacement(
            $activity,
            $character,
            $slot,
            $actor,
            published: true,
        );

        $this->notifySlotDesignations($activity, $character, $slot, $actor);
    }

    public function notifyPlacementChanged(ActivityApplication $application, ?ActivitySlot $slot, mixed $actor): void
    {
        $activity = $application->activity;

        if (! $activity instanceof Activity || $activity->status !== Activity::STATUS_ASSIGNED) {
            return;
        }

        $this->notifyApplicationPlacement(
            $application,
            $slot,
            $actor,
            published: false,
        );

        if ($slot && $application->selectedCharacter) {
            $this->notifySlotDesignations(
                $activity,
                $application->selectedCharacter,
                $slot,
                $actor,
            );
        }
    }

    public function notifyManualPlacementChanged(Activity $activity, Character $character, ?ActivitySlot $slot, mixed $actor): void
    {
        if ($activity->status !== Activity::STATUS_ASSIGNED) {
            return;
        }

        $this->notifyCharacterPlacement(
            $activity,
            $character,
            $slot,
            $actor,
            published: false,
        );

        if ($slot) {
            $this->notifySlotDesignations($activity, $character, $slot, $actor);
        }
    }

    public function notifyManualPlacementRemoved(Activity $activity, Character $character, ?ActivitySlot $slot, mixed $actor): void
    {
        if ($activity->status !== Activity::STATUS_ASSIGNED) {
            return;
        }

        $recipient = $this->characterRecipient($character);

        if (! $recipient) {
            return;
        }

        $event = $this->notificationService->createEvent(
            type: 'assignments.removed',
            category: NotificationCategory::ASSIGNMENTS,
            titleKey: 'notifications.assignments.removed.title',
            bodyKey: 'notifications.assignments.removed.body',
            messageParams: $this->characterMessageParams($activity, $character, $slot),
            actionUrl: $this->activityOverviewUrl($activity),
            actor: $actor instanceof User ? $actor : null,
            subject: $character,
            payload: array_merge($this->characterPayload($activity, $character, $slot), [
                'status' => 'removed',
            ]),
            topic: NotificationTopic::ASSIGNMENTS_ROSTER,
            groupId: $activity->group?->id,
        );

        $this->notificationService->sendInAppNotifications($event, $recipient);
        $this->notificationService->sendOffSiteNotifications($event, $recipient);
    }

    public function notifyMarkedMissing(Activity $activity, Character $character, ?ActivitySlot $slot, mixed $actor): void
    {
        if ($activity->status !== Activity::STATUS_ASSIGNED) {
            return;
        }

        $this->notifyAttendanceChanged(
            type: 'assignments.marked_missing',
            titleKey: 'notifications.assignments.marked_missing.title',
            bodyKey: 'notifications.assignments.marked_missing.body',
            activity: $activity,
            character: $character,
            slot: $slot,
            actor: $actor,
            attendanceStatus: 'missing',
        );
    }

    public function notifyMissingRestored(Activity $activity, Character $character, ?ActivitySlot $slot, mixed $actor): void
    {
        if ($activity->status !== Activity::STATUS_ASSIGNED) {
            return;
        }

        $this->notifyAttendanceChanged(
            type: 'assignments.missing_restored',
            titleKey: 'notifications.assignments.missing_restored.title',
            bodyKey: 'notifications.assignments.missing_restored.body',
            activity: $activity,
            character: $character,
            slot: $slot,
            actor: $actor,
            attendanceStatus: 'assigned',
        );
    }

    public function notifyDesignationChanged(
        Activity $activity,
        Character $character,
        ActivitySlot $slot,
        string $designation,
        bool $assigned,
        mixed $actor,
    ): void {
        if ($activity->status !== Activity::STATUS_ASSIGNED) {
            return;
        }

        $recipient = $this->characterRecipient($character);

        if (! $recipient) {
            return;
        }

        $event = $this->notificationService->createEvent(
            type: $assigned ? 'assignments.designation_assigned' : 'assignments.designation_removed',
            category: NotificationCategory::ASSIGNMENTS,
            titleKey: $assigned
                ? 'notifications.assignments.designation_assigned.title'
                : 'notifications.assignments.designation_removed.title',
            bodyKey: $assigned
                ? 'notifications.assignments.designation_assigned.body'
                : 'notifications.assignments.designation_removed.body',
            messageParams: [
                'activity' => $this->activityTitle($activity),
                'group' => $activity->group?->name,
                'character' => $character->name,
                'slot' => $this->slotLabel($slot),
                'slot_group' => $this->groupLabel($slot),
                'designation' => $this->designationLabel($designation),
            ] + $this->rosterMessageParams($slot),
            actionUrl: $this->activityOverviewUrl($activity),
            actor: $actor instanceof User ? $actor : null,
            subject: $character,
            payload: [
                'activity_id' => $activity->id,
                'group_id' => $activity->group?->id,
                'group_slug' => $activity->group?->slug,
                'character_id' => $character->id,
                'character_name' => $character->name,
                'slot_id' => $slot->id,
                'slot_key' => $slot->slot_key,
                'slot_label' => $this->slotLabel($slot),
                'slot_group' => $this->groupLabel($slot),
                'designation_key' => $designation,
                'designation_label' => $this->designationLabel($designation),
                'designation_assigned' => $assigned,
            ] + $this->rosterPayload($slot),
            topic: NotificationTopic::ASSIGNMENTS_DESIGNATIONS,
            groupId: $activity->group?->id,
        );

        $this->notificationService->sendInAppNotifications($event, $recipient);
        $this->notificationService->sendOffSiteNotifications($event, $recipient);
    }

    private function notifyApplicationPlacement(
        ActivityApplication $application,
        ?ActivitySlot $slot,
        mixed $actor,
        bool $published,
    ): void {
        $recipient = $this->recipient($application);

        if (! $recipient) {
            return;
        }

        $config = $this->eventConfigForStatus($application->status, $published);

        if ($config === null) {
            return;
        }

        $event = $this->notificationService->createEvent(
            type: $config['type'],
            category: NotificationCategory::ASSIGNMENTS,
            titleKey: $config['titleKey'],
            bodyKey: $config['bodyKey'],
            messageParams: $this->messageParams($application, $slot),
            actionUrl: $this->activityOverviewUrl($application->activity),
            actor: $actor instanceof User ? $actor : null,
            subject: $application,
            payload: $this->payload($application, $slot),
            topic: $config['topic'],
            groupId: $application->activity?->group?->id,
        );

        $this->notificationService->sendInAppNotifications($event, $recipient);
        $this->notificationService->sendOffSiteNotifications($event, $recipient);
    }

    private function notifyCharacterPlacement(
        Activity $activity,
        Character $character,
        ?ActivitySlot $slot,
        mixed $actor,
        bool $published,
    ): void {
        $recipient = $this->characterRecipient($character);

        if (! $recipient) {
            return;
        }

        $config = $this->eventConfigForStatus(
            $slot?->is_bench ? ActivityApplication::STATUS_ON_BENCH : ActivityApplication::STATUS_APPROVED,
            $published,
        );

        if ($config === null) {
            return;
        }

        $event = $this->notificationService->createEvent(
            type: $config['type'],
            category: NotificationCategory::ASSIGNMENTS,
            titleKey: $config['titleKey'],
            bodyKey: $config['bodyKey'],
            messageParams: $this->characterMessageParams($activity, $character, $slot),
            actionUrl: $this->activityOverviewUrl($activity),
            actor: $actor instanceof User ? $actor : null,
            subject: $character,
            payload: $this->characterPayload($activity, $character, $slot),
            topic: $config['topic'],
            groupId: $activity->group?->id,
        );

        $this->notificationService->sendInAppNotifications($event, $recipient);
        $this->notificationService->sendOffSiteNotifications($event, $recipient);
    }

    private function notifySlotDesignations(
        Activity $activity,
        Character $character,
        ActivitySlot $slot,
        mixed $actor,
    ): void {
        if ($slot->is_host) {
            $this->notifyDesignationChanged(
                $activity,
                $character,
                $slot,
                ActivitySlot::DESIGNATION_HOST,
                true,
                $actor,
            );
        }

        if ($slot->is_raid_leader) {
            $this->notifyDesignationChanged(
                $activity,
                $character,
                $slot,
                ActivitySlot::DESIGNATION_RAID_LEADER,
                true,
                $actor,
            );
        }
    }

    private function notifyAttendanceChanged(
        string $type,
        string $titleKey,
        string $bodyKey,
        Activity $activity,
        Character $character,
        ?ActivitySlot $slot,
        mixed $actor,
        string $attendanceStatus,
    ): void {
        $recipient = $this->characterRecipient($character);

        if (! $recipient) {
            return;
        }

        $event = $this->notificationService->createEvent(
            type: $type,
            category: NotificationCategory::ASSIGNMENTS,
            titleKey: $titleKey,
            bodyKey: $bodyKey,
            messageParams: $this->characterMessageParams($activity, $character, $slot),
            actionUrl: $this->activityOverviewUrl($activity),
            actor: $actor instanceof User ? $actor : null,
            subject: $character,
            payload: array_merge($this->characterPayload($activity, $character, $slot), [
                'attendance_status' => $attendanceStatus,
            ]),
            topic: NotificationTopic::ASSIGNMENTS_STATUS,
            groupId: $activity->group?->id,
        );

        $this->notificationService->sendInAppNotifications($event, $recipient);
        $this->notificationService->sendOffSiteNotifications($event, $recipient);
    }

    /**
     * @return array{type: string, titleKey: string, bodyKey: string, topic: string}|null
     */
    private function eventConfigForStatus(string $status, bool $published): ?array
    {
        return match ($status) {
            ActivityApplication::STATUS_APPROVED => [
                'type' => $published ? 'assignments.roster_published_assigned' : 'assignments.assigned',
                'titleKey' => $published
                    ? 'notifications.assignments.roster_published_assigned.title'
                    : 'notifications.assignments.assigned.title',
                'bodyKey' => $published
                    ? 'notifications.assignments.roster_published_assigned.body'
                    : 'notifications.assignments.assigned.body',
                'topic' => NotificationTopic::ASSIGNMENTS_ROSTER,
            ],
            ActivityApplication::STATUS_ON_BENCH => [
                'type' => $published ? 'assignments.roster_published_bench' : 'assignments.on_bench',
                'titleKey' => $published
                    ? 'notifications.assignments.roster_published_bench.title'
                    : 'notifications.assignments.on_bench.title',
                'bodyKey' => $published
                    ? 'notifications.assignments.roster_published_bench.body'
                    : 'notifications.assignments.on_bench.body',
                'topic' => NotificationTopic::ASSIGNMENTS_BENCH,
            ],
            ActivityApplication::STATUS_PENDING => $published ? null : [
                'type' => 'assignments.returned_to_queue',
                'titleKey' => 'notifications.assignments.returned_to_queue.title',
                'bodyKey' => 'notifications.assignments.returned_to_queue.body',
                'topic' => NotificationTopic::ASSIGNMENTS_ROSTER,
            ],
            default => null,
        };
    }

    private function recipient(ActivityApplication $application): ?User
    {
        $application->loadMissing('user');

        $recipient = $application->user;

        if (! $recipient instanceof User) {
            return null;
        }

        return $recipient;
    }

    private function characterRecipient(Character $character): ?User
    {
        $character->loadMissing('user');

        $recipient = $character->user;

        if (! $recipient instanceof User) {
            return null;
        }

        return $recipient;
    }

    private function findAssignedSlotForApplication(Activity $activity, ActivityApplication $application): ?ActivitySlot
    {
        if (! $application->selected_character_id) {
            return null;
        }

        return $activity->slots
            ->first(fn (ActivitySlot $slot) => (int) $slot->assigned_character_id === (int) $application->selected_character_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function messageParams(ActivityApplication $application, ?ActivitySlot $slot): array
    {
        return [
            'activity' => $this->activityTitle($application->activity),
            'group' => $application->activity?->group?->name,
            'character' => $this->characterName($application),
            'slot' => $this->slotLabel($slot),
            'slot_group' => $this->groupLabel($slot),
        ] + $this->rosterMessageParams($slot);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ActivityApplication $application, ?ActivitySlot $slot): array
    {
        return [
            'application_id' => $application->id,
            'activity_id' => $application->activity?->id,
            'group_id' => $application->activity?->group?->id,
            'group_slug' => $application->activity?->group?->slug,
            'activity_title' => $this->activityTitle($application->activity),
            'character_name' => $this->characterName($application),
            'status' => $application->status,
            'slot_id' => $slot?->id,
            'slot_key' => $slot?->slot_key,
            'slot_label' => $this->slotLabel($slot),
            'slot_group' => $this->groupLabel($slot),
        ] + $this->rosterPayload($slot);
    }

    /**
     * @return array<string, mixed>
     */
    private function characterMessageParams(Activity $activity, Character $character, ?ActivitySlot $slot): array
    {
        return [
            'activity' => $this->activityTitle($activity),
            'group' => $activity->group?->name,
            'character' => $character->name,
            'slot' => $this->slotLabel($slot),
            'slot_group' => $this->groupLabel($slot),
        ] + $this->rosterMessageParams($slot);
    }

    /**
     * @return array<string, mixed>
     */
    private function characterPayload(Activity $activity, Character $character, ?ActivitySlot $slot): array
    {
        return [
            'application_id' => null,
            'activity_id' => $activity->id,
            'group_id' => $activity->group?->id,
            'group_slug' => $activity->group?->slug,
            'activity_title' => $this->activityTitle($activity),
            'character_id' => $character->id,
            'character_name' => $character->name,
            'status' => $slot?->is_bench ? ActivityApplication::STATUS_ON_BENCH : ActivityApplication::STATUS_APPROVED,
            'slot_id' => $slot?->id,
            'slot_key' => $slot?->slot_key,
            'slot_label' => $this->slotLabel($slot),
            'slot_group' => $this->groupLabel($slot),
        ] + $this->rosterPayload($slot);
    }

    /**
     * @return array<string, mixed>
     */
    private function rosterMessageParams(?ActivitySlot $slot): array
    {
        $roster = $this->rosterDetails($slot);

        return $this->filledValues([
            'class' => $roster['selected_class']['name'] ?? null,
            'class_shorthand' => $roster['selected_class']['shorthand'] ?? null,
            'phantom_job' => $roster['selected_phantom_job']['name'] ?? null,
            'position' => $roster['selected_position']['label'] ?? null,
            'position_key' => $roster['selected_position']['key'] ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rosterPayload(?ActivitySlot $slot): array
    {
        $roster = $this->rosterDetails($slot);

        if ($roster === []) {
            return [];
        }

        return ['roster' => $roster];
    }

    /**
     * @return array<string, mixed>
     */
    private function rosterDetails(?ActivitySlot $slot): array
    {
        if (! $slot) {
            return [];
        }

        $slot->loadMissing('fieldValues');

        $fields = $slot->fieldValues
            ->map(fn (ActivitySlotFieldValue $fieldValue) => $this->rosterFieldPayload($fieldValue))
            ->filter()
            ->values()
            ->all();

        $roster = $this->filledValues([
            'group_key' => $slot->group_key,
            'group_label' => $this->groupLabel($slot),
            'slot_key' => $slot->slot_key,
            'slot_label' => $this->slotLabel($slot),
            'position_in_group' => $slot->position_in_group,
        ]);

        if ($fields !== []) {
            $roster['fields'] = $fields;
        }

        $selectedClass = collect($fields)->first(fn (array $field) => ($field['source'] ?? null) === 'character_classes'
            || str_contains((string) ($field['key'] ?? ''), 'class'));
        $selectedPhantomJob = collect($fields)->first(fn (array $field) => ($field['source'] ?? null) === 'phantom_jobs'
            || str_contains((string) ($field['key'] ?? ''), 'phantom'));
        $selectedPosition = collect($fields)->first(fn (array $field) => str_contains((string) ($field['key'] ?? ''), 'position'));

        if ($selectedClass) {
            $roster['selected_class'] = $this->classPayload($selectedClass);
        }

        if ($selectedPhantomJob) {
            $roster['selected_phantom_job'] = $this->phantomJobPayload($selectedPhantomJob);
        }

        if ($selectedPosition) {
            $roster['selected_position'] = $this->optionPayload($selectedPosition);
        }

        return $roster;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rosterFieldPayload(ActivitySlotFieldValue $fieldValue): ?array
    {
        if (blank($fieldValue->value)) {
            return null;
        }

        $displayValue = $this->stringValue($this->resolveSlotFieldDisplayValue($fieldValue));
        $meta = $this->resolveSlotFieldDisplayMeta($fieldValue);

        return $this->filledValues([
            'key' => $fieldValue->field_key,
            'label' => $this->labelValue($fieldValue->field_label, $fieldValue->field_key),
            'type' => $fieldValue->field_type,
            'source' => $fieldValue->source,
            'value' => $fieldValue->value,
            'display_value' => $displayValue,
            'meta' => $meta,
        ]);
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function classPayload(array $field): array
    {
        $value = is_array($field['value'] ?? null) ? $field['value'] : [];
        $meta = is_array($field['meta'] ?? null) ? $field['meta'] : [];

        return $this->filledValues([
            'id' => $value['id'] ?? null,
            'name' => $value['name'] ?? $meta['name'] ?? $field['display_value'] ?? null,
            'shorthand' => $value['shorthand'] ?? $meta['shorthand'] ?? null,
            'role' => $value['role'] ?? $meta['role'] ?? null,
            'icon_url' => $meta['icon_url'] ?? null,
            'flaticon_url' => $meta['flaticon_url'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function phantomJobPayload(array $field): array
    {
        $value = is_array($field['value'] ?? null) ? $field['value'] : [];
        $meta = is_array($field['meta'] ?? null) ? $field['meta'] : [];

        return $this->filledValues([
            'id' => $value['id'] ?? null,
            'name' => $value['name'] ?? $meta['name'] ?? $field['display_value'] ?? null,
            'icon_url' => $meta['icon_url'] ?? null,
            'black_icon_url' => $meta['black_icon_url'] ?? null,
            'transparent_icon_url' => $meta['transparent_icon_url'] ?? null,
            'sprite_url' => $meta['sprite_url'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function optionPayload(array $field): array
    {
        $value = is_array($field['value'] ?? null) ? $field['value'] : [];
        $meta = is_array($field['meta'] ?? null) ? $field['meta'] : [];

        return $this->filledValues([
            'key' => $meta['key'] ?? $value['key'] ?? null,
            'label' => $this->labelValue($meta['label'] ?? $value['label'] ?? null, $field['display_value'] ?? null),
        ]);
    }

    private function stringValue(mixed $value): ?string
    {
        if (is_array($value)) {
            return $this->labelValue($value);
        }

        return filled($value) ? (string) $value : null;
    }

    private function labelValue(mixed $label, ?string $fallback = null): ?string
    {
        if (is_array($label)) {
            $value = $label['en'] ?? collect($label)->first(fn ($entry) => filled($entry));

            return filled($value) ? (string) $value : $fallback;
        }

        return filled($label) ? (string) $label : $fallback;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function filledValues(array $values): array
    {
        return array_filter($values, fn ($value) => ! blank($value));
    }

    private function activityOverviewUrl(?Activity $activity): string
    {
        $group = $activity?->group;

        if (! $activity || ! $group) {
            return route('account.applications');
        }

        return route('groups.activities.overview', [
            'group' => $group->slug,
            'activity' => $activity->id,
        ]);
    }

    private function activityTitle(?Activity $activity): string
    {
        return ActivityDisplayName::for($activity);
    }

    private function characterName(ActivityApplication $application): string
    {
        $application->loadMissing('selectedCharacter');

        return $application->selectedCharacter?->name
            ?? $application->applicant_character_name
            ?? 'Applicant';
    }

    private function slotLabel(?ActivitySlot $slot): ?string
    {
        if (! $slot) {
            return null;
        }

        return $slot->slot_label['en'] ?? $slot->slot_key;
    }

    private function groupLabel(?ActivitySlot $slot): ?string
    {
        if (! $slot) {
            return null;
        }

        return $slot->group_label['en'] ?? $slot->group_key;
    }

    private function designationLabel(string $designation): string
    {
        return match ($designation) {
            ActivitySlot::DESIGNATION_HOST => 'Host',
            ActivitySlot::DESIGNATION_RAID_LEADER => 'Raid Leader',
            default => 'Designation',
        };
    }
}
