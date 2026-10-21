<?php

namespace App\Services\Characters;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class XIVAuthAccessTokenService
{
    public function forAccount(SocialAccount $account): string
    {
        if ($account->expires_at?->isFuture() && $account->access_token) {
            return $account->access_token;
        }
        if (! $account->refresh_token) {
            throw ValidationException::withMessages(['onboarding' => 'xivauth_reconnect']);
        }
        $response = Http::asForm()->connectTimeout(5)->timeout(20)->withoutRedirecting()
            ->post('https://xivauth.net/oauth/token', [
                'grant_type' => 'refresh_token',
                'client_id' => config('services.xivauth.client_id'),
                'client_secret' => config('services.xivauth.client_secret'),
                'scope' => 'user character:all refresh user:email',
                'refresh_token' => $account->refresh_token,
            ]);
        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw ValidationException::withMessages(['onboarding' => 'xivauth_reconnect']);
        }
        $account->update([
            'access_token' => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token') ?? $account->refresh_token,
            'expires_at' => $response->json('expires_in') !== null ? now()->addSeconds((int) $response->json('expires_in')) : null,
        ]);

        return $account->access_token;
    }
}
