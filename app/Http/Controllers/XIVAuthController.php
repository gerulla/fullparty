<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Socialite\Socialite;

class XIVAuthController extends Controller
{
	public function __construct(
		private readonly AuditLogger $auditLogger
	) {}

	public function redirect() {
		return Socialite::driver('xivauth')
			->enablePKCE()
			->withEmailScope()
			->withCharactersScope()
			->redirect();
	}
	
	public function callback() {
		$xivauthUser = Socialite::driver('xivauth')
			->enablePKCE()
			->user();
		
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

			$this->auditLogger->log(
				action: 'user.logged_in',
				severity: AuditSeverity::INFO,
				scopeType: AuditScope::USER,
				scopeId: $socialAccount->user->id,
				message: 'audit_log.events.user.logged_in',
				actor: $socialAccount->user,
				subject: $socialAccount->user,
				metadata: [
					'login_method' => 'social',
					'provider' => $provider,
				],
			);
			
			return redirect()->intended(route('dashboard'));
		}
		
		$user = null;
		$createdUser = false;
		$linkingExistingSession = auth()->check();
		// If the user is already authenticated, associate this social account with the user.
		if(auth()->check()){
			$user = auth()->user();
		// If the user is not authenticated, check if a user with the email exists.
		}else if ($providerEmail) {
			$user = User::query()
				->where('email', $providerEmail)
				->first();
		}
		
		if (! $user) {
			$user = User::create([
				'name' => $xivauthUser->getName() ?: 'User-' . Str::random(6),
				'email' => $providerEmail,
				'email_verified_at' => now(),
				'avatar_url' => null,
				'password' => null,
			]);

			$createdUser = true;
		} else {
			$updates = [];
			
			if ( !$user->email_verified_at && !empty($attributes['email_verified']) && $providerEmail) {
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

		if ($createdUser) {
			$this->auditLogger->log(
				action: 'user.registered',
				severity: AuditSeverity::INFO,
				scopeType: AuditScope::USER,
				scopeId: $user->id,
				message: 'audit_log.events.user.registered',
				actor: $user,
				subject: $user,
				metadata: [
					'registration_method' => 'social',
					'provider' => $provider,
					'email' => $user->email,
				],
			);
		}

		$this->auditLogger->log(
			action: 'user.social_account.linked',
			severity: AuditSeverity::INFO,
			scopeType: AuditScope::USER,
			scopeId: $user->id,
			message: 'audit_log.events.user.social_account.linked',
			actor: $user,
			subject: $user,
			metadata: [
				'provider' => $provider,
				'provider_user_id' => $providerUserId,
				'linked_while_authenticated' => $linkingExistingSession,
			],
		);
		
		Auth::login($user);
		request()->session()->regenerate();

		$this->auditLogger->log(
			action: 'user.logged_in',
			severity: AuditSeverity::INFO,
			scopeType: AuditScope::USER,
			scopeId: $user->id,
			message: 'audit_log.events.user.logged_in',
			actor: $user,
			subject: $user,
			metadata: [
				'login_method' => 'social',
				'provider' => $provider,
			],
		);
		
		return redirect()->intended(route('dashboard'));
	}
	
	public static function getValidXivAuthAccessToken(SocialAccount $account): string
	{
		if ($account->expires_at && $account->expires_at->isFuture() && $account->access_token) {
			return $account->access_token;
		}
		
		$response = Http::asForm()->post('https://xivauth.net/oauth/token', [
			'grant_type' => 'refresh_token',
			'client_id' => config('services.xivauth.client_id'),
			'client_secret' => config('services.xivauth.client_secret'),
			'scope' => 'user character:all refresh user:email',
			'refresh_token' => $account->refresh_token
		]);
		
		if (!$response->successful()) {
			throw new \RuntimeException($response->body());
		}
		
		$data = $response->json();
		
		$account->update([
			'access_token' => $data['access_token'],
			'refresh_token' => $data['refresh_token'] ?? $account->refresh_token,
			'expires_at' => isset($data['expires_in'])
				? Carbon::now()->addSeconds((int) $data['expires_in'])
				: null,
		]);
		
		return $account->access_token;
	}
}
