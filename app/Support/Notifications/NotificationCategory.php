<?php

namespace App\Support\Notifications;

use InvalidArgumentException;

class NotificationCategory
{
    public const APPLICATIONS = 'applications';

    public const ASSIGNMENTS = 'assignments';

    public const RUNS_AND_REMINDERS = 'runs_and_reminders';

    public const GROUP_UPDATES = 'group_updates';

    public const ACCOUNT_CHARACTER_UPDATES = 'account_character_updates';

    public const SYSTEM_NOTICES = 'system_notices';

    public const VALUES = [
        self::APPLICATIONS,
        self::ASSIGNMENTS,
        self::RUNS_AND_REMINDERS,
        self::GROUP_UPDATES,
        self::ACCOUNT_CHARACTER_UPDATES,
        self::SYSTEM_NOTICES,
    ];

    public const PREFERENCE_FIELDS = [
        self::APPLICATIONS => 'application_notifications',
        self::ASSIGNMENTS => 'assignment_notifications',
        self::RUNS_AND_REMINDERS => 'run_and_reminder_notifications',
        self::GROUP_UPDATES => 'group_update_notifications',
        self::ACCOUNT_CHARACTER_UPDATES => 'account_character_notifications',
        self::SYSTEM_NOTICES => 'system_notice_notifications',
    ];

    public static function ensureValid(string $category): void
    {
        if (!in_array($category, self::VALUES, true)) {
            throw new InvalidArgumentException("Invalid notification category [{$category}] supplied.");
        }
    }

    public static function preferenceField(string $category): string
    {
        self::ensureValid($category);

        return self::PREFERENCE_FIELDS[$category];
    }
}
