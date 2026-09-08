<?php

namespace App\Services\Auth;

use App\DTOs\VerifiedSocialIdentity;
use App\Models\PendingSocialLink;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Characters\XIVAuthCharacterSyncService;
use App\Services\Notifications\AccountCharacterNotificationService;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SocialLoginLinkService
{
    public function __construct(
        private readonly PendingSocialLinkStore $store,
        private readonly PasswordLoginService $passwordLogin,
        private readonly AuditLogger $auditLogger,
        private readonly AccountCharacterNotificationService $notifications,
        private readonly XIVAuthCharacterSyncService $characterSync,
    ) {}

    public function rememberOAuthRedirect(Request $request, string $provider, RedirectResponse $response): RedirectResponse
    {
        $request->session()->forget('social_link.oauth');
        $token = $request->query('link');
        if ($token !== null) {
            abort_unless(is_string($token), 404);
            $this->store->require($request, $token);
            $state = $request->session()->get('state');
            abort_unless(is_string($state) && $state !== '', 400);
            $request->session()->put('social_link.oauth', compact('token', 'provider', 'state'));
        }

        return $response;
    }

    public function handleCallback(Request $request, string $provider, object $providerUser, ?SocialAccount $account): ?RedirectResponse
    {
        $context = $request->session()->pull('social_link.oauth');
        if (is_array($context)) {
            $token = (string) ($context['token'] ?? '');
            $state = $request->query('state');
            if (($context['provider'] ?? null) !== $provider || ! is_string($state)
                || ! hash_equals((string) ($context['state'] ?? ''), $state)) {
                return redirect()->route('social-link.show', $token)
                    ->withErrors(['link' => __('auth.link_social.authentication_failed')]);
            }

            try {
                if (! $this->store->find($request, $token)) {
                    return redirect()->route('social-link.show', $token);
                }
                if (! $account) {
                    $this->failAuthentication();
                }

                return $this->authenticate($request, $token, (int) $account->user_id, [
                    'kind' => 'social', 'account_id' => $account->id,
                    'identity' => VerifiedSocialIdentity::fromProvider($provider, $providerUser)->toArray(),
                ]);
            } catch (ValidationException $exception) {
                return redirect()->route('social-link.show', $token)->withErrors($exception->errors());
            }
        }

        if ($request->user() || $account) {
            return null;
        }

        $identity = VerifiedSocialIdentity::fromProvider($provider, $providerUser);
        $existingUser = User::query()->whereRaw('LOWER(email) = ?', [$identity->attributes['provider_email']])->first();
        if (! $existingUser) {
            return null;
        }

        $token = $this->store->start($request, $existingUser, $identity);

        return redirect()->route('social-link.show', $token);
    }

    public function loginWithPassword(Request $request, string $token, string $login, string $password, bool $remember): RedirectResponse
    {
        $this->store->require($request, $token);
        $user = $this->passwordLogin->authenticate($login, $password);

        return $this->authenticate($request, $token, (int) $user->id, [
            'kind' => 'password', 'fingerprint' => hash('sha256', $user->password),
        ], $remember);
    }

    public function completeAfterVerification(Request $request, string $token): RedirectResponse
    {
        $pending = $this->store->require($request, $token);
        $proof = $pending->payload['proof'] ?? null;
        if (! $request->user() || ! is_array($proof)) {
            $this->failAuthentication();
        }

        return $this->authenticate($request, $token, (int) $request->user()->id, $proof);
    }

    private function authenticate(Request $request, string $token, int $userId, array $proof, bool $remember = false): RedirectResponse
    {
        try {
            [$user, $identity] = DB::transaction(function () use ($request, $token, $userId, $proof): array {
                $pending = $this->store->require($request, $token, lock: true);
                $user = User::query()->lockForUpdate()->findOrFail($pending->user_id);
                if ($userId !== (int) $user->id || ($request->user() && (int) $request->user()->id !== $userId)
                    || Str::lower($user->email) !== $pending->payload['email']) {
                    $this->failAuthentication();
                }
                $this->verifyProof($user, $proof);

                if (! $user->hasVerifiedEmail()) {
                    $pending->update(['payload' => array_merge($pending->payload, ['proof' => $proof])]);

                    return [$user, null];
                }

                return [$user, $this->link($pending, $user)];
            });
        } catch (UniqueConstraintViolationException) {
            // Another callback can claim the provider identity after our existence check.
            throw ValidationException::withMessages(['link' => __('auth.link_social.identity_taken')]);
        }

        Auth::login($user, $remember);
        $request->session()->regenerate();
        $this->auditLogger->log(
            action: 'user.logged_in', severity: AuditSeverity::INFO,
            scopeType: AuditScope::USER, scopeId: $user->id,
            message: 'audit_log.events.user.logged_in', actor: $user, subject: $user,
            metadata: ['login_method' => $proof['kind'], 'provider' => $proof['identity']['provider'] ?? null,
                'remember' => $remember],
        );

        if (! $identity) {
            return redirect()->route('verification.notice');
        }

        $this->store->cancel($request, $token);
        $this->notifications->notifySocialAccountLinked($user, $identity->provider, $user);
        $conflicts = [];
        $identities = [$identity];
        if ($proof['kind'] === 'social') {
            $identities[] = VerifiedSocialIdentity::fromArray($proof['identity']);
        }
        foreach ($identities as $authenticatedIdentity) {
            if ($authenticatedIdentity->provider === 'xivauth') {
                $conflicts = array_merge($conflicts, $this->characterSync->syncMany($user, $authenticatedIdentity->characters)->conflicts);
            }
        }

        // Verification middleware may remember this now-consumed handoff as the destination.
        if (in_array($request->session()->get('url.intended'), [
            route('social-link.show', $token), route('social-link.complete', $token),
        ], true)) {
            $request->session()->forget('url.intended');
        }
        $response = redirect()->intended(route('dashboard'));

        return $conflicts === [] ? $response : $response->with('flash_data', [
            'xivauth_character_sync' => ['conflicts' => $conflicts],
        ]);
    }

    private function verifyProof(User $user, array $proof): void
    {
        if ($proof['kind'] === 'password') {
            if (! $user->password || ! hash_equals(hash('sha256', $user->password), $proof['fingerprint'])) {
                $this->failAuthentication();
            }

            return;
        }

        $identity = VerifiedSocialIdentity::fromArray($proof['identity']);
        $account = SocialAccount::query()->whereKey($proof['account_id'])->where('user_id', $user->id)
            ->where('provider', $identity->provider)->where('provider_user_id', $identity->providerUserId)
            ->lockForUpdate()->first();
        if (! $account) {
            $this->failAuthentication();
        }
        $account->update($identity->attributes);
    }

    private function link(PendingSocialLink $pending, User $user): VerifiedSocialIdentity
    {
        $identity = VerifiedSocialIdentity::fromArray($pending->payload['identity']);
        if (SocialAccount::query()->where('provider', $identity->provider)->where('provider_user_id', $identity->providerUserId)->exists()) {
            throw ValidationException::withMessages(['link' => __('auth.link_social.identity_taken')]);
        }

        $user->socialAccounts()->create(array_merge($identity->attributes, [
            'provider' => $identity->provider, 'provider_user_id' => $identity->providerUserId,
        ]));
        $this->auditLogger->log(
            action: 'user.social_account.linked', severity: AuditSeverity::INFO,
            scopeType: AuditScope::USER, scopeId: $user->id,
            message: 'audit_log.events.user.social_account.linked', actor: $user, subject: $user,
            metadata: ['provider' => $identity->provider, 'provider_user_id' => $identity->providerUserId,
                'linked_while_authenticated' => true],
        );
        $pending->delete();

        return $identity;
    }

    private function failAuthentication(): never
    {
        throw ValidationException::withMessages(['login' => __('auth.link_social.authentication_failed')]);
    }
}
