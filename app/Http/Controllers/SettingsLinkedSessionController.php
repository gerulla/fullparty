<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingsLinkedSessionController extends Controller
{
    public function destroy(Request $request, string $token): RedirectResponse
    {
        $accessToken = $request->user()
            ->tokens()
            ->whereKey($token)
            ->where('revoked', false)
            ->firstOrFail();

        DB::transaction(function () use ($accessToken) {
            DB::table('oauth_refresh_tokens')
                ->where('access_token_id', $accessToken->id)
                ->update(['revoked' => true]);

            $accessToken->revoke();
        });

        return redirect()->back()->with('success', ['linked_session_revoked']);
    }
}
