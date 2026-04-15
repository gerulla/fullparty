<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Socialite;

class GoogleAuthController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger
    ) {}

    public function redirect() {
		return Socialite::driver('google')->redirect();
	}
	
	public function callback() {
		$googleUser = Socialite::driver('google')->user();
		
		$provider = 'google';
		$providerUserId = (string) $googleUser->getId();
		$providerEmail = $googleUser->getEmail();
		
		$socialAccount = SocialAccount::query()
			->with('user')
			->where('provider', $provider)
			->where('provider_user_id', $providerUserId)
			->first();
		
		if ($socialAccount) {
			$socialAccount->update([
				'provider_name' => $googleUser->getName(),
				'provider_email' => $providerEmail,
				'avatar_url' => $googleUser->getAvatar(),
				'access_token' => $googleUser->token ?? null,
				'refresh_token' => $googleUser->refreshToken ?? null,
				'expires_at' => isset($googleUser->expiresIn)
					? now()->addSeconds((int) $googleUser->expiresIn)
					: null,
				'provider_data' => [
					'name' => $googleUser->getName(),
					'nickname' => $googleUser->getNickname(),
					'avatar' => $googleUser->getAvatar(),
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
		
		if (! $user) {
			$user = User::create([
				'name' => $googleUser->getName() ?: 'User-' . Str::random(6),
				'email' => $providerEmail,
				'email_verified_at' => $providerEmail ? now() : null,
				'avatar_url' => $googleUser->getAvatar(),
				'password' => null,
			]);

            $createdUser = true;
		} else {
			$updates = [];
			
			if (! $user->avatar_url && $googleUser->getAvatar()) {
				$updates['avatar_url'] = $googleUser->getAvatar();
			}
			
			if (! $user->email_verified_at && $providerEmail) {
				$updates['email_verified_at'] = now();
			}
			
			if (! empty($updates)) {
				$user->update($updates);
			}
		}
		
		$user->socialAccounts()->create([
			'provider' => $provider,
			'provider_user_id' => $providerUserId,
			'provider_name' => $googleUser->getName(),
			'provider_email' => $providerEmail,
			'avatar_url' => $googleUser->getAvatar(),
			'access_token' => $googleUser->token ?? null,
			'refresh_token' => $googleUser->refreshToken ?? null,
			'expires_at' => isset($googleUser->expiresIn)
				? now()->addSeconds((int) $googleUser->expiresIn)
				: null,
			'provider_data' => [
				'name' => $googleUser->getName(),
				'nickname' => $googleUser->getNickname(),
				'avatar' => $googleUser->getAvatar(),
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
