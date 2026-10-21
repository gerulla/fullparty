@php
    $pageComponent = $page['component'] ?? '';
    $usesMedasTheme = str_starts_with($pageComponent, 'V2/')
        || (str_starts_with($pageComponent, 'auth/') && $pageComponent !== 'auth/Banned');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="dark" data-ui-theme="{{ $usesMedasTheme ? 'medas' : 'classic' }}">
    <head>
        @php
            $serverMeta = $serverMeta ?? app(\App\Support\Seo\ServerMeta::class)->defaults();
            $siteName = $serverMeta['site_name'] ?? config('app.name', 'FullParty');
            $pageTitle = $serverMeta['title'] ?? null;
            $fullTitle = filled($pageTitle) && $pageTitle !== $siteName
                ? $pageTitle.' - '.$siteName
                : $siteName;
            $description = $serverMeta['description'] ?? null;
            $canonicalUrl = $serverMeta['url'] ?? request()->fullUrl();
            $imageUrl = collect($serverMeta['images'] ?? [$serverMeta['image'] ?? null])
                ->filter()
                ->unique()
                ->first();
            $ogType = $serverMeta['type'] ?? 'website';
            $robots = $serverMeta['robots'] ?? 'index, follow';
            $manageResourceHead = request()->routeIs('public-resources.*');
        @endphp
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="fullparty-authenticated" content="{{ auth()->check() ? '1' : '0' }}">
        <meta name="color-scheme" content="dark light">
        <script>
            // Apply the saved mode before styles load; dark is the first-visit default.
            (() => {
                let appearance = 'dark';
                try {
                    if (localStorage.getItem('fullparty-appearance') === 'light') appearance = 'light';
                } catch {}
                const root = document.documentElement;
                root.classList.toggle('dark', appearance === 'dark');
                root.classList.toggle('light', appearance === 'light');
                root.style.colorScheme = appearance;
            })();
        </script>
        <style>
            html.page-ripple-arriving::after {
                content: '';
                position: fixed;
                inset: 0;
                z-index: 9998;
                pointer-events: none;
                background: var(--page-ripple-arrival-color);
            }
            @media (prefers-reduced-motion: reduce) {
                html.page-ripple-arriving::after { display: none; }
            }
        </style>
        <script>
            // Carry the curtain across same-site links that intentionally reload the document.
            (() => {
                try {
                    const saved = sessionStorage.getItem('fullparty-page-ripple');
                    sessionStorage.removeItem('fullparty-page-ripple');
                    if (!saved || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
                    const ripple = JSON.parse(saved);
                    if (ripple.href !== location.href || !Number.isFinite(ripple.at) || Date.now() - ripple.at > 10000
                        || Date.now() < ripple.at || ![ripple.x, ripple.y, ripple.seed].every(Number.isFinite)
                        || ripple.x < 0 || ripple.x > 1 || ripple.y < 0 || ripple.y > 1
                        || typeof ripple.color !== 'string' || !CSS.supports('color', ripple.color)) return;
                    const root = document.documentElement;
                    root.style.setProperty('--page-ripple-arrival-color', ripple.color);
                    root.dataset.pageRipple = saved;
                    root.classList.add('page-ripple-arriving');
                    setTimeout(() => {
                        root.classList.remove('page-ripple-arriving');
                        delete root.dataset.pageRipple;
                    }, 8000);
                } catch {}
            })();
        </script>
        @if($manageResourceHead)<title inertia="">@else<title>@endif{{ $fullTitle }}</title>
        @if (filled($description))
            <meta name="description" content="{{ $description }}"@if($manageResourceHead) inertia="description"@endif>
            <meta property="og:description" content="{{ $description }}"@if($manageResourceHead) inertia="og:description"@endif>
            <meta name="twitter:description" content="{{ $description }}"@if($manageResourceHead) inertia="twitter:description"@endif>
        @endif
        <meta name="robots" content="{{ $robots }}"@if($manageResourceHead) inertia="robots"@endif>
        <link rel="canonical" href="{{ $canonicalUrl }}"@if($manageResourceHead) inertia="canonical"@endif>
        <meta property="og:title" content="{{ $fullTitle }}"@if($manageResourceHead) inertia="og:title"@endif>
        <meta property="og:type" content="{{ $ogType }}"@if($manageResourceHead) inertia="og:type"@endif>
        <meta property="og:site_name" content="{{ $siteName }}"@if($manageResourceHead) inertia="og:site_name"@endif>
        <meta property="og:url" content="{{ $canonicalUrl }}"@if($manageResourceHead) inertia="og:url"@endif>
        @if (filled($imageUrl))
            <meta property="og:image" content="{{ $imageUrl }}"@if($manageResourceHead) inertia="og:image"@endif>
            <meta name="twitter:image" content="{{ $imageUrl }}"@if($manageResourceHead) inertia="twitter:image"@endif>
        @endif
        <meta name="twitter:card" content="{{ filled($imageUrl) ? 'summary_large_image' : 'summary' }}"@if($manageResourceHead) inertia="twitter:card"@endif>
        <meta name="twitter:title" content="{{ $fullTitle }}"@if($manageResourceHead) inertia="twitter:title"@endif>
        <link rel="icon" href="/favicon.ico">
        @vite('resources/css/app.css')
        @vite('resources/js/app.js')
        @inertiaHead
        @routes
    </head>
    <body class="bg-default text-default">
        <div class="isolate">
            @inertia
        </div>
    </body>
</html>
