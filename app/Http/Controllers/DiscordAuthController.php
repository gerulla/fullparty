<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Notifications\AccountCharacterNotificationService;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Socialite;

class DiscordAuthController extends Controller
{
	public function __construct(
		private readonly AuditLogger $auditLogger,
        private readonly AccountCharacterNotificationService $accountCharacterNotificationService,
	) {}

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
		if(auth()->check()) {
			$user = auth()->user();
			// If the user is not authenticated, check if a user with the email exists.
		}else if ($providerEmail) {
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

			$createdUser = true;
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

        $this->accountCharacterNotificationService->notifySocialAccountLinked($user, $provider, $user);
		
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
}
