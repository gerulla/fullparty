<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Socialite;

class XIVAuthController extends Controller
{
	public function redirect() {
		return Socialite::driver('xivauth')
			->withEmailScope()
			->withCharactersScope()
			->redirect();
	}
	
	public function callback() {
		$xivauthUser = Socialite::driver('xivauth')->user();
		
		$provider = 'xivauth';
		$providerUserId = (string) $xivauthUser->getId();
		$providerEmail = $xivauthUser->getEmail();
		
		$attributes = $xivauthUser->user ?? [];
		
		$socialAccount = SocialAccount::query()
			->with('user')
			->where('provider', $provider)
			->where('provider_user_id', $providerUserId)
			->first();
		
		if ($socialAccount) {
			$socialAccount->update([
				'provider_name' => $xivauthUser->getName(),
				'provider_email' => $providerEmail,
				'avatar_url' => null,
				'access_token' => $xivauthUser->token ?? null,
				'refresh_token' => $xivauthUser->refreshToken ?? null,
				'expires_at' => isset($xivauthUser->expiresIn)
					? now()->addSeconds((int) $xivauthUser->expiresIn)
					: null,
				'provider_data' => $attributes,
			]);
			
			Auth::login($socialAccount->user);
			request()->session()->regenerate();
			
			return redirect()->intended(route('dashboard'));
		}
		
		$user = null;
		
		if ($providerEmail) {
			$user = User::query()
				->where('email', $providerEmail)
				->first();
		}
		
		if (! $user) {
			$user = User::create([
				'name' => $xivauthUser->getName() ?: 'User-' . Str::random(6),
				'email' => $providerEmail,
				'email_verified_at' => ! empty($attributes['email_verified']) && $providerEmail ? now() : null,
				'avatar_url' => null,
				'password' => null,
			]);
		} else {
			$updates = [];
			
			if (! $user->email_verified_at && ! empty($attributes['email_verified']) && $providerEmail) {
				$updates['email_verified_at'] = now();
			}
			
			if (! empty($updates)) {
				$user->update($updates);
			}
		}
		
		$user->socialAccounts()->create([
			'provider' => $provider,
			'provider_user_id' => $providerUserId,
			'provider_name' => $xivauthUser->getName(),
			'provider_email' => $providerEmail,
			'avatar_url' => null,
			'access_token' => $xivauthUser->token ?? null,
			'refresh_token' => $xivauthUser->refreshToken ?? null,
			'expires_at' => isset($xivauthUser->expiresIn)
				? now()->addSeconds((int) $xivauthUser->expiresIn)
				: null,
			'provider_data' => $attributes,
		]);
		
		Auth::login($user);
		request()->session()->regenerate();
		
		return redirect()->intended(route('dashboard'));
	}
}
