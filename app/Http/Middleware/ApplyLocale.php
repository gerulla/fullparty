<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class ApplyLocale
{
    public const SUPPORTED_LOCALES = ['en', 'de', 'fr', 'ja'];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('locale')
            ?? $request->cookie('locale')
            ?? config('app.locale');

        if (!in_array($locale, self::SUPPORTED_LOCALES, true)) {
            $locale = config('app.locale');
        }

        $request->session()->put('locale', $locale);
        App::setLocale($locale);

        return $next($request);
    }
}
