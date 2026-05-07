<?php

namespace App\Services\Notifications;

use App\Events\UserNotificationsUpdated;
use App\Models\User;

class NotificationRealtimeService
{
    public function broadcastUserInboxUpdated(User|int $user): void
    {
        event(new UserNotificationsUpdated(
            $user instanceof User ? (int) $user->id : (int) $user,
        ));
    }
}
