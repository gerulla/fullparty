<?php

namespace App\Support\Notifications;

use InvalidArgumentException;

class NotificationChannel
{
    public const EMAIL = 'email';

    public const DISCORD = 'discord';

    public const VALUES = [
        self::EMAIL,
        self::DISCORD,
    ];

    public static function ensureValid(string $channel): void
    {
        if (!in_array($channel, self::VALUES, true)) {
            throw new InvalidArgumentException("Invalid notification channel [{$channel}] supplied.");
        }
    }
}
