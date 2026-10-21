@php
    $statusCode = trim($__env->yieldContent('code', '500'));
    $descriptionKey = in_array($statusCode, ['401', '402', '403', '404', '419', '429', '500', '503'], true)
        ? 'error_pages.descriptions.'.$statusCode
        : 'error_pages.descriptions.generic';
    $homeUrl = route('home', ['locale' => app()->getLocale()]);
    $discordUrl = config('services.project_links.discord');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="dark">
        <meta name="robots" content="noindex, nofollow">
        <title>@yield('title') · FullParty</title>

        {{-- Standalone Medas colours: error pages must work without the app's asset build or JavaScript. --}}
        <style>
            :root {
                color-scheme: only dark;
                --page-bg: oklch(0.182204 0.005625 285.862);
                --surface: oklch(0.214916 0.006 285.909);
                --border: oklch(0.329407 0.010136 285.898);
                --text: oklch(0.946105 0.002495 286.348);
                --muted: oklch(0.715474 0.015 286.067);
                --brand: oklch(0.423963 0.124218 305.772); /* Medas brand-700: identity */
                --brand-hover: oklch(0.347058 0.109402 307.253); /* Medas brand-800 */
                --brand-ink: var(--text);
                background: var(--page-bg);
                color: var(--text);
                font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                -webkit-font-smoothing: antialiased;
            }

            * { box-sizing: border-box; }
            body { display: flex; flex-direction: column; min-height: 100vh; min-height: 100svh; margin: 0; }
            a { color: inherit; text-decoration: none; }
            a:focus-visible { outline: 2px solid var(--brand); outline-offset: 5px; }

            .site-header { padding: 24px clamp(20px, 4vw, 48px); border-bottom: 1px solid var(--border); }
            .logo-link { display: inline-flex; border-radius: 6px; }
            .logo { display: block; width: 192px; max-width: 100%; height: auto; }

            main { display: grid; flex: 1; place-items: center; padding: 48px 20px 64px; }
            .error-content { width: 100%; max-width: 640px; text-align: center; }
            .status-code {
                margin: 0 0 24px;
                color: transparent;
                -webkit-text-stroke: 2px var(--brand);
                font-size: clamp(112px, 22vw, 224px);
                font-weight: 800;
                letter-spacing: -0.065em;
                line-height: 1;
            }
            h1 { margin: 0; font-size: clamp(26px, 4vw, 36px); font-weight: 650; line-height: 1.25; letter-spacing: -0.025em; overflow-wrap: anywhere; text-wrap: balance; }
            .description { max-width: 48ch; margin: 20px auto 0; color: var(--muted); font-size: 16px; line-height: 1.75; text-wrap: pretty; }
            .actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 12px; margin-top: 32px; }
            .button { display: inline-flex; min-height: 44px; align-items: center; justify-content: center; gap: 8px; padding: 11px 18px; border: 1px solid transparent; border-radius: 6px; font-size: 14px; font-weight: 600; line-height: 1.5; }
            .button-primary { background: var(--brand); color: var(--brand-ink); }
            .button-secondary { border-color: var(--border); background: var(--surface); }
            .button svg { flex-shrink: 0; width: 18px; height: 18px; }
            .site-footer { padding: 24px 20px; border-top: 1px solid var(--border); color: var(--muted); text-align: center; font-size: 13px; }

            @media (hover: hover) {
                .button-primary:hover { background: var(--brand-hover); }
                .button-secondary:hover { border-color: var(--brand); }
            }
            @media (max-width: 639px) {
                .site-header { text-align: center; }
                .actions { flex-direction: column; max-width: 320px; margin-inline: auto; }
            }
        </style>
    </head>
    <body>
        <header class="site-header">
            <a class="logo-link" href="{{ $homeUrl }}">
                <img class="logo" src="{{ asset('logos/full.png') }}" alt="FullParty.GG" width="1203" height="318">
            </a>
        </header>

        <main aria-labelledby="error-title">
            <div class="error-content">
                <p class="status-code">{{ $statusCode }}</p>
                <h1 id="error-title">@yield('message')</h1>
                <p class="description">{{ __($descriptionKey) }}</p>

                <nav class="actions" aria-label="{{ __('error_pages.actions') }}">
                    <a class="button button-primary" href="{{ $homeUrl }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1Z" />
                            <path d="M9 21v-8h6v8" />
                        </svg>
                        {{ __('error_pages.home') }}
                    </a>
                    @if ($discordUrl)
                        <a class="button button-secondary" href="{{ $discordUrl }}" target="_blank" rel="noopener noreferrer">
                            {{ __('error_pages.discord') }}
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M7 17 17 7M7 7h10v10" />
                            </svg>
                        </a>
                    @endif
                </nav>
            </div>
        </main>

        <footer class="site-footer">&copy; {{ date('Y') }} FullParty</footer>
    </body>
</html>
