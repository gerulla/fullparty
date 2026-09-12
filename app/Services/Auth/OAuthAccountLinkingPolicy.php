<?php

namespace App\Services\Auth;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class OAuthAccountLinkingPolicy
{
    public function authorize(?User $authenticatedUser, ?SocialAccount $account, string $providerEmail): void
    {
        if ($account !== null) {
            if ($authenticatedUser !== null && (int) $account->user_id !== (int) $authenticatedUser->id) {
                $this->deny('social_account_already_linked');
            }

            return;
        }

        if ($authenticatedUser !== null) {
            if (! $authenticatedUser->hasVerifiedEmail()) {
                $this->deny('social_link_requires_verified_account');
            }

            return;
        }

        // A matching mailbox must not promote an untrusted pre-registration.
        if (User::query()->whereRaw('LOWER(email) = ?', [$providerEmail])->exists()) {
            $this->deny('social_link_requires_login');
        }
    }

    private function deny(string $key): never
    {
        throw ValidationException::withMessages(['email' => __('auth.'.$key)]);
    }
}
