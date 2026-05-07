<?php

namespace App\Services\Notifications;

use App\Models\Activity;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use App\Support\Notifications\NotificationCategory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class GroupUpdateNotificationService
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function notifyRunCreated(Activity $activity, mixed $actor): void
    {
        $activity->loadMissing('group');

        $isPlanningNotification = in_array($activity->status, [
            Activity::STATUS_DRAFT,
            Activity::STATUS_PLANNED,
        ], true);

        $recipients = $isPlanningNotification
            ? $this->moderatorRecipients($activity->group, $actor instanceof User ? $actor->id : null)
            : $this->followerRecipients($activity->group, $actor instanceof User ? $actor->id : null);

        if ($recipients->isEmpty()) {
            return;
        }

        $event = $this->notificationService->createEvent(
            type: $isPlanningNotification ? 'groups.run_planned' : 'groups.run_scheduled',
            category: NotificationCategory::GROUP_UPDATES,
            titleKey: $isPlanningNotification
                ? 'notifications.groups.run_planned.title'
                : 'notifications.groups.run_scheduled.title',
            bodyKey: $isPlanningNotification
                ? 'notifications.groups.run_planned.body'
                : 'notifications.groups.run_scheduled.body',
            messageParams: [
                'group' => $activity->group?->name,
                'activity' => $this->activityTitle($activity),
            ],
            actionUrl: route('groups.show', $activity->group),
            actor: $actor instanceof User ? $actor : null,
            subject: $activity,
            payload: [
                'group_id' => $activity->group?->id,
                'group_slug' => $activity->group?->slug,
                'activity_id' => $activity->id,
                'activity_title' => $this->activityTitle($activity),
                'status' => $activity->status,
            ],
        );

        $this->notificationService->sendInAppNotifications($event, $recipients);
    }

    public function notifyRunScheduled(Activity $activity, mixed $actor): void
    {
        $activity->loadMissing('group');

        $recipients = $this->followerRecipients($activity->group, $actor instanceof User ? $actor->id : null);

        if ($recipients->isEmpty()) {
            return;
        }

        $event = $this->notificationService->createEvent(
            type: 'groups.run_scheduled',
            category: NotificationCategory::GROUP_UPDATES,
            titleKey: 'notifications.groups.run_scheduled.title',
            bodyKey: 'notifications.groups.run_scheduled.body',
            messageParams: [
                'group' => $activity->group?->name,
                'activity' => $this->activityTitle($activity),
            ],
            actionUrl: route('groups.show', $activity->group),
            actor: $actor instanceof User ? $actor : null,
            subject: $activity,
            payload: [
                'group_id' => $activity->group?->id,
                'group_slug' => $activity->group?->slug,
                'activity_id' => $activity->id,
                'activity_title' => $this->activityTitle($activity),
                'status' => $activity->status,
            ],
        );

        $this->notificationService->sendInAppNotifications($event, $recipients);
    }

    public function notifyMemberPromoted(Group $group, User $member, mixed $actor): void
    {
        $this->notifyTargetUser(
            group: $group,
            member: $member,
            actor: $actor,
            type: 'groups.member_promoted',
            titleKey: 'notifications.groups.member_promoted.title',
            bodyKey: 'notifications.groups.member_promoted.body',
        );
    }

    public function notifyMemberDemoted(Group $group, User $member, mixed $actor): void
    {
        $this->notifyTargetUser(
            group: $group,
            member: $member,
            actor: $actor,
            type: 'groups.member_demoted',
            titleKey: 'notifications.groups.member_demoted.title',
            bodyKey: 'notifications.groups.member_demoted.body',
        );
    }

    public function notifyOwnershipTransferred(Group $group, User $previousOwner, User $newOwner, mixed $actor): void
    {
        if ($previousOwner->group_update_notifications) {
            $event = $this->notificationService->createEvent(
                type: 'groups.ownership_transferred_from_you',
                category: NotificationCategory::GROUP_UPDATES,
                titleKey: 'notifications.groups.ownership_transferred_from_you.title',
                bodyKey: 'notifications.groups.ownership_transferred_from_you.body',
                messageParams: [
                    'group' => $group->name,
                    'user' => $newOwner->name,
                ],
                actionUrl: route('groups.show', $group),
                actor: $actor instanceof User ? $actor : null,
                subject: $group,
                payload: [
                    'group_id' => $group->id,
                    'group_slug' => $group->slug,
                    'user_id' => $newOwner->id,
                ],
            );

            $this->notificationService->sendInAppNotifications($event, $previousOwner);
        }

        if ($newOwner->group_update_notifications) {
            $event = $this->notificationService->createEvent(
                type: 'groups.ownership_transferred_to_you',
                category: NotificationCategory::GROUP_UPDATES,
                titleKey: 'notifications.groups.ownership_transferred_to_you.title',
                bodyKey: 'notifications.groups.ownership_transferred_to_you.body',
                messageParams: [
                    'group' => $group->name,
                    'user' => $previousOwner->name,
                ],
                actionUrl: route('groups.show', $group),
                actor: $actor instanceof User ? $actor : null,
                subject: $group,
                payload: [
                    'group_id' => $group->id,
                    'group_slug' => $group->slug,
                    'user_id' => $previousOwner->id,
                ],
            );

            $this->notificationService->sendInAppNotifications($event, $newOwner);
        }
    }

    public function notifyMemberJoined(Group $group, User $member, mixed $actor): void
    {
        $this->notifyModeratorsAboutMember(
            group: $group,
            member: $member,
            actor: $actor,
            type: 'groups.member_joined',
            titleKey: 'notifications.groups.member_joined.title',
            bodyKey: 'notifications.groups.member_joined.body',
        );
    }

    public function notifyMemberLeft(Group $group, User $member, mixed $actor): void
    {
        $this->notifyModeratorsAboutMember(
            group: $group,
            member: $member,
            actor: $actor,
            type: 'groups.member_left',
            titleKey: 'notifications.groups.member_left.title',
            bodyKey: 'notifications.groups.member_left.body',
        );
    }

    public function notifyMemberBanned(Group $group, User $member, mixed $actor): void
    {
        $this->notifyTargetUser(
            group: $group,
            member: $member,
            actor: $actor,
            type: 'groups.member_banned',
            titleKey: 'notifications.groups.member_banned.title',
            bodyKey: 'notifications.groups.member_banned.body',
        );
    }

    private function notifyTargetUser(
        Group $group,
        User $member,
        mixed $actor,
        string $type,
        string $titleKey,
        string $bodyKey,
    ): void {
        if (!$member->group_update_notifications) {
            return;
        }

        $event = $this->notificationService->createEvent(
            type: $type,
            category: NotificationCategory::GROUP_UPDATES,
            titleKey: $titleKey,
            bodyKey: $bodyKey,
            messageParams: [
                'group' => $group->name,
                'user' => $member->name,
            ],
            actionUrl: route('groups.show', $group),
            actor: $actor instanceof User ? $actor : null,
            subject: $group,
            payload: [
                'group_id' => $group->id,
                'group_slug' => $group->slug,
                'user_id' => $member->id,
            ],
        );

        $this->notificationService->sendInAppNotifications($event, $member);
    }

    private function notifyModeratorsAboutMember(
        Group $group,
        User $member,
        mixed $actor,
        string $type,
        string $titleKey,
        string $bodyKey,
    ): void {
        $recipients = $this->moderatorRecipients($group, $actor instanceof User ? $actor->id : null);

        if ($recipients->isEmpty()) {
            return;
        }

        $event = $this->notificationService->createEvent(
            type: $type,
            category: NotificationCategory::GROUP_UPDATES,
            titleKey: $titleKey,
            bodyKey: $bodyKey,
            messageParams: [
                'group' => $group->name,
                'user' => $member->name,
            ],
            actionUrl: route('groups.dashboard.members', $group),
            actor: $actor instanceof User ? $actor : null,
            subject: $group,
            payload: [
                'group_id' => $group->id,
                'group_slug' => $group->slug,
                'user_id' => $member->id,
            ],
        );

        $this->notificationService->sendInAppNotifications($event, $recipients);
    }

    /**
     * @return EloquentCollection<int, User>
     */
    private function moderatorRecipients(Group $group, ?int $excludeUserId = null): EloquentCollection
    {
        return User::query()
            ->where('group_update_notifications', true)
            ->whereHas('followedGroups', function ($followQuery) use ($group): void {
                $followQuery
                    ->where('groups.id', $group->id)
                    ->where('group_follows.notifications_enabled', true);
            })
            ->where(function ($query) use ($group): void {
                $query->whereKey($group->owner_id)
                    ->orWhereHas('groupMemberships', function ($membershipQuery) use ($group): void {
                        $membershipQuery
                            ->where('group_id', $group->id)
                            ->where('role', GroupMembership::ROLE_MODERATOR);
                    });
            })
            ->when($excludeUserId !== null, fn ($query) => $query->whereKeyNot($excludeUserId))
            ->get();
    }

    /**
     * @return EloquentCollection<int, User>
     */
    private function followerRecipients(Group $group, ?int $excludeUserId = null): EloquentCollection
    {
        return User::query()
            ->where('group_update_notifications', true)
            ->whereHas('followedGroups', function ($query) use ($group): void {
                $query
                    ->where('groups.id', $group->id)
                    ->where('group_follows.notifications_enabled', true);
            })
            ->when($excludeUserId !== null, fn ($query) => $query->whereKeyNot($excludeUserId))
            ->get();
    }

    private function activityTitle(Activity $activity): string
    {
        if (filled($activity->title)) {
            return (string) $activity->title;
        }

        return sprintf('Activity #%d', $activity->id);
    }
}
