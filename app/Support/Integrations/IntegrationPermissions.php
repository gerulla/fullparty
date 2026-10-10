<?php

namespace App\Support\Integrations;

use App\Models\IntegrationClient as Client;

final class IntegrationPermissions
{
    public const MEMBERS_READ = 'members:read';

    public const MEMBERS_WRITE = 'members:write';

    public static function scopeGroups(): array
    {
        return [
            ['key' => 'lookup', 'permissions' => [Client::SCOPE_RUNS_READ, Client::SCOPE_USERS_READ, Client::SCOPE_RESOURCES_READ]],
            ['key' => 'connections', 'permissions' => [Client::SCOPE_USERS_WRITE, Client::SCOPE_GUILDS_WRITE]],
            ['key' => 'members_read', 'permissions' => [self::MEMBERS_READ]],
            ['key' => 'members_write', 'permissions' => [self::MEMBERS_WRITE]],
        ];
    }

    public static function eventGroups(): array
    {
        return [
            ['key' => 'accounts', 'permissions' => [Client::EVENT_DISCORD_USER_APP_INSTALLED, Client::EVENT_DISCORD_USER_APP_DISCONNECTED, Client::EVENT_USER_DISCORD_LOGIN]],
            ['key' => 'notifications', 'permissions' => [Client::EVENT_DISCORD_NOTIFICATION_DELIVERY]],
            ['key' => 'runs', 'permissions' => [Client::EVENT_DISCORD_GUILD_RUN_STARTING_SOON, Client::EVENT_DISCORD_GUILD_RUN_STARTING_NOW, Client::EVENT_DISCORD_GUILD_RUN_COMPLETED, Client::EVENT_DISCORD_GUILD_RUN_CANCELLED, Client::EVENT_DISCORD_GUILD_RUN_PARTICIPANT_SYNC, Client::EVENT_DISCORD_GUILD_RUNS_CHANGED]],
            ['key' => 'guilds', 'permissions' => [Client::EVENT_DISCORD_GUILD_SNAPSHOT_REQUESTED, Client::EVENT_DISCORD_GUILD_MEMBERSHIP_SNAPSHOT_REQUESTED, Client::EVENT_DISCORD_GUILD_SETTINGS_UPDATED]],
            ['key' => 'admin_reports', 'permissions' => [Client::EVENT_DISCORD_ADMIN_REPORT]],
        ];
    }

    public static function scopes(): array
    {
        // Keep the existing option order for compatibility with clients of the admin page.
        return [Client::SCOPE_RUNS_READ, Client::SCOPE_USERS_READ, Client::SCOPE_USERS_WRITE, Client::SCOPE_GUILDS_WRITE, Client::SCOPE_RESOURCES_READ, self::MEMBERS_READ, self::MEMBERS_WRITE];
    }
}
