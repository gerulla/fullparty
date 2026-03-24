<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Socialite;

class DiscordAuthController extends Controller
{
	public function redirect() {
		return Socialite::driver('discord')->redirect();
	}
	
	public function callback() {
		$discordUser = Socialite::driver('discord')->user();
		$provider = 'discord';
		$providerUserId = (string) $discordUser->getId();
		$providerEmail = $discordUser->getEmail();
		
		$socialAccount = SocialAccount::query()
			->with('user')
			->where('provider', $provider)
			->where('provider_user_id', $providerUserId)
			->first();
		
		//If the user is already connected to the social account, we can log them in.
		if ($socialAccount) {
			$socialAccount->update([
				'provider_name' => $discordUser->getName() ?: $discordUser->getNickname(),
				'provider_email' => $providerEmail,
				'avatar_url' => $discordUser->getAvatar(),
				'access_token' => $discordUser->token ?? null,
				'refresh_token' => $discordUser->refreshToken ?? null,
				'expires_at' => isset($discordUser->expiresIn)
					? now()->addSeconds((int) $discordUser->expiresIn)
					: null,
				'provider_data' => [
					'nickname' => $discordUser->getNickname(),
					'name' => $discordUser->getName(),
					'avatar' => $discordUser->getAvatar(),
				],
			]);
			
			Auth::login($socialAccount->user);
			request()->session()->regenerate();
			
			return redirect()->intended(route('dashboard'));
		}
		
		//If the user is not connected to the social account, we need to check if the user exists
		$user = null;
		if ($providerEmail) {
			$user = User::query()
				->where('email', $providerEmail)
				->first();
		}
		// If the user doesn't exist, we need to create a new user
		if (! $user) {
			$user = User::create([
				'name' => $discordUser->getName()
					?: $discordUser->getNickname()
						?: 'User-' . Str::random(6),
				'email' => $providerEmail,
				'email_verified_at' => $providerEmail ? now() : null,
				'avatar_url' => $discordUser->getAvatar(),
				'password' => null,
			]);
		} else {
			// If the user exists, check and update the avatar if it's not set
			if (!$user->avatar_url && $discordUser->getAvatar()) {
				$user->update([
					'avatar_url' => $discordUser->getAvatar(),
				]);
			}
		}
		
		// Finally, we can create a new social account for the user
		$user->socialAccounts()->create([
			'provider' => $provider,
			'provider_user_id' => $providerUserId,
			'provider_name' => $discordUser->getName() ?: $discordUser->getNickname(),
			'provider_email' => $providerEmail,
			'avatar_url' => $discordUser->getAvatar(),
			'access_token' => $discordUser->token ?? null,
			'refresh_token' => $discordUser->refreshToken ?? null,
			'expires_at' => isset($discordUser->expiresIn)
				? now()->addSeconds((int) $discordUser->expiresIn)
				: null,
			'provider_data' => [
				'nickname' => $discordUser->getNickname(),
				'name' => $discordUser->getName(),
				'avatar' => $discordUser->getAvatar(),
			],
		]);
		
		Auth::login($user);
		request()->session()->regenerate();
		
		return redirect()->intended(route('dashboard'));
	}
}
