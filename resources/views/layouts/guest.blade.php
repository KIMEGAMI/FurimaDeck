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

    <body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased">
        <div class="flex min-h-screen flex-col items-center justify-center px-4 py-8 sm:px-6">
            <div>
                <a href="/" class="text-2xl font-black tracking-tight text-slate-900">
                    FurimaDeck
                </a>
            </div>

            <div class="mt-6 w-full max-w-md overflow-hidden rounded-3xl border border-slate-200 bg-white px-5 py-6 text-slate-900 shadow-lg sm:px-8 sm:py-8">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
