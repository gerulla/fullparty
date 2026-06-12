<?php

namespace App\Support\Notifications;

use InvalidArgumentException;

class NotificationTopic
{
    public const APPLICATIONS_SUBMITTED = 'applications.submitted';

    public const APPLICATIONS_REVIEW = 'applications.review';

    public const APPLICATIONS_HOST_UPDATES = 'applications.host_updates';

    public const APPLICATIONS_OUTCOMES = 'applications.outcomes';

    public const ASSIGNMENTS_ROSTER = 'assignments.roster';

    public const ASSIGNMENTS_BENCH = 'assignments.bench';

    public const ASSIGNMENTS_STATUS = 'assignments.status';

    public const ASSIGNMENTS_DESIGNATIONS = 'assignments.designations';

    public const RUNS_REMINDERS = 'runs.reminders';

    public const RUNS_LIFECYCLE = 'runs.lifecycle';

    public const GROUP_RUN_POSTS = 'group_updates.run_posts';

    public const GROUP_MEMBERSHIP = 'group_updates.membership';

    public const GROUP_ROLES = 'group_updates.roles';

    public const ACCOUNT_CONNECTED_ACCOUNTS = 'account.connected_accounts';

    public const ACCOUNT_SETTINGS = 'account.settings';

    public const CHARACTER_CHANGES = 'characters.changes';

    public const CHARACTER_REFRESHES = 'characters.refreshes';

    public const SYSTEM_MAINTENANCE = 'system.maintenance';

    public const SYSTEM_ANNOUNCEMENTS = 'system.announcements';

    public const SYSTEM_ADMIN_ALERTS = 'system.admin_alerts';

    public const VALUES = [
        self::APPLICATIONS_SUBMITTED,
        self::APPLICATIONS_REVIEW,
        self::APPLICATIONS_HOST_UPDATES,
        self::APPLICATIONS_OUTCOMES,
        self::ASSIGNMENTS_ROSTER,
        self::ASSIGNMENTS_BENCH,
        self::ASSIGNMENTS_STATUS,
        self::ASSIGNMENTS_DESIGNATIONS,
        self::RUNS_REMINDERS,
        self::RUNS_LIFECYCLE,
        self::GROUP_RUN_POSTS,
        self::GROUP_MEMBERSHIP,
        self::GROUP_ROLES,
        self::ACCOUNT_CONNECTED_ACCOUNTS,
        self::ACCOUNT_SETTINGS,
        self::CHARACTER_CHANGES,
        self::CHARACTER_REFRESHES,
        self::SYSTEM_MAINTENANCE,
        self::SYSTEM_ANNOUNCEMENTS,
        self::SYSTEM_ADMIN_ALERTS,
    ];

    public const GROUP_OVERRIDE_VALUES = [
        self::GROUP_RUN_POSTS,
        self::GROUP_MEMBERSHIP,
        self::GROUP_ROLES,
    ];

    public const DISCORD_DEFAULT_ENABLED_VALUES = [
        self::APPLICATIONS_SUBMITTED,
        self::APPLICATIONS_HOST_UPDATES,
        self::APPLICATIONS_OUTCOMES,
        self::ASSIGNMENTS_ROSTER,
        self::ASSIGNMENTS_BENCH,
        self::ASSIGNMENTS_STATUS,
        self::ASSIGNMENTS_DESIGNATIONS,
        self::RUNS_REMINDERS,
        self::RUNS_LIFECYCLE,
        self::ACCOUNT_CONNECTED_ACCOUNTS,
        self::CHARACTER_CHANGES,
    ];

    public const CATEGORY_BY_TOPIC = [
        self::APPLICATIONS_SUBMITTED => NotificationCategory::APPLICATIONS,
        self::APPLICATIONS_REVIEW => NotificationCategory::APPLICATIONS,
        self::APPLICATIONS_HOST_UPDATES => NotificationCategory::APPLICATIONS,
        self::APPLICATIONS_OUTCOMES => NotificationCategory::APPLICATIONS,
        self::ASSIGNMENTS_ROSTER => NotificationCategory::ASSIGNMENTS,
        self::ASSIGNMENTS_BENCH => NotificationCategory::ASSIGNMENTS,
        self::ASSIGNMENTS_STATUS => NotificationCategory::ASSIGNMENTS,
        self::ASSIGNMENTS_DESIGNATIONS => NotificationCategory::ASSIGNMENTS,
        self::RUNS_REMINDERS => NotificationCategory::RUNS_AND_REMINDERS,
        self::RUNS_LIFECYCLE => NotificationCategory::RUNS_AND_REMINDERS,
        self::GROUP_RUN_POSTS => NotificationCategory::GROUP_UPDATES,
        self::GROUP_MEMBERSHIP => NotificationCategory::GROUP_UPDATES,
        self::GROUP_ROLES => NotificationCategory::GROUP_UPDATES,
        self::ACCOUNT_CONNECTED_ACCOUNTS => NotificationCategory::ACCOUNT_CHARACTER_UPDATES,
        self::ACCOUNT_SETTINGS => NotificationCategory::ACCOUNT_CHARACTER_UPDATES,
        self::CHARACTER_CHANGES => NotificationCategory::ACCOUNT_CHARACTER_UPDATES,
        self::CHARACTER_REFRESHES => NotificationCategory::ACCOUNT_CHARACTER_UPDATES,
        self::SYSTEM_MAINTENANCE => NotificationCategory::SYSTEM_NOTICES,
        self::SYSTEM_ANNOUNCEMENTS => NotificationCategory::SYSTEM_NOTICES,
        self::SYSTEM_ADMIN_ALERTS => NotificationCategory::SYSTEM_NOTICES,
    ];

    public const TOPIC_BY_TYPE = [
        'applications.new_for_review' => self::APPLICATIONS_REVIEW,
        'applications.submitted' => self::APPLICATIONS_SUBMITTED,
        'applications.updated' => self::APPLICATIONS_HOST_UPDATES,
        'applications.withdrawn_for_review' => self::APPLICATIONS_HOST_UPDATES,
        'applications.withdrawal_confirmed' => self::APPLICATIONS_SUBMITTED,
        'applications.declined' => self::APPLICATIONS_OUTCOMES,
        'applications.cancelled' => self::APPLICATIONS_OUTCOMES,
        'assignments.roster_published_assigned' => self::ASSIGNMENTS_ROSTER,
        'assignments.assigned' => self::ASSIGNMENTS_ROSTER,
        'assignments.returned_to_queue' => self::ASSIGNMENTS_ROSTER,
        'assignments.removed' => self::ASSIGNMENTS_ROSTER,
        'assignments.roster_published_bench' => self::ASSIGNMENTS_BENCH,
        'assignments.on_bench' => self::ASSIGNMENTS_BENCH,
        'assignments.marked_missing' => self::ASSIGNMENTS_STATUS,
        'assignments.missing_restored' => self::ASSIGNMENTS_STATUS,
        'assignments.designation_assigned' => self::ASSIGNMENTS_DESIGNATIONS,
        'assignments.designation_removed' => self::ASSIGNMENTS_DESIGNATIONS,
        'runs.starting_soon' => self::RUNS_REMINDERS,
        'runs.starting_now' => self::RUNS_REMINDERS,
        'runs.cancelled' => self::RUNS_LIFECYCLE,
        'runs.completed' => self::RUNS_LIFECYCLE,
        'groups.run_draft' => self::GROUP_RUN_POSTS,
        'groups.run_scheduled' => self::GROUP_RUN_POSTS,
        'groups.member_joined' => self::GROUP_MEMBERSHIP,
        'groups.member_left' => self::GROUP_MEMBERSHIP,
        'groups.member_banned' => self::GROUP_MEMBERSHIP,
        'groups.member_upcoming_assignments_cleared' => self::GROUP_MEMBERSHIP,
        'groups.member_promoted' => self::GROUP_ROLES,
        'groups.member_demoted' => self::GROUP_ROLES,
        'groups.ownership_transferred_from_you' => self::GROUP_ROLES,
        'groups.ownership_transferred_to_you' => self::GROUP_ROLES,
        'user.social_account.linked' => self::ACCOUNT_CONNECTED_ACCOUNTS,
        'user.social_account.unlinked' => self::ACCOUNT_CONNECTED_ACCOUNTS,
        'user.settings.username_updated' => self::ACCOUNT_SETTINGS,
        'user.settings.profile_picture_updated' => self::ACCOUNT_SETTINGS,
        'user.settings.notifications_updated' => self::ACCOUNT_SETTINGS,
        'user.settings.privacy_updated' => self::ACCOUNT_SETTINGS,
        'user.settings.password_updated' => self::ACCOUNT_SETTINGS,
        'characters.added' => self::CHARACTER_CHANGES,
        'characters.primary_changed' => self::CHARACTER_CHANGES,
        'characters.unclaimed' => self::CHARACTER_CHANGES,
        'characters.refreshed' => self::CHARACTER_REFRESHES,
        'system.maintenance.upcoming' => self::SYSTEM_MAINTENANCE,
        'system.announcement' => self::SYSTEM_ANNOUNCEMENTS,
        'integration.event_delivery_failed' => self::SYSTEM_ADMIN_ALERTS,
    ];

    public const SUPPORTED_CHANNELS_BY_TOPIC = [
        self::APPLICATIONS_SUBMITTED => NotificationPreferenceChannel::VALUES,
        self::APPLICATIONS_REVIEW => [NotificationPreferenceChannel::IN_APP],
        self::APPLICATIONS_HOST_UPDATES => NotificationPreferenceChannel::VALUES,
        self::APPLICATIONS_OUTCOMES => NotificationPreferenceChannel::VALUES,
        self::ASSIGNMENTS_ROSTER => NotificationPreferenceChannel::VALUES,
        self::ASSIGNMENTS_BENCH => NotificationPreferenceChannel::VALUES,
        self::ASSIGNMENTS_STATUS => NotificationPreferenceChannel::VALUES,
        self::ASSIGNMENTS_DESIGNATIONS => NotificationPreferenceChannel::VALUES,
        self::RUNS_REMINDERS => NotificationPreferenceChannel::VALUES,
        self::RUNS_LIFECYCLE => NotificationPreferenceChannel::VALUES,
        self::GROUP_RUN_POSTS => [NotificationPreferenceChannel::IN_APP],
        self::GROUP_MEMBERSHIP => [NotificationPreferenceChannel::IN_APP],
        self::GROUP_ROLES => [NotificationPreferenceChannel::IN_APP],
        self::ACCOUNT_CONNECTED_ACCOUNTS => NotificationPreferenceChannel::VALUES,
        self::ACCOUNT_SETTINGS => [NotificationPreferenceChannel::IN_APP],
        self::CHARACTER_CHANGES => NotificationPreferenceChannel::VALUES,
        self::CHARACTER_REFRESHES => [NotificationPreferenceChannel::IN_APP],
        self::SYSTEM_MAINTENANCE => NotificationPreferenceChannel::VALUES,
        self::SYSTEM_ANNOUNCEMENTS => NotificationPreferenceChannel::VALUES,
        self::SYSTEM_ADMIN_ALERTS => [NotificationPreferenceChannel::IN_APP],
    ];

    public const FALLBACK_TOPIC_BY_CATEGORY = [
        NotificationCategory::APPLICATIONS => self::APPLICATIONS_SUBMITTED,
        NotificationCategory::ASSIGNMENTS => self::ASSIGNMENTS_ROSTER,
        NotificationCategory::RUNS_AND_REMINDERS => self::RUNS_REMINDERS,
        NotificationCategory::GROUP_UPDATES => self::GROUP_RUN_POSTS,
        NotificationCategory::ACCOUNT_CHARACTER_UPDATES => self::CHARACTER_CHANGES,
        NotificationCategory::SYSTEM_NOTICES => self::SYSTEM_ANNOUNCEMENTS,
    ];

    public static function ensureValid(string $topic): void
    {
        if (! in_array($topic, self::VALUES, true)) {
            throw new InvalidArgumentException("Invalid notification topic [{$topic}] supplied.");
        }
    }

    public static function forType(string $type, string $category): string
    {
        return self::TOPIC_BY_TYPE[$type] ?? self::FALLBACK_TOPIC_BY_CATEGORY[$category];
    }

    public static function categoryForTopic(string $topic): string
    {
        self::ensureValid($topic);

        return self::CATEGORY_BY_TOPIC[$topic];
    }

    /**
     * @return array<int, string>
     */
    public static function supportedChannels(string $topic): array
    {
        self::ensureValid($topic);

        return self::SUPPORTED_CHANNELS_BY_TOPIC[$topic];
    }

    public static function supportsChannel(string $topic, string $channel): bool
    {
        NotificationPreferenceChannel::ensureValid($channel);

        return in_array($channel, self::supportedChannels($topic), true);
    }

    public static function isGroupOverrideTopic(string $topic): bool
    {
        self::ensureValid($topic);

        return in_array($topic, self::GROUP_OVERRIDE_VALUES, true);
    }

    public static function defaultsToDiscordEnabled(string $topic): bool
    {
        self::ensureValid($topic);

        return in_array($topic, self::DISCORD_DEFAULT_ENABLED_VALUES, true);
    }
}
