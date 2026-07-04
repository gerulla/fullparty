<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

#[Fillable([
    'name',
    'email',
    'avatar_url',
    'public_profile',
    'public_characters',
    'application_notifications',
    'run_and_reminder_notifications',
    'group_update_notifications',
    'assignment_notifications',
    'account_character_notifications',
    'system_notice_notifications',
    'email_notifications',
    'discord_notifications',
    'discord_link_token_hash',
    'discord_link_token_expires_at',
    'time_display_mode',
    'notification_preferences_reviewed_at',
    'account_completion_celebrated_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const TIME_DISPLAY_LOCAL = 'local';

    public const TIME_DISPLAY_SERVER = 'server';

    public const TIME_DISPLAY_MODES = [
        self::TIME_DISPLAY_LOCAL,
        self::TIME_DISPLAY_SERVER,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'public_profile' => 'boolean',
            'public_characters' => 'boolean',
            'application_notifications' => 'boolean',
            'run_and_reminder_notifications' => 'boolean',
            'group_update_notifications' => 'boolean',
            'assignment_notifications' => 'boolean',
            'account_character_notifications' => 'boolean',
            'system_notice_notifications' => 'boolean',
            'email_notifications' => 'boolean',
            'discord_notifications' => 'boolean',
            'discord_link_token_expires_at' => 'datetime',
            'notification_preferences_reviewed_at' => 'datetime',
            'account_completion_celebrated_at' => 'datetime',
        ];
    }

    public function primaryCharacter(): HasOne|User
    {
        return $this->hasOne(Character::class)->where('is_primary', true);
    }

    public function characters(): User|HasMany
    {
        return $this->hasMany(Character::class)->where('verified_at', '!=', null);
    }

    public function socialAccounts(): User|HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function discordUserIntegration(): HasOne
    {
        return $this->hasOne(DiscordUserIntegration::class)->whereNull('revoked_at');
    }

    public function inAppNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class)->latest();
    }

    public function notificationDeliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class)->latest();
    }

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(UserNotificationPreference::class);
    }

    public function groupNotificationPreferences(): HasMany
    {
        return $this->hasMany(GroupNotificationPreference::class);
    }

    public function ownedGroups(): HasMany
    {
        return $this->hasMany(Group::class, 'owner_id');
    }

    public function groupMemberships(): HasMany
    {
        return $this->hasMany(GroupMembership::class);
    }

    public function receivedGroupNotes(): HasMany
    {
        return $this->hasMany(GroupUserNote::class);
    }

    public function authoredGroupNotes(): HasMany
    {
        return $this->hasMany(GroupUserNote::class, 'author_user_id');
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_memberships')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    public function moderatedGroups(): BelongsToMany
    {
        return $this->groups()->wherePivotIn('role', [
            GroupMembership::ROLE_ADMIN,
            GroupMembership::ROLE_MODERATOR,
        ]);
    }

    public function memberGroups(): BelongsToMany
    {
        return $this->groups()->wherePivot('role', GroupMembership::ROLE_MEMBER);
    }

    public function organizedRuns(): HasMany
    {
        return $this->hasMany(ScheduledRun::class, 'organized_by_user_id');
    }

    public function createdActivityTypes(): HasMany
    {
        return $this->hasMany(ActivityType::class, 'created_by_user_id');
    }

    public function publishedActivityTypeVersions(): HasMany
    {
        return $this->hasMany(ActivityTypeVersion::class, 'published_by_user_id');
    }

    public function activityApplicationDefaults(): HasMany
    {
        return $this->hasMany(UserActivityApplicationDefault::class);
    }

    public function savedActivities(): BelongsToMany
    {
        return $this->belongsToMany(Activity::class, 'activity_saves')
            ->withTimestamps();
    }

    public function homeProfile(): HasOne
    {
        return $this->hasOne(UserHomeProfile::class);
    }

    public function onboardingState(): HasOne
    {
        return $this->hasOne(UserOnboardingState::class);
    }
}
