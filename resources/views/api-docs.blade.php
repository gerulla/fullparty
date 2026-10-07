<!doctype html>
<html lang="{{ app()->getLocale() }}" class="dark-mode">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ __('integration_api.docs.description') }}">
    <meta name="referrer" content="same-origin">
    <title>FullParty · {{ __('integration_api.docs.title') }}</title>
    @vite('resources/api-docs/app.js')
</head>
<body>
    <header class="docs-header">
        <a class="docs-brand" href="{{ $websiteUrl }}">FULL<span>PARTY</span><span class="docs-label">{{ __('integration_api.docs.title') }}</span></a>
        <nav aria-label="{{ __('integration_api.docs.navigation') }}">
            <a href="/openapi.json" download="fullparty-openapi.json">{{ __('integration_api.docs.download') }} <span aria-hidden="true">↗</span></a>
            <a href="{{ $websiteUrl }}">{{ __('integration_api.docs.website') }}</a>
        </nav>
    </header>
    <div id="api-reference"></div>
    <noscript><p>{{ __('integration_api.docs.no_script') }} <a href="/openapi.json">OpenAPI JSON</a></p></noscript>
</body>
</html>
