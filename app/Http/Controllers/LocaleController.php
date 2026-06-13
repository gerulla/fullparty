<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ApplyLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse|Response
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(ApplyLocale::SUPPORTED_LOCALES)],
        ]);

        $request->session()->put('locale', $validated['locale']);
        Cookie::queue(cookie()->forever('locale', $validated['locale']));
        app()->setLocale($validated['locale']);

        if ($request->expectsJson()) {
            return response()->noContent();
        }

        return back();
    }
}
