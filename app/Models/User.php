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
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
	'name',
	'email',
	'password',
	'email_verified_at',
	'avatar_url',
	'is_admin',
	'public_profile',
	'public_characters',
	'run_reminders',
	'application_notifications',
	'group_updates',
	'assignment_updates',
	'email_notifications',
	'discord_notifications',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
        ];
    }
	
	public function primaryCharacter(): \Illuminate\Database\Eloquent\Relations\HasOne|User
	{
		return $this->hasOne(Character::class)->where('is_primary', true);
	}
	
	public function characters(): User|\Illuminate\Database\Eloquent\Relations\HasMany
	{
		return $this->hasMany(Character::class)->where('verified_at', '!=', null);
	}
	
	public function socialAccounts(): User|\Illuminate\Database\Eloquent\Relations\HasMany
	{
		return $this->hasMany(SocialAccount::class);
	}

	public function ownedGroups(): HasMany
	{
		return $this->hasMany(Group::class, 'owner_id');
	}

	public function groupMemberships(): HasMany
	{
		return $this->hasMany(GroupMembership::class);
	}

	public function groups(): BelongsToMany
	{
		return $this->belongsToMany(Group::class, 'group_memberships')
			->withPivot(['role', 'joined_at'])
			->withTimestamps();
	}

	public function moderatedGroups(): BelongsToMany
	{
		return $this->groups()->wherePivot('role', GroupMembership::ROLE_MODERATOR);
	}

	public function memberGroups(): BelongsToMany
	{
		return $this->groups()->wherePivot('role', GroupMembership::ROLE_MEMBER);
	}

	public function organizedRuns(): HasMany
	{
		return $this->hasMany(ScheduledRun::class, 'organized_by_user_id');
	}
}
