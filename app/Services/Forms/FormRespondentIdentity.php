<?php

namespace App\Services\Forms;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

class FormRespondentIdentity
{
    public function key(Request $request): string
    {
        if ($request->user()) {
            return hash_hmac('sha256', 'account:'.$request->user()->id, config('app.key'));
        }
        $token = $request->cookie('fullparty_forms');
        if (! is_string($token) || ! preg_match('/^[A-Za-z0-9]{64}$/D', $token)) {
            $token = Str::random(64);
            Cookie::queue(cookie('fullparty_forms', $token, 60 * 24 * 365, '/', null, $request->isSecure(), true, false, 'lax'));
        }

        return hash_hmac('sha256', 'browser:'.$token, config('app.key'));
    }
}
