<html>
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" href="/favicon.ico">
        @vite('resources/css/app.css')
        @vite('resources/js/app.js')
        @inertiaHead
        @routes
    </head>
    <body>
        <div class="isolate">
            @inertia
        </div>
    </body>
</html>
