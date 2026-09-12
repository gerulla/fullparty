<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Auth\OAuthAccountLinkingPolicy;
use App\Services\Auth\SocialLoginLinkService;
use App\Services\Notifications\AccountCharacterNotificationService;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use App\Support\Auth\OAuthEmailVerification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

class GoogleAuthController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly AccountCharacterNotificationService $accountCharacterNotificationService,
        private readonly OAuthAccountLinkingPolicy $accountLinkingPolicy,
        private readonly SocialLoginLinkService $loginLinkService,
    ) {}

    public function redirect()
    {
        return $this->loginLinkService->rememberOAuthRedirect(request(), 'google', Socialite::driver('google')->redirect());
    }

    public function callback()
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            return redirect()
                ->route('settings')
                ->withErrors(['error' => 'social_oauth_invalid_state']);
        }

        $provider = 'google';
        $providerUserId = (string) $googleUser->getId();
        $providerEmail = Str::lower(trim((string) $googleUser->getEmail()));

        if (! OAuthEmailVerification::isVerified($googleUser, $provider)) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => __('auth.social_email_unverified')]);
        }

        $socialAccount = SocialAccount::query()
            ->safeSummary()
            ->with('user')
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        if ($response = $this->loginLinkService->handleCallback(request(), $provider, $googleUser, $socialAccount)) {
            return $response;
        }

        try {
            $this->accountLinkingPolicy->authorize(auth()->user(), $socialAccount, $providerEmail);
        } catch (ValidationException $exception) {
            return redirect()->route(auth()->check() ? 'settings' : 'login')->withErrors($exception->errors());
        }

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

        $user = auth()->user();
        $createdUser = false;
        $linkingExistingSession = auth()->check();

        if (! $user) {
            $user = User::forceCreate([
                'name' => $googleUser->getName() ?: 'User-'.Str::random(6),
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

            if (! empty($updates)) {
                $user->forceFill($updates)->save();
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
