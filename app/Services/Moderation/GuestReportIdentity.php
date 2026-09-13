<?php

namespace App\Services\Moderation;

use Illuminate\Http\Request;

class GuestReportIdentity
{
    public function fingerprint(Request $request): string
    {
        // Use Laravel's trusted-proxy handling, never client-supplied forwarding headers directly.
        $address = inet_pton($request->ip() ?? '');
        abort_unless($address !== false, 400);
        if (strlen($address) === 16 && substr($address, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            $address = substr($address, 12);
        }
        // IPv6 privacy addresses on the same /64 share a limit, just like IPv4 users behind NAT.
        $network = strlen($address) === 16 ? 'v6:'.bin2hex(substr($address, 0, 8)) : 'v4:'.bin2hex($address);

        return hash_hmac('sha256', 'public-resource-report:v1:'.$network, config('app.key'));
    }
}
