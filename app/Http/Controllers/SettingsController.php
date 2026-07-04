<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Passport\Token;

class SettingsController extends Controller
{
    /**
     * Display the user's settings page.
     *
     * @return Response
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->notification_preferences_reviewed_at === null) {
            $user->forceFill([
                'notification_preferences_reviewed_at' => now(),
            ])->save();

            $request->session()->flash('success', ['notification_preferences_reviewed']);
        }

        return Inertia::render('Dashboard/Settings/Index', [
            'activeLinkedSessions' => $this->activeLinkedSessions($user),
        ]);
    }

    private function activeLinkedSessions(User $user): array
    {
        $tokens = $user->tokens()
            ->with('client')
            ->where('revoked', false)
            ->latest()
            ->get();

        $refreshTokens = DB::table('oauth_refresh_tokens')
            ->whereIn('access_token_id', $tokens->pluck('id'))
            ->where('revoked', false)
            ->get()
            ->groupBy('access_token_id')
            ->map(fn ($items) => $items
                ->sortByDesc(fn ($refreshToken) => $refreshToken->expires_at ? Carbon::parse($refreshToken->expires_at)->timestamp : PHP_INT_MAX)
                ->first());

        return $tokens
            ->filter(function (Token $token) use ($refreshTokens) {
                $accessExpiresAt = $token->expires_at;
                $refreshToken = $refreshTokens->get($token->id);
                $refreshExpiresAt = $refreshToken?->expires_at
                    ? Carbon::parse($refreshToken->expires_at)
                    : null;

                return $accessExpiresAt === null
                    || $accessExpiresAt->isFuture()
                    || ($refreshToken !== null && ($refreshExpiresAt === null || $refreshExpiresAt->isFuture()));
            })
            ->map(function (Token $token) use ($refreshTokens) {
                $refreshExpiresAt = $refreshTokens->get($token->id)?->expires_at
                    ? Carbon::parse($refreshTokens->get($token->id)->expires_at)
                    : null;

                return [
                    'id' => $token->id,
                    'name' => $token->name,
                    'client_name' => $token->client?->name,
                    'scopes' => $token->scopes ?? [],
                    'created_at' => $token->created_at?->toIso8601String(),
                    'expires_at' => $token->expires_at?->toIso8601String(),
                    'refresh_expires_at' => $refreshExpiresAt?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }
}
