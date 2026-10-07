<?php

namespace App\Http\Middleware;

use App\Models\DiscordUserIntegration;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateIntegrationMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $discordId = $request->header('X-FullParty-Discord-User-Id');
        abort_unless(is_string($discordId) && preg_match('/^\d{1,32}$/D', $discordId), 422, __('integration_api.actor_required'));

        $link = DiscordUserIntegration::query()->with('user')
            ->where('discord_user_id', $discordId)->whereNull('revoked_at')
            ->whereNotNull('user_app_installed_at')->first();
        $user = $link?->user;
        abort_unless($user, 404, __('integration_api.actor_unlinked'));
        abort_if($user->banned_at !== null, 403, __('reports.errors.banned'));
        abort_unless($user->hasVerifiedEmail(), 403, __('integration_api.email_unverified'));

        $previousGuard = Auth::getDefaultDriver();
        $previousResolver = $request->getUserResolver();
        $request->attributes->set('integration_member', $user);
        Auth::shouldUse('integration-member');
        Auth::guard('integration-member')->setUser($user);
        $request->setUserResolver(fn () => $user);

        try {
            $response = $next($request);
            $response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        } finally {
            $request->setUserResolver($previousResolver);
            $request->attributes->remove('integration_member');
            Auth::guard('integration-member')->forgetUser();
            Auth::shouldUse($previousGuard);
        }
    }
}
