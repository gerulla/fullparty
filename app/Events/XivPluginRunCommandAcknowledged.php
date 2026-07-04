<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class XivPluginRunCommandAcknowledged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $acknowledgement
     */
    public function __construct(
        public readonly int $activityId,
        public readonly array $acknowledgement,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PresenceChannel(sprintf('xivplugin.runs.%d', $this->activityId)),
        ];
    }

    public function broadcastAs(): string
    {
        return 'xivplugin.run.command.acknowledged';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->acknowledgement;
    }
}
