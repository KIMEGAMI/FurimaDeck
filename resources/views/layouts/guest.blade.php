<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">

        <meta name="viewport" content="width=device-width, initial-scale=1">

        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'FurimaDeck') }}</title>

        <x-pwa-head />
        <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}?v=3">
        <link rel="shortcut icon" type="image/x-icon" href="{{ asset('favicon.ico') }}?v=3">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>

    <body class="font-sans text-cyan-50 antialiased">
        <div
            class="fd-bg-guest flex min-h-screen flex-col items-center pt-6 text-white sm:justify-center sm:pt-0"
        >
            <div>
                <a href="/" class="text-3xl font-black tracking-[0.22em] text-white drop-shadow-2xl">
                    FurimaDeck
                </a>
            </div>

            <div class="mt-6 w-full overflow-hidden bg-slate-950/45 px-6 py-4 text-white shadow-2xl backdrop-blur-md sm:max-w-md sm:rounded-3xl border border-cyan-300/20">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
