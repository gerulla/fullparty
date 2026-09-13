<?php

namespace App\Services\Moderation;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountBanService
{
    public function setBanned(User $user, User $admin, bool $banned): void
    {
        abort_if($user->is_admin || $user->id === $admin->id, 422, __('reports.errors.protected_account'));
        $user->forceFill(['banned_at' => $banned ? now() : null])->save();
        if ($banned) {
            $tokens = DB::table('oauth_access_tokens')->where('user_id', $user->id)->pluck('id');
            DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $tokens)->update(['revoked' => true]);
            DB::table('oauth_access_tokens')->whereIn('id', $tokens)->update(['revoked' => true]);
            $user->forceFill(['remember_token' => Str::random(60)])->save();
        }
    }
}
