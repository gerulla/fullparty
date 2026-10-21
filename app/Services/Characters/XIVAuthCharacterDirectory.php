<?php

namespace App\Services\Characters;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class XIVAuthCharacterDirectory
{
    public function __construct(private readonly XIVAuthAccessTokenService $tokens) {}

    /** @return array<int, array<string, mixed>> */
    public function forUser(User $user): array
    {
        $account = $user->socialAccounts()->where('provider', 'xivauth')->first();
        if (! $account) {
            throw ValidationException::withMessages(['onboarding' => 'xivauth_reconnect']);
        }

        try {
            $token = $this->tokens->forAccount($account);
            $response = Http::acceptJson()->withToken($token)->connectTimeout(5)->timeout(20)
                ->withoutRedirecting()->get('https://xivauth.net/api/v1/characters');
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['onboarding' => 'xivauth_unavailable']);
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw ValidationException::withMessages(['onboarding' => 'xivauth_reconnect']);
        }
        $data = $response->json();
        if (! $response->successful() || ! is_array($data) || ! array_is_list($data)) {
            throw ValidationException::withMessages(['onboarding' => 'xivauth_unavailable']);
        }

        // character:all exposes verified registrations; also check the explicit attestation.
        return collect($data)->filter(fn ($entry) => is_array($entry)
            && filled($entry['verified_at'] ?? null)
            && preg_match('/^[0-9]{1,20}$/', (string) ($entry['lodestone_id'] ?? ''))
            && filled($entry['name'] ?? null)
            && filled($entry['home_world'] ?? null)
            && in_array($entry['data_center'] ?? null, config('datacenters.values', []), true))
            ->map(fn (array $entry) => [
                'lodestone_id' => (string) $entry['lodestone_id'],
                'name' => $entry['name'],
                'world' => $entry['home_world'],
                'datacenter' => $entry['data_center'],
                'avatar_url' => $entry['avatar_url'] ?? null,
            ])->unique('lodestone_id')->values()->all();
    }
}
