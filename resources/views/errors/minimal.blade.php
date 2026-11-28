@php
    $assetVersion = (string) config('syncviet.branding.asset_version', 'sysnoxis-2');
    $brandName = (string) config('app.name', 'SysNoxis');
    $customFavicon = null;
    try {
        $branding = app(\App\Services\System\SystemSettingService::class)->brandingPayload();
        $customFavicon = $branding['faviconUrl'];
    } catch (\Throwable) {
        // Keep default brand assets if settings table is unavailable.
    }
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ (($isRtl ?? false) || app()->getLocale() === 'ar') ? 'rtl' : 'ltr' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title') — {{ $brandName }}</title>
        @if (! empty($customFavicon))
            <link rel="icon" href="{{ $customFavicon }}">
        @else
            <link rel="icon" href="/favicon.svg?v={{ $assetVersion }}" type="image/svg+xml">
            <link rel="icon" href="/favicon.ico?v={{ $assetVersion }}" sizes="any">
        @endif
        <script>
            (function () {
                var saved = null;
                try {
                    saved = window.localStorage.getItem('appearance');
                } catch (e) {}
                var mode = saved || 'system';
                var isDark =
                    mode === 'dark' ||
                    (mode !== 'light' &&
                        window.matchMedia('(prefers-color-scheme: dark)').matches);
                if (isDark) {
                    document.documentElement.classList.add('dark');
                } else {
                    document.documentElement.classList.remove('dark');
                }
            })();
        </script>
        <style>
            :root {
                color-scheme: light;
                --bg: #f3f5f9;
                --card: #ffffff;
                --text: #0f172a;
                --muted: #64748b;
                --border: #e2e8f0;
                --primary: #4f46e5;
                --primary-hover: #4338ca;
                --primary-fg: #ffffff;
                --primary-soft: #eef2ff;
                --accent-fg: #3730a3;
                --surface-hover: #f1f5f9;
            }
            html.dark {
                color-scheme: dark;
                --bg: #0b0f17;
                --card: #161b26;
                --text: #f1f5f9;
                --muted: #94a3b8;
                --border: #273244;
                --primary: #818cf8;
                --primary-hover: #a5b4fc;
                --primary-fg: #0b0f17;
                --primary-soft: #25264a;
                --accent-fg: #a5b4fc;
                --surface-hover: #1e293b;
            }
            * {
                box-sizing: border-box;
                margin: 0;
                padding: 0;
            }
            body {
                min-height: 100vh;
                background-color: var(--bg);
                color: var(--text);
                font-family:
                    'Be Vietnam Pro', ui-sans-serif, system-ui, -apple-system,
                    BlinkMacSystemFont, 'Segoe UI', sans-serif;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                padding: 1.5rem;
                line-height: 1.6;
                -webkit-font-smoothing: antialiased;
            }
            .card {
                width: 100%;
                max-width: 28rem;
                background-color: var(--card);
                border: 1px solid var(--border);
                border-radius: 1rem;
                padding: 2rem;
                text-align: center;
            }
            .brand-lockup {
                display: inline-flex;
                align-items: center;
                gap: 0.625rem;
                margin-bottom: 1.25rem;
                text-decoration: none;
                color: var(--text);
            }
            .brand-icon {
                width: 2.25rem;
                height: 2.25rem;
                border-radius: 0.5rem;
                background-color: var(--primary-soft);
                color: var(--primary);
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }
            .brand-name {
                font-size: 1rem;
                font-weight: 700;
                letter-spacing: -0.02em;
            }
            .code-badge {
                display: inline-flex;
                align-items: center;
                padding: 0.25rem 0.75rem;
                border-radius: 9999px;
                background-color: var(--primary-soft);
                color: var(--accent-fg);
                font-size: 0.75rem;
                font-weight: 600;
                letter-spacing: 0.04em;
                margin-bottom: 1rem;
            }
            h1 {
                font-size: 1.375rem;
                font-weight: 700;
                color: var(--text);
                letter-spacing: -0.015em;
            }
            p {
                margin-top: 0.625rem;
                font-size: 0.9375rem;
                color: var(--muted);
            }
            .actions {
                margin-top: 1.5rem;
                display: flex;
                flex-wrap: wrap;
                gap: 0.75rem;
                justify-content: center;
            }
            .btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                height: 2.375rem;
                padding: 0 1.125rem;
                border-radius: 0.625rem;
                font-size: 0.875rem;
                font-weight: 600;
                text-decoration: none;
                cursor: pointer;
                transition: background-color 0.15s ease, border-color 0.15s ease;
            }
            .btn-primary {
                background-color: var(--primary);
                color: var(--primary-fg);
                border: 1px solid transparent;
            }
            .btn-primary:hover {
                background-color: var(--primary-hover);
            }
            .btn-secondary {
                background-color: var(--card);
                color: var(--text);
                border: 1px solid var(--border);
            }
            .btn-secondary:hover {
                background-color: var(--surface-hover);
            }
            .brand {
                margin-top: 1.5rem;
                font-size: 0.75rem;
                color: var(--muted);
            }
        </style>
    </head>
    <body>
        <main class="card" role="main">
            <a href="{{ url('/') }}" class="brand-lockup">
                <span class="brand-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 40 40" fill="none">
                        <path d="M33 5H15A8 8 0 0 0 15 21H17.2L22.6 15.6H15A2.6 2.6 0 0 1 15 10.4H27.6Z" fill="currentColor" />
                        <path d="M7 35H25A8 8 0 0 0 25 19H22.8L17.4 24.4H25A2.6 2.6 0 0 1 25 29.6H12.4Z" fill="currentColor" />
                    </svg>
                </span>
                <span class="brand-name">{{ $brandName }}</span>
            </a>
            <div><div class="code-badge">@yield('code')</div></div>
            <h1>@yield('heading')</h1>
            <p>@yield('message')</p>
            <div class="actions">
                <a href="{{ url('/') }}" class="btn btn-primary">
                    {{ __('Back to home') }}
                </a>
                <button type="button" onclick="window.history.back()" class="btn btn-secondary">
                    {{ __('Go back') }}
                </button>
            </div>
        </main>
        <footer class="brand">
            &copy; {{ date('Y') }} {{ config('app.name', 'SysNoxis') }}
        </footer>
    </body>
</html>
