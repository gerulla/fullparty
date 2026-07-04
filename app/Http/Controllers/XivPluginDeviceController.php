<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class XivPluginDeviceController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $userCode = $request->query('user_code');

        if (filled($userCode)) {
            return redirect()->route('xivplugin.device.authorize', [
                'user_code' => $userCode,
            ]);
        }

        return Inertia::render('auth/XivPlugin/DeviceCode', [
            'prefilledUserCode' => (string) ($request->old('user_code') ?: ''),
            'status' => $request->session()->get('status'),
        ]);
    }
}
