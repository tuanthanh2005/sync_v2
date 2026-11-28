@php
    $ga4 = ['enabled' => false, 'measurement_id' => null, 'require_consent' => true];
    $googleMetaToken = null;
    $bingMetaToken = '';
    try {
        $seoConfig = app(\App\Services\Seo\SeoConfigService::class);
        $googleVerify = app(\App\Services\Seo\GoogleSiteVerificationService::class);
        $ga4 = $seoConfig->ga4PublicConfig();
        $googleMetaToken = $googleVerify->activeMetaToken();
        $bingMetaToken = trim($seoConfig->string('bing_meta_verification', ''));
    } catch (\Throwable) {
        // Never break rendering if SEO tables are not yet migrated during deployment.
    }
    $seoData = is_array($seo ?? null) ? $seo : [];
    $canonicalUrl = $seoData['canonical'] ?? $seoData['url'] ?? null;
    $robotsDirective = $seoData['robots'] ?? (! empty($seoData['noindex']) ? 'noindex, nofollow' : null);
    $hreflangList = [];
    if (! empty($seoData['hreflang']) && is_array($seoData['hreflang'])) {
        $hreflangList = $seoData['hreflang'];
    } elseif (! empty($seoData['hreflangs']) && is_array($seoData['hreflangs'])) {
        foreach ($seoData['hreflangs'] as $hlCode => $hlUrl) {
            $hreflangList[] = ['hreflang' => $hlCode, 'href' => $hlUrl];
        }
    }
    $schemaGraphs = [];
    if (! empty($seoData['schema']) && is_array($seoData['schema'])) {
        $schemaGraphs = array_is_list($seoData['schema']) ? $seoData['schema'] : [$seoData['schema']];
    } elseif (! empty($seoData['jsonLd']) && is_array($seoData['jsonLd'])) {
        $schemaGraphs = [$seoData['jsonLd']];
    }
@endphp
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
        <meta name="apple-mobile-web-app-title" content="{{ config('app.name', 'SysNoxis') }}">
        <meta name="format-detection" content="telephone=no">
        <link rel="manifest" href="/manifest.webmanifest">

        @if (! empty($googleMetaToken))
            <meta name="google-site-verification" content="{{ $googleMetaToken }}">
        @endif
        @if ($bingMetaToken !== '')
            <meta name="msvalidate.01" content="{{ $bingMetaToken }}">
        @endif

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

        {{-- Inline style to set the HTML background color matching SysNoxis light/dark tokens --}}
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
            <title>{{ $seoData['title'] ?? config('app.name', 'SysNoxis') }}</title>
            @if (! empty($robotsDirective))
                <meta name="robots" content="{{ $robotsDirective }}">
            @endif
            @if (! empty($seoData['description']))
                <meta name="description" content="{{ $seoData['description'] }}">
                <meta property="og:title" content="{{ $seoData['og_title'] ?? $seoData['title'] ?? config('app.name') }}">
                <meta property="og:description" content="{{ $seoData['og_description'] ?? $seoData['description'] }}">
                <meta property="og:type" content="{{ $seoData['og_type'] ?? $seoData['type'] ?? 'website' }}">
                <meta property="og:site_name" content="{{ config('app.name', 'SysNoxis') }}">
                @if (! empty($seoData['og_locale']))
                    <meta property="og:locale" content="{{ $seoData['og_locale'] }}">
                @endif
                @if (! empty($seoData['og_image']))
                    <meta property="og:image" content="{{ $seoData['og_image'] }}">
                    <meta name="twitter:image" content="{{ $seoData['og_image'] }}">
                @endif
                <meta name="twitter:card" content="{{ $seoData['twitter_card'] ?? 'summary_large_image' }}">
                <meta name="twitter:title" content="{{ $seoData['og_title'] ?? $seoData['title'] ?? config('app.name') }}">
                <meta name="twitter:description" content="{{ $seoData['og_description'] ?? $seoData['description'] }}">
            @endif
            @if (! empty($seoData['published_time']))
                <meta property="article:published_time" content="{{ $seoData['published_time'] }}">
            @endif
            @if (! empty($seoData['modified_time']))
                <meta property="article:modified_time" content="{{ $seoData['modified_time'] }}">
            @endif
            @if (! empty($canonicalUrl))
                <link rel="canonical" href="{{ $canonicalUrl }}">
                <meta property="og:url" content="{{ $canonicalUrl }}">
            @endif
            @foreach ($hreflangList as $hl)
                @if (! empty($hl['hreflang']) && ! empty($hl['href']))
                    <link rel="alternate" hreflang="{{ $hl['hreflang'] }}" href="{{ $hl['href'] }}">
                @endif
            @endforeach
            @foreach ($schemaGraphs as $graph)
                <script type="application/ld+json">{!! json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
            @endforeach
        </x-inertia::head>

        @if ($ga4['enabled'] && ! empty($ga4['measurement_id']) && ! request()->is('admin*'))
            <script async src="https://www.googletagmanager.com/gtag/js?id={{ $ga4['measurement_id'] }}"></script>
            <script>
                window.dataLayer = window.dataLayer || [];
                function gtag(){dataLayer.push(arguments);}
                window.__SYNCVIET_GA4_ID = @json($ga4['measurement_id']);
                gtag('js', new Date());
                gtag('config', @json($ga4['measurement_id']), { send_page_view: false, anonymize_ip: true });
            </script>
        @endif
    </head>
    <body class="bg-background text-foreground font-sans antialiased selection:bg-primary/15 selection:text-primary">
        <x-inertia::app />
        @include('partials.seo-ssr')
    </body>
</html>
