<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="dark">
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
        <meta name="color-scheme" content="dark">
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
    <body class="dark bg-neutral-950 text-neutral-50">
        <div class="isolate">
            @inertia
        </div>
    </body>
</html>
