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

class DiscordAuthController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly AccountCharacterNotificationService $accountCharacterNotificationService,
        private readonly OAuthAccountLinkingPolicy $accountLinkingPolicy,
        private readonly SocialLoginLinkService $loginLinkService,
    ) {}

    public function redirect()
    {
        return $this->loginLinkService->rememberOAuthRedirect(request(), 'discord', Socialite::driver('discord')->redirect());
    }

    public function callback()
    {
        try {
            $discordUser = Socialite::driver('discord')->user();
        } catch (InvalidStateException) {
            return redirect()
                ->route('settings')
                ->withErrors(['error' => 'social_oauth_invalid_state']);
        }

        $provider = 'discord';
        $providerUserId = (string) $discordUser->getId();
        $providerEmail = Str::lower(trim((string) $discordUser->getEmail()));

        if (! OAuthEmailVerification::isVerified($discordUser, $provider)) {
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

        if ($response = $this->loginLinkService->handleCallback(request(), $provider, $discordUser, $socialAccount)) {
            return $response;
        }

        try {
            $this->accountLinkingPolicy->authorize(auth()->user(), $socialAccount, $providerEmail);
        } catch (ValidationException $exception) {
            return redirect()->route(auth()->check() ? 'settings' : 'login')->withErrors($exception->errors());
        }

        // If the user is already connected to the social account, we can log them in.
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

        $user = auth()->user();
        $createdUser = false;
        $linkingExistingSession = auth()->check();
        // If the user doesn't exist, we need to create a new user
        if (! $user) {
            $user = User::forceCreate([
                'name' => $discordUser->getName()
                    ?: $discordUser->getNickname()
                        ?: 'User-'.Str::random(6),
                'email' => $providerEmail,
                'email_verified_at' => $providerEmail ? now() : null,
                'avatar_url' => $discordUser->getAvatar(),
                'password' => null,
            ]);

            $createdUser = true;
        } else {
            // If the user exists, check and update the avatar if it's not set
            if (! $user->avatar_url && $discordUser->getAvatar()) {
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
