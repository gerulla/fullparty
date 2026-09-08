<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PasswordLoginService
{
    public function authenticate(string $login, string $password): User
    {
        $column = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'name';
        $user = User::query()->whereRaw('LOWER('.$column.') = ?', [Str::lower($login)])->first();

        if (! $user || ! $user->password || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['login' => __('auth.failed')]);
        }

        return $user;
    }
}
