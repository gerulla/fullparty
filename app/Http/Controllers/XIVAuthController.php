<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Auth\OAuthAccountLinkingPolicy;
use App\Services\Auth\SocialLoginLinkService;
use App\Services\Characters\XIVAuthAccessTokenService;
use App\Services\Characters\XIVAuthCharacterSyncResult;
use App\Services\Characters\XIVAuthCharacterSyncService;
use App\Services\Notifications\AccountCharacterNotificationService;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use App\Support\Auth\OAuthEmailVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

class XIVAuthController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly AccountCharacterNotificationService $accountCharacterNotificationService,
        private readonly XIVAuthCharacterSyncService $xivAuthCharacterSyncService,
        private readonly OAuthAccountLinkingPolicy $accountLinkingPolicy,
        private readonly SocialLoginLinkService $loginLinkService,
    ) {}

    public function redirect()
    {
        request()->session()->forget('character_onboarding.oauth');
        $response = $this->loginLinkService->rememberOAuthRedirect(request(), 'xivauth', Socialite::driver('xivauth')
            ->enablePKCE()
            ->withEmailScope()
            ->withCharactersScope()
            ->redirect());
        if (request()->boolean('onboarding') && auth()->check() && app()->environment('local')) {
            request()->session()->put('character_onboarding.oauth', [
                'user_id' => auth()->id(), 'state' => request()->session()->get('state'),
                'locale' => app()->getLocale(),
            ]);
        }

        return $response;
    }

    public function callback()
    {
        $onboarding = request()->session()->pull('character_onboarding.oauth');
        if ($onboarding !== null && (! is_array($onboarding)
            || ! auth()->check() || (int) ($onboarding['user_id'] ?? 0) !== (int) auth()->id()
            || ! is_string(request()->query('state')) || ! is_string($onboarding['state'] ?? null)
            || ! hash_equals($onboarding['state'], request()->query('state')))) {
            return redirect()->route('settings')->withErrors(['error' => 'social_oauth_invalid_state']);
        }
        $resumeUrl = is_array($onboarding) && app()->environment('local')
            ? route('characters.create', ['locale' => $onboarding['locale'], 'import' => 'xivauth']) : null;
        if ($resumeUrl && request()->has('error')) {
            return redirect($resumeUrl)->withErrors(['onboarding' => 'xivauth_cancelled']);
        }
        try {
            $xivauthUser = Socialite::driver('xivauth')
                ->enablePKCE()
                ->user();
        } catch (InvalidStateException) {
            return redirect($resumeUrl ?? route('settings'))
                ->withErrors(['error' => 'social_oauth_invalid_state']);
        } catch (\Throwable $exception) {
            if (! $resumeUrl) {
                throw $exception;
            }
            Log::warning('XIVAuth character onboarding authorization failed.', [
                'user_id' => auth()->id(), 'exception_type' => $exception::class,
            ]);

            return redirect($resumeUrl)->withErrors(['onboarding' => 'xivauth_unavailable']);
        }

        $provider = 'xivauth';
        $providerUserId = (string) $xivauthUser->getId();
        $providerEmail = Str::lower(trim((string) $xivauthUser->getEmail()));

        if (! OAuthEmailVerification::isVerified($xivauthUser, $provider)) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => __('auth.social_email_unverified')]);
        }

        $attributes = $xivauthUser->user ?? [];

        $socialAccount = SocialAccount::query()
            ->safeSummary()
            ->with('user')
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        if ($response = $this->loginLinkService->handleCallback(request(), $provider, $xivauthUser, $socialAccount)) {
            return $response;
        }

        try {
            $this->accountLinkingPolicy->authorize(auth()->user(), $socialAccount, $providerEmail);
        } catch (ValidationException $exception) {
            if ($resumeUrl) {
                return redirect($resumeUrl)->withErrors(['onboarding' => 'xivauth_link_conflict']);
            }

            return redirect()->route(auth()->check() ? 'settings' : 'login')->withErrors($exception->errors());
        }

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

            if ($resumeUrl) {
                return redirect($resumeUrl);
            }

            $syncResult = $this->xivAuthCharacterSyncService->syncMany(
                $socialAccount->user,
                $this->charactersFromProviderUser($xivauthUser),
            );

            return $this->redirectAfterLogin($syncResult);
        }

        $user = auth()->user();
        $createdUser = false;
        $linkingExistingSession = auth()->check();

        if (! $user) {
            $user = User::forceCreate([
                'name' => $xivauthUser->getName() ?: 'User-'.Str::random(6),
                'email' => $providerEmail,
                'email_verified_at' => now(),
                'avatar_url' => null,
                'password' => null,
            ]);

            $createdUser = true;
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

        if ($resumeUrl) {
            return redirect($resumeUrl);
        }

        $syncResult = $this->xivAuthCharacterSyncService->syncMany(
            $user,
            $this->charactersFromProviderUser($xivauthUser),
        );

        return $this->redirectAfterLogin($syncResult);
    }

    private function redirectAfterLogin(XIVAuthCharacterSyncResult $syncResult): RedirectResponse
    {
        $response = redirect()->intended(route('dashboard'));

        if (! $syncResult->hasConflicts()) {
            return $response;
        }

        return $response->with('flash_data', [
            'xivauth_character_sync' => [
                'conflicts' => $syncResult->conflicts,
            ],
        ]);
    }

    /**
     * @return array<int, mixed>
     */
    private function charactersFromProviderUser(object $xivauthUser): array
    {
        $characters = $xivauthUser->characters ?? null;

        if ($characters === null && method_exists($xivauthUser, 'getRaw')) {
            $characters = data_get($xivauthUser->getRaw(), 'characters', []);
        }

        return is_array($characters) ? $characters : [];
    }

    public static function getValidXivAuthAccessToken(SocialAccount $account): string
    {
        return app(XIVAuthAccessTokenService::class)->forAccount($account);
    }
}
