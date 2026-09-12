<?php

namespace App\Http\Controllers;

use App\DTOs\VerifiedSocialIdentity;
use App\Http\Requests\LoginRequest;
use App\Services\Auth\PendingSocialLinkStore;
use App\Services\Auth\SocialLoginLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SocialAccountLinkController extends Controller
{
    public function __construct(
        private readonly PendingSocialLinkStore $store,
        private readonly SocialLoginLinkService $linking,
    ) {}

    public function show(Request $request, string $token): Response
    {
        $pending = $this->store->find($request, $token);
        $hasProof = $pending && isset($pending->payload['proof']) && (int) $request->user()?->id === (int) $pending->user_id;

        return Inertia::render('auth/LinkSocial', [
            'token' => $token,
            'provider' => $pending ? VerifiedSocialIdentity::PROVIDERS[$pending->payload['identity']['provider']] : null,
            'email' => $pending?->payload['email'],
            'expired' => $pending === null,
            'verificationRequired' => $hasProof && ! $request->user()->hasVerifiedEmail(),
            'canComplete' => $hasProof && $request->user()->hasVerifiedEmail(),
        ]);
    }

    public function login(LoginRequest $request, string $token): RedirectResponse
    {
        return $this->linking->loginWithPassword($request, $token, $request->validated('login'),
            $request->validated('password'), (bool) $request->validated('remember', false));
    }

    public function complete(Request $request, string $token): RedirectResponse
    {
        return $this->linking->completeAfterVerification($request, $token);
    }

    public function cancel(Request $request, string $token): RedirectResponse
    {
        $this->store->cancel($request, $token);

        return redirect()->route($request->user() ? 'dashboard' : 'login');
    }
}
