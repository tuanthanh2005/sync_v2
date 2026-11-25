<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#4F46E5">

        {{-- Inline script to detect saved/system theme preference before first paint --}}
        <script>
            (function() {
                var savedAppearance = null;
                try {
                    savedAppearance = window.localStorage.getItem('appearance');
                } catch (e) {}

                var appearance = savedAppearance || '{{ $appearance ?? "system" }}';

                if (appearance === 'dark') {
                    document.documentElement.classList.add('dark');
                } else if (appearance === 'light') {
                    document.documentElement.classList.remove('dark');
                } else {
                    var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    } else {
                        document.documentElement.classList.remove('dark');
                    }
                }
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
            @if (! empty($seo['description']))
                <meta name="description" content="{{ $seo['description'] }}">
                <meta property="og:title" content="{{ $seo['title'] ?? config('app.name') }}">
                <meta property="og:description" content="{{ $seo['description'] }}">
            @endif
            @if (! empty($seo['url']))
                <link rel="canonical" href="{{ $seo['url'] }}">
                <meta property="og:url" content="{{ $seo['url'] }}">
            @endif
        </x-inertia::head>
    </head>
    <body class="bg-background text-foreground font-sans antialiased selection:bg-primary/15 selection:text-primary">
        <x-inertia::app />
    </body>
</html>
