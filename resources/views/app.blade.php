<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ ($isRtl ?? false) ? 'rtl' : 'ltr' }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#FFFFFF" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#161B26" media="(prefers-color-scheme: dark)">
        <meta name="color-scheme" content="light dark">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="{{ config('app.name', 'SyncViet') }}">
        <meta name="format-detection" content="telephone=no">
        <link rel="manifest" href="/manifest.webmanifest">

        {{-- Inline script to detect saved/system theme preference before first paint --}}
        <script>
            (function() {
                var savedAppearance = null;
                try {
                    savedAppearance = window.localStorage.getItem('appearance');
                } catch (e) {}

                var appearance = savedAppearance || '{{ $appearance ?? "system" }}';
                var isDark = false;

                if (appearance === 'dark') {
                    isDark = true;
                } else if (appearance === 'light') {
                    isDark = false;
                } else {
                    isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                }

                document.documentElement.classList.toggle('dark', isDark);
                var themeMeta = document.querySelector('meta[name="theme-color"]:not([media])');
                if (!themeMeta) {
                    themeMeta = document.createElement('meta');
                    themeMeta.name = 'theme-color';
                    document.head.appendChild(themeMeta);
                }
                themeMeta.content = isDark ? '#161B26' : '#FFFFFF';
            })();
        </script>

        {{-- Inline style to set the HTML background color matching SyncViet light/dark tokens --}}
        <style>
            html {
                background-color: #f3f5f9;
            }

            html.dark {
                background-color: #0b0f17;
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ $seo['title'] ?? config('app.name', 'SyncViet') }}</title>
            @if (! empty($seo['noindex']))
                <meta name="robots" content="noindex, nofollow">
            @endif
            @if (! empty($seo['description']))
                <meta name="description" content="{{ $seo['description'] }}">
                <meta property="og:title" content="{{ $seo['title'] ?? config('app.name') }}">
                <meta property="og:description" content="{{ $seo['description'] }}">
                <meta property="og:type" content="{{ $seo['type'] ?? 'website' }}">
                <meta property="og:site_name" content="{{ config('app.name', 'SyncViet') }}">
                <meta name="twitter:card" content="summary_large_image">
                <meta name="twitter:title" content="{{ $seo['title'] ?? config('app.name') }}">
                <meta name="twitter:description" content="{{ $seo['description'] }}">
            @endif
            @if (! empty($seo['url']))
                <link rel="canonical" href="{{ $seo['url'] }}">
                <meta property="og:url" content="{{ $seo['url'] }}">
            @endif
            @if (! empty($seo['hreflangs']) && is_array($seo['hreflangs']))
                @foreach ($seo['hreflangs'] as $hlCode => $hlUrl)
                    <link rel="alternate" hreflang="{{ $hlCode }}" href="{{ $hlUrl }}">
                @endforeach
            @endif
            @if (! empty($seo['jsonLd']) && is_array($seo['jsonLd']))
                <script type="application/ld+json">{!! json_encode($seo['jsonLd'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
            @endif
        </x-inertia::head>
    </head>
    <body class="bg-background text-foreground font-sans antialiased selection:bg-primary/15 selection:text-primary">
        <x-inertia::app />
    </body>
</html>
