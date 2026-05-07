<?php

namespace App\Services\Notifications;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\Character;
use App\Models\User;
use App\Support\Notifications\NotificationCategory;

class AssignmentNotificationService
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function notifyRosterPublished(Activity $activity, mixed $actor): void
    {
        $activity->loadMissing([
            'group',
            'applications.user',
            'applications.selectedCharacter',
            'slots.assignedCharacter',
        ]);

        $applicationsByCharacterId = $activity->applications
            ->filter(fn (ActivityApplication $application) => $application->selected_character_id !== null)
            ->filter(fn (ActivityApplication $application) => in_array($application->status, [
                ActivityApplication::STATUS_APPROVED,
                ActivityApplication::STATUS_ON_BENCH,
            ], true))
            ->keyBy('selected_character_id');

        foreach ($activity->slots as $slot) {
            if (!$slot->assigned_character_id) {
                continue;
            }

            $application = $applicationsByCharacterId->get($slot->assigned_character_id);

            if ($application) {
                $this->notifyApplicationPlacement(
                    $application,
                    $slot,
                    $actor,
                    published: true,
                );

                continue;
            }

            if ($slot->assignedCharacter) {
                $this->notifyCharacterPlacement(
                    $activity,
                    $slot->assignedCharacter,
                    $slot,
                    $actor,
                    published: true,
                );
            }
        }
    }

    public function notifyPlacementChanged(ActivityApplication $application, ?ActivitySlot $slot, mixed $actor): void
    {
        $activity = $application->activity;

        if (!$activity instanceof Activity || $activity->status !== Activity::STATUS_ASSIGNED) {
            return;
        }

        $this->notifyApplicationPlacement(
            $application,
            $slot,
            $actor,
            published: false,
        );
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
    }

    private function notifyApplicationPlacement(
        ActivityApplication $application,
        ?ActivitySlot $slot,
        mixed $actor,
        bool $published,
    ): void {
        $recipient = $this->recipient($application);

        if (!$recipient) {
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
            actionUrl: route('account.applications'),
            actor: $actor instanceof User ? $actor : null,
            subject: $application,
            payload: $this->payload($application, $slot),
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

        if (!$recipient) {
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
            actionUrl: route('account.applications'),
            actor: $actor instanceof User ? $actor : null,
            subject: $character,
            payload: $this->characterPayload($activity, $character, $slot),
        );

        $this->notificationService->sendInAppNotifications($event, $recipient);
        $this->notificationService->sendOffSiteNotifications($event, $recipient);
    }

    /**
     * @return array{type: string, titleKey: string, bodyKey: string}|null
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
            ],
            ActivityApplication::STATUS_ON_BENCH => [
                'type' => $published ? 'assignments.roster_published_bench' : 'assignments.on_bench',
                'titleKey' => $published
                    ? 'notifications.assignments.roster_published_bench.title'
                    : 'notifications.assignments.on_bench.title',
                'bodyKey' => $published
                    ? 'notifications.assignments.roster_published_bench.body'
                    : 'notifications.assignments.on_bench.body',
            ],
            ActivityApplication::STATUS_PENDING => $published ? null : [
                'type' => 'assignments.returned_to_queue',
                'titleKey' => 'notifications.assignments.returned_to_queue.title',
                'bodyKey' => 'notifications.assignments.returned_to_queue.body',
            ],
            default => null,
        };
    }

    private function recipient(ActivityApplication $application): ?User
    {
        $application->loadMissing('user');

        $recipient = $application->user;

        if (!$recipient instanceof User || !$recipient->assignment_notifications) {
            return null;
        }

        return $recipient;
    }

    private function characterRecipient(Character $character): ?User
    {
        $character->loadMissing('user');

        $recipient = $character->user;

        if (!$recipient instanceof User || !$recipient->assignment_notifications) {
            return null;
        }

        return $recipient;
    }

    private function findAssignedSlotForApplication(Activity $activity, ActivityApplication $application): ?ActivitySlot
    {
        if (!$application->selected_character_id) {
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
        ];
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
        ];
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
        ];
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
        ];
    }

    private function activityTitle(?Activity $activity): string
    {
        if (filled($activity?->title)) {
            return (string) $activity->title;
        }

        return $activity ? sprintf('Activity #%d', $activity->id) : 'Activity';
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
        if (!$slot) {
            return null;
        }

        return $slot->slot_label['en'] ?? $slot->slot_key;
    }

    private function groupLabel(?ActivitySlot $slot): ?string
    {
        if (!$slot) {
            return null;
        }

        return $slot->group_label['en'] ?? $slot->group_key;
    }
}
