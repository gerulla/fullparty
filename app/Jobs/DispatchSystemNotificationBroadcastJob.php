<?php

namespace App\Jobs;

use App\Models\SystemNotificationBroadcast;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchSystemNotificationBroadcastJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $broadcastId,
        public readonly int $chunkSize = 1000,
    ) {}

    public function handle(): void
    {
        $broadcast = SystemNotificationBroadcast::query()->find($this->broadcastId);

        if (!$broadcast) {
            return;
        }

        $maxUserId = User::query()->max('id');

        if (!$maxUserId) {
            return;
        }

        for ($rangeStart = 1; $rangeStart <= $maxUserId; $rangeStart += $this->chunkSize) {
            DispatchSystemNotificationBroadcastChunkJob::dispatch(
                broadcastId: $broadcast->id,
                minUserId: $rangeStart,
                maxUserId: min($rangeStart + $this->chunkSize - 1, $maxUserId),
            );
        }
    }
}
