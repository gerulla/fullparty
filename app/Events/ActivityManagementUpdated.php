<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ActivityManagementUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $patch
     */
    public function __construct(
        public readonly int $groupId,
        public readonly int $activityId,
        public readonly array $patch,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(sprintf('groups.%d.activities.%d.management', $this->groupId, $this->activityId)),
        ];
    }

    public function broadcastAs(): string
    {
        return 'activity.management.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'group_id' => $this->groupId,
            'activity_id' => $this->activityId,
            'patch' => $this->patch,
        ];
    }
}
