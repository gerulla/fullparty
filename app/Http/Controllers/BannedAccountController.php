<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BannedAccountController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->banned_at) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('auth/Banned', [
            'discordUrl' => config('services.project_links.discord'),
        ]);
    }
}
