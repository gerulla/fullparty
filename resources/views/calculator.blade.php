<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="dark">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="robots" content="noindex, nofollow">
        <title>Calculator - FullParty</title>
        <link rel="icon" href="/favicon.ico">
        @vite('resources/css/app.css')
        @vite('resources/calculator/app.js')
        @inertiaHead
    </head>
    <body class="dark bg-neutral-950 text-neutral-50">
        @inertia
    </body>
</html>
