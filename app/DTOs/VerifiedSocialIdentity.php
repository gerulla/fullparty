<?php

namespace App\DTOs;

use App\Support\Auth\OAuthEmailVerification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class VerifiedSocialIdentity
{
    public const PROVIDERS = ['google' => 'Google', 'discord' => 'Discord', 'xivauth' => 'XIVAuth'];

    /** @param array<string, mixed> $attributes
     * @param  array<int, mixed>  $characters
     */
    public function __construct(
        public string $provider,
        public string $providerUserId,
        public array $attributes,
        public array $characters = [],
    ) {}

    public static function fromProvider(string $provider, object $identity): self
    {
        if (! isset(self::PROVIDERS[$provider]) || ! OAuthEmailVerification::isVerified($identity, $provider)
            || ! filled($identity->getId())) {
            throw ValidationException::withMessages(['link' => __('auth.social_email_unverified')]);
        }

        $raw = method_exists($identity, 'getRaw') ? $identity->getRaw() : [];
        $characters = $identity->characters ?? data_get($raw, 'characters', []);

        return new self($provider, (string) $identity->getId(), [
            'provider_name' => $identity->getName() ?: $identity->getNickname(),
            'provider_email' => Str::lower(trim((string) $identity->getEmail())),
            'avatar_url' => $provider === 'xivauth' ? null : $identity->getAvatar(),
            'access_token' => $identity->token ?? null,
            'refresh_token' => $identity->refreshToken ?? null,
            'expires_at' => isset($identity->expiresIn) ? now()->addSeconds((int) $identity->expiresIn)->toIso8601String() : null,
            'provider_data' => $provider === 'xivauth' ? ($identity->user ?? []) : [
                'name' => $identity->getName(), 'nickname' => $identity->getNickname(), 'avatar' => $identity->getAvatar(),
            ],
        ], is_array($characters) ? $characters : []);
    }

    public function toArray(): array
    {
        return ['provider' => $this->provider, 'provider_user_id' => $this->providerUserId,
            'attributes' => $this->attributes, 'characters' => $this->characters];
    }

    public static function fromArray(array $payload): self
    {
        return new self($payload['provider'], $payload['provider_user_id'], $payload['attributes'], $payload['characters'] ?? []);
    }
}
