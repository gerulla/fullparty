<?php

namespace App\Services\Auth;

use App\DTOs\VerifiedSocialIdentity;
use App\Models\PendingSocialLink;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class PendingSocialLinkStore
{
    private const TOKEN_KEY = 'social_link.token';

    private const BROWSER_KEY = 'social_link.browser';

    public function start(Request $request, User $user, VerifiedSocialIdentity $identity): string
    {
        $binding = $request->session()->get(self::BROWSER_KEY, Str::random(64));
        $request->session()->put(self::BROWSER_KEY, $binding);
        PendingSocialLink::query()->where('binding_hash', hash('sha256', $binding))->delete();
        $token = Str::random(64);
        PendingSocialLink::create([
            'id' => hash('sha256', $token),
            'binding_hash' => hash('sha256', $binding),
            'user_id' => $user->id,
            'payload' => ['identity' => $identity->toArray(), 'email' => Str::lower($user->email)],
            'expires_at' => now()->addMinutes(10),
        ]);
        $request->session()->put(self::TOKEN_KEY, $token);

        return $token;
    }

    public function find(Request $request, string $token, bool $lock = false): ?PendingSocialLink
    {
        $current = $request->session()->get(self::TOKEN_KEY);
        $binding = $request->session()->get(self::BROWSER_KEY);
        if (! is_string($current) || ! is_string($binding) || ! hash_equals($current, $token)) {
            return null;
        }

        return PendingSocialLink::query()->whereKey(hash('sha256', $token))
            ->where('binding_hash', hash('sha256', $binding))->where('expires_at', '>', now())
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
    }

    public function require(Request $request, string $token, bool $lock = false): PendingSocialLink
    {
        return $this->find($request, $token, $lock) ?? abort(410, __('auth.link_social.expired'));
    }

    public function resumeUrl(Request $request): ?string
    {
        $token = $request->session()->get(self::TOKEN_KEY);

        return is_string($token) && $this->find($request, $token)
            ? route('social-link.show', ['token' => $token]) : null;
    }

    public function cancel(Request $request, string $token): void
    {
        $this->find($request, $token)?->delete();
        if ($request->session()->get(self::TOKEN_KEY) === $token) {
            $request->session()->forget(['social_link', 'state', 'code_verifier']);
        }
    }
}
