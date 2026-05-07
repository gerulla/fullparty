<?php

namespace App\Services\Notifications;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
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
            'slots',
        ]);

        foreach ($activity->applications as $application) {
            if (!in_array($application->status, [
                ActivityApplication::STATUS_APPROVED,
                ActivityApplication::STATUS_ON_BENCH,
            ], true)) {
                continue;
            }

            $slot = $this->findAssignedSlotForApplication($activity, $application);

            $this->notifyApplicationPlacement(
                $application,
                $slot,
                $actor,
                published: true,
            );
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

        $config = $this->eventConfigFor($application, $published);

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

    /**
     * @return array{type: string, titleKey: string, bodyKey: string}|null
     */
    private function eventConfigFor(ActivityApplication $application, bool $published): ?array
    {
        return match ($application->status) {
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
