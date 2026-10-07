<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApiDocumentationSecurityHeaders
{
    public function __construct(private readonly Vite $vite) {}

    public function handle(Request $request, Closure $next): Response
    {
        $developmentOrigin = $this->developmentOrigin();
        $assetSources = "'self'".($developmentOrigin ? ' '.$developmentOrigin : '');
        $connectSources = $assetSources;
        if ($developmentOrigin) {
            $connectSources .= ' '.preg_replace('/^http/', 'ws', $developmentOrigin);
        }

        $policy = [
            "default-src 'none'",
            "base-uri 'none'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "frame-src 'none'",
            "form-action 'none'",
            'script-src '.$assetSources,
            // Scalar positions popovers and highlights code using dynamic inline styles.
            "style-src {$assetSources} 'unsafe-inline'",
            "font-src {$assetSources} data:",
            "img-src 'self' data: blob:",
            'connect-src '.$connectSources,
            "worker-src 'self' blob:",
        ];

        $response = $next($request);
        $response->headers->set('Content-Security-Policy', implode('; ', $policy));

        return $response;
    }

    private function developmentOrigin(): ?string
    {
        // Never let an accidentally deployed hot file relax the production policy.
        if (! app()->environment('local') || ! $this->vite->isRunningHot()) {
            return null;
        }

        $parts = parse_url(trim(file_get_contents($this->vite->hotFile())));
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || ! preg_match('/^[a-zA-Z0-9.\[\]:-]+$/D', $parts['host'] ?? '')) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
