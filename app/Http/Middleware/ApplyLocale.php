<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class ApplyLocale
{
    public const SUPPORTED_LOCALES = ['en', 'de', 'fr', 'ja'];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $routeLocale = $request->route('locale');
        $routeLocale = is_string($routeLocale) && in_array($routeLocale, self::SUPPORTED_LOCALES, true)
            ? $routeLocale
            : null;

        $preferredLocale = $this->preferredLocale($request);
        $locale = $preferredLocale
            ?? $routeLocale
            ?? config('app.locale');

        if (! in_array($locale, self::SUPPORTED_LOCALES, true)) {
            $locale = config('app.locale');
        }

        if ($preferredLocale !== null) {
            $request->session()->put('locale', $preferredLocale);
            Cookie::queue(cookie()->forever('locale', $preferredLocale));
        }

        App::setLocale($locale);
        URL::defaults(['locale' => $locale]);

        if (
            ($request->isMethod('GET') || $request->isMethod('HEAD'))
            && $request->route() !== null
            && in_array('locale', $request->route()->parameterNames(), true)
            && (
                ! $this->hasLocalizedRoutePrefix($request)
                || ($preferredLocale !== null && $routeLocale !== $preferredLocale)
            )
            && ! $request->hasValidSignature(false)
        ) {
            $routeName = $request->route()?->getName();

            if ($routeName !== null) {
                $parameters = $request->route()->parameters();
                $parameters['locale'] = $locale;

                if ($request->query->count() > 0) {
                    $parameters['_query'] = $request->query();
                }

                return redirect()->to(route($routeName, $parameters));
            }
        }

        $request->route()?->forgetParameter('locale');

        return $next($request);
    }

    private function preferredLocale(Request $request): ?string
    {
        $sessionLocale = $request->session()->get('locale');

        if (is_string($sessionLocale) && in_array($sessionLocale, self::SUPPORTED_LOCALES, true)) {
            return $sessionLocale;
        }

        $cookieLocale = $request->cookie('locale');

        if (is_string($cookieLocale) && in_array($cookieLocale, self::SUPPORTED_LOCALES, true)) {
            return $cookieLocale;
        }

        return null;
    }

    private function hasLocalizedRoutePrefix(Request $request): bool
    {
        $firstSegment = $request->segment(1);

        return is_string($firstSegment) && in_array($firstSegment, self::SUPPORTED_LOCALES, true);
    }
}
