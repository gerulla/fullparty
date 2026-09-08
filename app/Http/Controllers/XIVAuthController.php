<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Auth\OAuthAccountLinkingPolicy;
use App\Services\Characters\XIVAuthCharacterSyncResult;
use App\Services\Characters\XIVAuthCharacterSyncService;
use App\Services\Notifications\AccountCharacterNotificationService;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use App\Support\Auth\OAuthEmailVerification;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
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
    ) {}

    public function redirect()
    {
        return Socialite::driver('xivauth')
            ->enablePKCE()
            ->withEmailScope()
            ->withCharactersScope()
            ->redirect();
    }

    public function callback()
    {
        try {
            $xivauthUser = Socialite::driver('xivauth')
                ->enablePKCE()
                ->user();
        } catch (InvalidStateException) {
            return redirect()
                ->route('settings')
                ->withErrors(['error' => 'social_oauth_invalid_state']);
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

        try {
            $this->accountLinkingPolicy->authorize(auth()->user(), $socialAccount, $providerEmail);
        } catch (ValidationException $exception) {
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
        if ($account->expires_at && $account->expires_at->isFuture() && $account->access_token) {
            return $account->access_token;
        }

        $response = Http::asForm()->post('https://xivauth.net/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => config('services.xivauth.client_id'),
            'client_secret' => config('services.xivauth.client_secret'),
            'scope' => 'user character:all refresh user:email',
            'refresh_token' => $account->refresh_token,
        ]);

        if (! $response->successful()) {
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
