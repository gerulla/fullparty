<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'email_verified_at', 'avatar_url'])]
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
		return $this->hasMany(Character::class);
	}
	
	public function socialAccounts(): User|\Illuminate\Database\Eloquent\Relations\HasMany
	{
		return $this->hasMany(SocialAccount::class);
	}
}
