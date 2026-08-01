<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class IntegrationClient extends Model
{
    use HasFactory;

    public const TYPE_DISCORD_BOT = 'discord_bot';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_REVOKED = 'revoked';

    public const SCOPE_RUNS_READ = 'runs:read';

    public const SCOPE_USERS_READ = 'users:read';

    public const SCOPE_USERS_WRITE = 'users:write';

    public const SCOPE_GUILDS_WRITE = 'guilds:write';

    public const EVENT_DISCORD_USER_APP_INSTALLED = 'discord.user_app.installed';

    public const EVENT_DISCORD_USER_APP_DISCONNECTED = 'discord.user_app.disconnected';

    public const EVENT_DISCORD_NOTIFICATION_DELIVERY = 'discord.notification.delivery';

    public const EVENT_DISCORD_GUILD_RUN_REMINDER = 'discord.guild.run_reminder';

    public const EVENT_DISCORD_GUILD_RUN_STARTING_SOON = 'discord.guild.run_starting_soon';

    public const EVENT_DISCORD_GUILD_RUN_STARTING_NOW = 'discord.guild.run_starting_now';

    public const EVENT_DISCORD_GUILD_RUN_COMPLETED = 'discord.guild.run_completed';

    public const EVENT_DISCORD_GUILD_RUN_CANCELLED = 'discord.guild.run_cancelled';

    public const EVENT_DISCORD_GUILD_SNAPSHOT_REQUESTED = 'discord.guild.snapshot_requested';

    public const EVENT_DISCORD_GUILD_MEMBERSHIP_SNAPSHOT_REQUESTED = 'discord.guild.membership_snapshot_requested';

    public const EVENT_DISCORD_GUILD_SETTINGS_UPDATED = 'discord.guild.settings_updated';

    public const EVENT_DISCORD_GUILD_DISCONNECTED = 'discord.guild.disconnected';

    protected $fillable = [
        'created_by_user_id',
        'name',
        'type',
        'status',
        'outbound_events_url',
        'healthcheck_url',
        'webhook_signing_secret',
        'api_token_hash',
        'scopes',
        'allowed_events',
        'last_event_sent_at',
        'last_event_failed_at',
        'last_event_error',
        'last_healthcheck_at',
        'last_healthcheck_ok_at',
        'last_healthcheck_failed_at',
        'last_healthcheck_error',
        'last_api_used_at',
    ];

    protected function casts(): array
    {
        return [
            'webhook_signing_secret' => 'encrypted',
            'scopes' => 'array',
            'allowed_events' => 'array',
            'last_event_sent_at' => 'datetime',
            'last_event_failed_at' => 'datetime',
            'last_healthcheck_at' => 'datetime',
            'last_healthcheck_ok_at' => 'datetime',
            'last_healthcheck_failed_at' => 'datetime',
            'last_api_used_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function healthChecks(): HasMany
    {
        return $this->hasMany(IntegrationClientHealthCheck::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    public function allowsEvent(string $event): bool
    {
        return in_array($event, $this->allowed_events ?? [], true);
    }

    public static function makePlainApiToken(): string
    {
        return 'fp_'.Str::random(64);
    }

    public static function hashApiToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
