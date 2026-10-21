<?php

namespace App\Services\Notifications;

use App\Models\GroupInvite;
use App\Models\User;
use App\Support\Activities\ActivityDisplayName;
use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\NotificationTopic;

final class GroupInvitationNotificationService
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function sent(GroupInvite $invite, User $actor): void
    {
        $invite->loadMissing(['group', 'recipient', 'sourceActivity.activityTypeVersion']);
        $event = $this->notifications->createEvent(
            type: 'groups.personal_invite',
            category: NotificationCategory::GROUP_UPDATES,
            titleKey: 'group_invitations.notification_title',
            bodyKey: $invite->sourceActivity ? 'group_invitations.notification_after_run' : 'group_invitations.notification_body',
            messageParams: [
                'group' => $invite->group->name,
                'activity' => $invite->sourceActivity ? ActivityDisplayName::for($invite->sourceActivity) : null,
            ],
            actionUrl: route('groups.invites.show', ['token' => $invite->token]),
            actor: $actor,
            subject: $invite,
            payload: ['group_id' => $invite->group_id, 'invite_id' => $invite->id, 'activity_id' => $invite->source_activity_id,
                'expires_at' => $invite->expires_at->toIso8601String()],
            topic: NotificationTopic::GROUP_MEMBERSHIP,
            groupId: $invite->group_id,
        );
        $this->notifications->sendInAppNotifications($event, $invite->recipient);
    }
}
