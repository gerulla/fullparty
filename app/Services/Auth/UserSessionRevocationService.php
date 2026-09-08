<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class UserSessionRevocationService
{
    public function revokeAll(User $user): void
    {
        DB::transaction(function () use ($user): void {
            DB::table('sessions')->where('user_id', $user->id)->delete();

            // Include expired/revoked access tokens: their refresh tokens may still be live.
            DB::table('oauth_refresh_tokens')
                ->whereIn('access_token_id', DB::table('oauth_access_tokens')
                    ->where('user_id', $user->id)
                    ->select('id'))
                ->update(['revoked' => true]);

            $user->tokens()->update(['revoked' => true]);
            $user->forceFill(['remember_token' => Str::random(60)])->save();
        });
    }
}
