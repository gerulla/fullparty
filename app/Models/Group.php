<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Group extends Model
{
    use HasFactory;

    public const TYPE_COMMUNITY = 'community';

    public const TYPE_STATIC = 'static';

    public const JOIN_MODE_OPEN = 'open';

    public const JOIN_MODE_INVITE_ONLY = 'invite_only';

    public const JOIN_MODE_APPLICATION = 'application';

    public const TYPES = [
        self::TYPE_COMMUNITY,
        self::TYPE_STATIC,
    ];

    public const JOIN_MODES = [
        self::JOIN_MODE_OPEN,
        self::JOIN_MODE_INVITE_ONLY,
        self::JOIN_MODE_APPLICATION,
    ];

    public const COMMUNITY_JOIN_MODES = [
        self::JOIN_MODE_OPEN,
        self::JOIN_MODE_INVITE_ONLY,
        self::JOIN_MODE_APPLICATION,
    ];

    public const STATIC_JOIN_MODES = [
        self::JOIN_MODE_INVITE_ONLY,
        self::JOIN_MODE_APPLICATION,
    ];

    protected $fillable = [
        'owner_id',
        'name',
        'description',
        'profile_picture_url',
        'banner_image_url',
        'discord_invite_url',
        'discord_link_token_hash',
        'discord_link_token_expires_at',
        'datacenter',
        'is_visible',
        'slug',
        'group_type',
        'join_mode',
        'membership_application_schema',
        'primary_focuses',
        'experience_expectation',
        'voice_expectation',
        'preferred_languages',
        'tags',
        'active_timezone',
        'active_days',
        'active_start_time',
        'active_end_time',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
        'membership_application_schema' => 'array',
        'primary_focuses' => 'array',
        'preferred_languages' => 'array',
        'tags' => 'array',
        'active_days' => 'array',
        'discord_link_token_expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (Group $group): void {
            $group->features()->create(GroupFeature::defaults());
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(GroupMembership::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_memberships')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    public function invites(): HasMany
    {
        return $this->hasMany(GroupInvite::class);
    }

    public function bans(): HasMany
    {
        return $this->hasMany(GroupBan::class);
    }

    public function userNotes(): HasMany
    {
        return $this->hasMany(GroupUserNote::class);
    }

    public function systemInvite(): HasOne
    {
        return $this->hasOne(GroupInvite::class)->where('is_system', true);
    }

    public function scheduledRuns(): HasMany
    {
        return $this->hasMany(ScheduledRun::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function membershipApplications(): HasMany
    {
        return $this->hasMany(GroupMembershipApplication::class);
    }

    public function discordGuildIntegrations(): HasMany
    {
        return $this->hasMany(DiscordGuildIntegration::class);
    }

    public function activeDiscordGuildIntegration(): HasOne
    {
        return $this->hasOne(DiscordGuildIntegration::class)->whereNull('removed_at');
    }

    public function featuredGroup(): HasOne
    {
        return $this->hasOne(FeaturedGroup::class);
    }

    public function features(): HasOne
    {
        return $this->hasOne(GroupFeature::class);
    }

    public function availabilitySettings(): HasOne
    {
        return $this->hasOne(GroupAvailabilitySetting::class);
    }

    public function availabilitySchedules(): HasMany
    {
        return $this->hasMany(GroupAvailabilitySchedule::class);
    }

    /**
     * @return array<string, bool>
     */
    public function featureSettings(): array
    {
        $features = $this->relationLoaded('features')
            ? $this->features
            : $this->features()->first();

        if (! $features instanceof GroupFeature) {
            return GroupFeature::defaults();
        }

        return [
            'availability_scheduler_enabled' => $features->availability_scheduler_enabled,
            'statistics_enabled' => $features->statistics_enabled,
            'leaderboard_enabled' => $features->leaderboard_enabled,
            'calendar_sync_enabled' => $features->calendar_sync_enabled,
            'resource_hub_enabled' => $features->resource_hub_enabled,
        ];
    }

    public function featureEnabled(string $feature): bool
    {
        return (bool) ($this->featureSettings()[$feature] ?? false);
    }

    public function scopeVisible($query)
    {
        return $query->where('is_visible', true);
    }

    public function isOwnedBy(?int $userId): bool
    {
        return $userId !== null && $this->owner_id === $userId;
    }

    public function hasModeratorAccess(?int $userId): bool
    {
        if ($this->isOwnedBy($userId)) {
            return true;
        }

        if ($userId === null) {
            return false;
        }

        return $this->memberships
            ->contains(fn (GroupMembership $membership) => $membership->user_id === $userId
                && in_array($membership->role, [
                    GroupMembership::ROLE_ADMIN,
                    GroupMembership::ROLE_MODERATOR,
                ], true));
    }

    public function hasAdminAccess(?int $userId): bool
    {
        if ($this->isOwnedBy($userId)) {
            return true;
        }

        if ($userId === null) {
            return false;
        }

        return $this->memberships
            ->contains(fn (GroupMembership $membership) => $membership->user_id === $userId
                && $membership->role === GroupMembership::ROLE_ADMIN);
    }

    public function hasMember(?int $userId): bool
    {
        if ($this->isOwnedBy($userId)) {
            return true;
        }

        if ($userId === null) {
            return false;
        }

        return $this->memberships
            ->contains(fn (GroupMembership $membership) => $membership->user_id === $userId);
    }

    public function isBanned(?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        if ($this->relationLoaded('bans')) {
            return $this->bans->contains(fn (GroupBan $ban) => $ban->user_id === $userId);
        }

        return $this->bans()
            ->where('user_id', $userId)
            ->exists();
    }

    public function usesCommunityJoinFlow(): bool
    {
        return $this->group_type === self::TYPE_COMMUNITY;
    }

    public function allowsOpenJoin(): bool
    {
        return $this->usesCommunityJoinFlow()
            && $this->join_mode === self::JOIN_MODE_OPEN;
    }

    public function usesMembershipApplications(): bool
    {
        return $this->join_mode === self::JOIN_MODE_APPLICATION;
    }

    public function hasPermanentInvite(): bool
    {
        return $this->allowsOpenJoin();
    }

    /**
     * @return array<int, string>
     */
    public static function joinModesForType(string $groupType): array
    {
        return $groupType === self::TYPE_STATIC
            ? self::STATIC_JOIN_MODES
            : self::COMMUNITY_JOIN_MODES;
    }

    public function inferredRegion(): ?string
    {
        return self::regionForDatacenter($this->datacenter);
    }

    public static function regionForDatacenter(?string $datacenter): ?string
    {
        if ($datacenter === null) {
            return null;
        }

        return config("datacenters.regions.$datacenter");
    }

    public function ensureSystemInvite(): void
    {
        if (! $this->hasPermanentInvite()) {
            return;
        }

        $this->invites()->updateOrCreate(
            ['is_system' => true],
            [
                'created_by' => null,
                'token' => $this->slug,
                'max_uses' => null,
                'expires_at' => null,
            ]
        );
    }

    public function removeSystemInvite(): void
    {
        $this->invites()->where('is_system', true)->delete();
    }
}
