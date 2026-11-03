<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\Group;
use App\Models\User;
use App\Services\Notifications\UserNotificationSerializer;
use App\Support\Notifications\NotificationTopic;
use Illuminate\Support\Collection;

class GroupHostedRunUpdatesService
{
    public function __construct(private readonly UserNotificationSerializer $serializer) {}

    /**
     * @param  Collection<int, Activity>  $hostedActivities  Already-visible, non-archived runs.
     * @return array<int, array<string, mixed>>
     */
    public function forHost(Group $group, ?User $user, Collection $hostedActivities): array
    {
        if (! $user || ! $group->hasModeratorAccess($user->id) || $group->isBanned($user->id)) {
            return [];
        }

        $runIds = $hostedActivities
            ->where('group_id', $group->id)
            ->where('organized_by_user_id', $user->id)
            ->pluck('id');

        if ($runIds->isEmpty()) {
            return [];
        }

        // Read the existing inbox so aggregation, delivery preferences and read state
        // stay consistent with the notification bell. Never recreate alerts from audits.
        $notifications = $user->inAppNotifications()
            ->whereHas('notificationEvent', fn ($events) => $events
                ->where('group_id', $group->id)
                ->whereIn('topic', [NotificationTopic::APPLICATIONS_REVIEW, NotificationTopic::APPLICATIONS_HOST_UPDATES])
                ->whereIn('payload->activity_id', $runIds))
            ->with('notificationEvent')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return $this->serializer->serializeCollection($notifications);
    }
}
