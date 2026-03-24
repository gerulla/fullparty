<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialAccount extends Model
{
	protected $fillable = [
		'user_id',
		'provider',
		'provider_user_id',
		'provider_name',
		'provider_email',
		'avatar_url',
		'access_token',
		'refresh_token',
		'provider_data',
		'expires_at',
	];
	
	protected $casts = [
		'provider_data' => 'array',
		'expires_at' => 'datetime',
	];
	public function user()
	{
		return $this->belongsTo(User::class);
	}
}
