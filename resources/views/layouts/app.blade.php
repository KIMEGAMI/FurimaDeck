<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'FurimaDeck') }}</title>

    <x-pwa-head />
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}?v=2">
    <link rel="shortcut icon" type="image/x-icon" href="{{ asset('favicon.ico') }}?v=2">

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        .furimadeck-app-shell {
            background-image:
                linear-gradient(rgba(2, 6, 23, 0.28), rgba(2, 6, 23, 0.52)),
                url('{{ asset('images/bg.png') }}');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }

        .furimadeck-app-shell main > .bg-slate-100,
        .furimadeck-app-shell main > .bg-gray-100,
        .furimadeck-app-shell main .bg-slate-100,
        .furimadeck-app-shell main .bg-gray-100 {
            background-color: transparent !important;
        }

        .furimadeck-app-shell header {
            background-color: rgba(0, 0, 0, 0.25);
            border-bottom: 1px solid rgba(255, 255, 255, 0.10);
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.28);
            backdrop-filter: blur(12px);
        }

        .furimadeck-app-shell main :where(.bg-white, .bg-slate-50, .bg-slate-100, .bg-gray-50, .bg-gray-100, .bg-blue-50, .bg-cyan-50, .bg-emerald-50, .bg-red-50, .bg-yellow-50, .bg-amber-50) {
            color: #0f172a;
        }

        .furimadeck-app-shell main :where(input, textarea, select) {
            background-color: #ffffff;
            color: #0f172a;
        }

        .furimadeck-app-shell main :where(input::placeholder, textarea::placeholder) {
            color: #64748b;
        }
    </style>
</head>

<body class="font-sans antialiased">
    <div class="furimadeck-app-shell min-h-screen bg-slate-950 text-white">
        @include('layouts.navigation')

        @isset($header)
            <header>
                <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                    {{ $header }}
                </div>
            </header>
        @endisset

        <main>
            {{ $slot }}
        </main>
    </div>
</body>
</html>
