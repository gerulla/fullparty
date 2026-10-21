<?php

namespace App\Services\Notifications;

use App\Models\Activity;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use App\Services\Runs\RunAccessService;
use App\Support\Activities\ActivityDisplayName;
use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\NotificationTopic;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class GroupUpdateNotificationService
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly RunAccessService $runAccess,
    ) {}

    public function notifyRunCreated(Activity $activity, mixed $actor): void
    {
        $activity->loadMissing('group');

        $isDraftNotification = $activity->status === Activity::STATUS_DRAFT;

        $recipients = $isDraftNotification
            ? $this->moderatorRecipients($activity->group, $actor instanceof User ? $actor->id : null)
            : $this->memberRecipients($activity->group, $actor instanceof User ? $actor->id : null);

        $recipients = $this->runAccess->filterViewers($activity, $recipients);

        if ($recipients->isEmpty()) {
            return;
        }

        $event = $this->notificationService->createEvent(
            type: $isDraftNotification ? 'groups.run_draft' : 'groups.run_scheduled',
            category: NotificationCategory::GROUP_UPDATES,
            titleKey: $isDraftNotification
                ? 'notifications.groups.run_draft.title'
                : 'notifications.groups.run_scheduled.title',
            bodyKey: $isDraftNotification
                ? 'notifications.groups.run_draft.body'
                : 'notifications.groups.run_scheduled.body',
            messageParams: [
                'group' => $activity->group?->name,
                'activity' => $this->activityTitle($activity),
            ],
            actionUrl: route('groups.activities.overview', [
                'group' => $activity->group,
                'activity' => $activity,
            ]),
            actor: $actor instanceof User ? $actor : null,
            subject: $activity,
            payload: [
                'group_id' => $activity->group?->id,
                'group_slug' => $activity->group?->slug,
                'activity_id' => $activity->id,
                'activity_title' => $this->activityTitle($activity),
                'status' => $activity->status,
            ],
            topic: NotificationTopic::GROUP_RUN_POSTS,
            groupId: $activity->group?->id,
        );

        $this->notificationService->sendInAppNotifications($event, $recipients);
    }

    public function notifyRunScheduled(Activity $activity, mixed $actor): void
    {
        $activity->loadMissing('group');

        $recipients = $this->memberRecipients($activity->group, $actor instanceof User ? $actor->id : null);
        $recipients = $this->runAccess->filterViewers($activity, $recipients);

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
            actionUrl: route('groups.activities.overview', [
                'group' => $activity->group,
                'activity' => $activity,
            ]),
            actor: $actor instanceof User ? $actor : null,
            subject: $activity,
            payload: [
                'group_id' => $activity->group?->id,
                'group_slug' => $activity->group?->slug,
                'activity_id' => $activity->id,
                'activity_title' => $this->activityTitle($activity),
                'status' => $activity->status,
            ],
            topic: NotificationTopic::GROUP_RUN_POSTS,
            groupId: $activity->group?->id,
        );

        $this->notificationService->sendInAppNotifications($event, $recipients);
    }

    public function notifyMemberPromoted(Group $group, User $member, mixed $actor, string $newRole): void
    {
        $this->notifyTargetUser(
            group: $group,
            member: $member,
            actor: $actor,
            type: 'groups.member_promoted',
            titleKey: 'notifications.groups.member_promoted.title',
            bodyKey: 'notifications.groups.member_promoted.body',
            messageParams: [
                'role' => $this->formatGroupRole($newRole),
            ],
        );
    }

    public function notifyMemberDemoted(Group $group, User $member, mixed $actor, string $newRole): void
    {
        $this->notifyTargetUser(
            group: $group,
            member: $member,
            actor: $actor,
            type: 'groups.member_demoted',
            titleKey: 'notifications.groups.member_demoted.title',
            bodyKey: 'notifications.groups.member_demoted.body',
            messageParams: [
                'role' => $this->formatGroupRole($newRole),
            ],
        );
    }

    public function notifyOwnershipTransferred(Group $group, User $previousOwner, User $newOwner, mixed $actor): void
    {
        $event = $this->notificationService->createEvent(
            type: 'groups.ownership_transferred_from_you',
            category: NotificationCategory::GROUP_UPDATES,
            titleKey: 'notifications.groups.ownership_transferred_from_you.title',
            bodyKey: 'notifications.groups.ownership_transferred_from_you.body',
            messageParams: [
                'group' => $group->name,
                'user' => $newOwner->name,
            ],
            actionUrl: route('groups.dashboard', $group),
            actor: $actor instanceof User ? $actor : null,
            subject: $group,
            payload: [
                'group_id' => $group->id,
                'group_slug' => $group->slug,
                'user_id' => $newOwner->id,
            ],
            topic: NotificationTopic::GROUP_ROLES,
            groupId: $group->id,
        );

        $this->notificationService->sendInAppNotifications($event, $previousOwner);

        $event = $this->notificationService->createEvent(
            type: 'groups.ownership_transferred_to_you',
            category: NotificationCategory::GROUP_UPDATES,
            titleKey: 'notifications.groups.ownership_transferred_to_you.title',
            bodyKey: 'notifications.groups.ownership_transferred_to_you.body',
            messageParams: [
                'group' => $group->name,
                'user' => $previousOwner->name,
            ],
            actionUrl: route('groups.dashboard', $group),
            actor: $actor instanceof User ? $actor : null,
            subject: $group,
            payload: [
                'group_id' => $group->id,
                'group_slug' => $group->slug,
                'user_id' => $previousOwner->id,
            ],
            topic: NotificationTopic::GROUP_ROLES,
            groupId: $group->id,
        );

        $this->notificationService->sendInAppNotifications($event, $newOwner);
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
        array $messageParams = [],
    ): void {
        $event = $this->notificationService->createEvent(
            type: $type,
            category: NotificationCategory::GROUP_UPDATES,
            titleKey: $titleKey,
            bodyKey: $bodyKey,
            messageParams: array_merge([
                'group' => $group->name,
                'user' => $member->name,
            ], $messageParams),
            actionUrl: $type === 'groups.member_banned'
                ? route('groups.index')
                : route('groups.dashboard', $group),
            actor: $actor instanceof User ? $actor : null,
            subject: $group,
            payload: [
                'group_id' => $group->id,
                'group_slug' => $group->slug,
                'user_id' => $member->id,
            ],
            topic: $type === 'groups.member_banned'
                ? NotificationTopic::GROUP_MEMBERSHIP
                : NotificationTopic::GROUP_ROLES,
            groupId: $group->id,
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
            topic: NotificationTopic::GROUP_MEMBERSHIP,
            groupId: $group->id,
        );

        $this->notificationService->sendInAppNotifications($event, $recipients);
    }

    /**
     * @return EloquentCollection<int, User>
     */
    private function moderatorRecipients(Group $group, ?int $excludeUserId = null): EloquentCollection
    {
        return User::query()
            ->whereHas('groupMemberships', function ($query) use ($group): void {
                $query
                    ->where('group_id', $group->id)
                    ->whereIn('role', [
                        GroupMembership::ROLE_OWNER,
                        GroupMembership::ROLE_ADMIN,
                        GroupMembership::ROLE_MODERATOR,
                    ]);
            })
            ->when($excludeUserId !== null, fn ($query) => $query->whereKeyNot($excludeUserId))
            ->get();
    }

    /**
     * @return EloquentCollection<int, User>
     */
    private function memberRecipients(Group $group, ?int $excludeUserId = null): EloquentCollection
    {
        return User::query()
            ->whereHas('groupMemberships', function ($query) use ($group): void {
                $query
                    ->where('group_id', $group->id);
            })
            ->when($excludeUserId !== null, fn ($query) => $query->whereKeyNot($excludeUserId))
            ->get();
    }

    private function activityTitle(Activity $activity): string
    {
        return ActivityDisplayName::for($activity);
    }

    private function formatGroupRole(string $role): string
    {
        return match ($role) {
            GroupMembership::ROLE_OWNER => 'Owner',
            GroupMembership::ROLE_ADMIN => 'Admin',
            GroupMembership::ROLE_MODERATOR => 'Moderator',
            default => 'Member',
        };
    }
}
