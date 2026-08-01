<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DiscordGuildDisconnected
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $discordGuildId,
        public readonly int $groupId,
        public readonly string $groupSlug,
        public readonly string $disconnectedAt,
    ) {}
}
