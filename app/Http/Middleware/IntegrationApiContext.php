<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

final class IntegrationApiContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');
        $locale = app()->getLocale();
        app()->setLocale($request->getPreferredLanguage(['en', 'de', 'fr', 'ja']) ?? 'en');
        $previousUrls = URL::getFacadeRoot();
        URL::swap(clone $previousUrls);
        URL::setRequest($request);
        // Notifications and browser links must lead to the website, even on api.*.
        URL::forceRootUrl(config('app.url'));
        URL::defaults(['locale' => app()->getLocale()]);

        try {
            return $next($request);
        } finally {
            app()->setLocale($locale);
            URL::swap($previousUrls);
        }
    }
}
