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

    <script nonce="{{ Vite::cspNonce() }}" src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        @media (max-width: 639px) {
        .furimadeck-app-shell {
            background-color: var(--color-fd-canvas);
        }

        .furimadeck-app-shell header {
            background-color: var(--color-fd-surface);
            border-bottom: 1px solid var(--color-fd-border);
            box-shadow: var(--shadow-sm);
        }

        .furimadeck-app-shell header .text-cyan-200 {
            color: var(--color-fd-ink);
        }

        .furimadeck-app-shell header .text-cyan-100 {
            color: var(--color-fd-muted);
        }

        .furimadeck-app-shell > header :is(.text-white, .text-slate-50, .text-slate-100, .text-slate-200) {
            color: var(--color-fd-ink) !important;
        }

        .furimadeck-app-shell > header :is(.text-slate-300, .text-slate-400) {
            color: var(--color-fd-muted) !important;
        }

        .furimadeck-app-shell main [style*="background-image"] {
            background-color: var(--color-fd-canvas) !important;
            background-image: none !important;
            background-attachment: scroll !important;
        }

        .furimadeck-app-shell main :is([class*="bg-slate-950"], [class*="bg-slate-900"], [class*="bg-black"], [class*="bg-cyan-950"], [class*="bg-emerald-950"], [class*="bg-rose-950"], [class*="bg-gray-900"]) {
            background-color: var(--color-fd-surface) !important;
            background-image: none !important;
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
        }

        .furimadeck-app-shell main :is([class*="bg-cyan-"][class*="/"], [class*="bg-sky-"][class*="/"], [class*="bg-blue-"][class*="/"]) {
            background-color: var(--color-fd-info-surface) !important;
        }

        .furimadeck-app-shell main :is([class*="bg-emerald-"][class*="/"], [class*="bg-green-"][class*="/"], [class*="bg-lime-"][class*="/"]) {
            background-color: var(--color-fd-success-surface) !important;
        }

        .furimadeck-app-shell main :is([class*="bg-amber-"][class*="/"], [class*="bg-yellow-"][class*="/"], [class*="bg-orange-"][class*="/"]) {
            background-color: var(--color-fd-warning-surface) !important;
        }

        .furimadeck-app-shell main :is([class*="bg-red-"][class*="/"], [class*="bg-rose-"][class*="/"]) {
            background-color: var(--color-fd-danger-surface) !important;
        }

        .furimadeck-app-shell main [class*="bg-violet-"][class*="/"] {
            background-color: var(--color-fd-violet-surface) !important;
        }

        .furimadeck-app-shell main :is([class*="bg-slate-"][class*="/"], [class*="bg-gray-"][class*="/"], [class*="bg-white/"]) {
            background-color: var(--color-fd-canvas) !important;
        }

        .furimadeck-app-shell main [class*="border-"][class*="/"] {
            border-color: var(--color-fd-border) !important;
        }

        .furimadeck-app-shell main :is(.text-white, .text-slate-50, .text-slate-100, .text-slate-200) {
            color: var(--color-fd-ink) !important;
        }

        .furimadeck-app-shell main :is(.text-slate-300, .text-slate-400) {
            color: var(--color-fd-muted) !important;
        }

        .furimadeck-app-shell main :is(.text-cyan-50, .text-cyan-100, .text-cyan-200, .text-cyan-300, .text-sky-50, .text-blue-50, .text-blue-400) {
            color: var(--color-fd-info) !important;
        }

        .furimadeck-app-shell main :is(.text-emerald-100, .text-emerald-200, .text-emerald-300, .text-green-100, .text-green-200, .text-lime-100, .text-lime-200, .text-lime-300) {
            color: var(--color-fd-success) !important;
        }

        .furimadeck-app-shell main :is(.text-amber-100, .text-amber-200, .text-amber-300, .text-yellow-100, .text-orange-100, .text-orange-200) {
            color: var(--color-fd-warning) !important;
        }

        .furimadeck-app-shell main :is(.text-red-200, .text-red-300, .text-red-400, .text-rose-100) {
            color: var(--color-fd-danger) !important;
        }

        .furimadeck-app-shell main :is(.text-violet-50, .text-violet-100, .text-violet-200) {
            color: var(--color-fd-violet) !important;
        }

        .furimadeck-app-shell main :is(a, button, input[type="submit"], [role="button"]):is([class~="bg-slate-700"], [class~="bg-slate-800"], [class~="bg-slate-900"], [class~="bg-gray-900"], [class~="bg-cyan-600"], [class~="bg-cyan-700"], [class~="bg-cyan-800"], [class~="bg-blue-600"], [class~="bg-blue-700"], [class~="bg-blue-800"], [class~="bg-emerald-600"], [class~="bg-emerald-700"], [class~="bg-emerald-800"], [class~="bg-green-600"], [class~="bg-indigo-600"], [class~="bg-red-600"], [class~="bg-red-700"], [class~="bg-red-800"], [class~="bg-amber-600"], [class~="bg-amber-700"], [class~="bg-sky-600"]) {
            color: var(--color-fd-surface) !important;
        }

        .furimadeck-app-shell main :is(a, button, input[type="submit"], [role="button"])[class~="bg-slate-900"] {
            background-color: var(--color-fd-primary) !important;
        }

        .furimadeck-app-shell main :is(a, button, input[type="submit"], [role="button"])[class~="bg-red-600"],
        .furimadeck-app-shell main :is(a, button, input[type="submit"], [role="button"])[class~="bg-red-700"],
        .furimadeck-app-shell main :is(a, button, input[type="submit"], [role="button"])[class~="bg-red-800"] {
            background-color: var(--color-fd-danger) !important;
        }

        .furimadeck-app-shell main :is(a, button, input[type="submit"], [role="button"]):is([class~="bg-cyan-600"], [class~="bg-cyan-700"], [class~="bg-cyan-800"], [class~="bg-blue-600"], [class~="bg-blue-700"], [class~="bg-blue-800"], [class~="bg-sky-600"]) {
            background-color: var(--color-fd-info) !important;
        }

        .furimadeck-app-shell main :is(a, button, input[type="submit"], [role="button"]):is([class~="bg-emerald-600"], [class~="bg-emerald-700"], [class~="bg-emerald-800"], [class~="bg-green-600"]) {
            background-color: var(--color-fd-accent) !important;
        }

        .furimadeck-app-shell main :where(.bg-white, .bg-slate-50, .bg-slate-100, .bg-gray-50, .bg-gray-100, .bg-blue-50, .bg-cyan-50, .bg-emerald-50, .bg-red-50, .bg-yellow-50, .bg-amber-50) {
            color: var(--color-fd-ink);
        }

        .furimadeck-app-shell main :where(input, textarea, select) {
            background-color: var(--color-fd-surface);
            color: var(--color-fd-ink);
        }

        .furimadeck-app-shell main :where(input::placeholder, textarea::placeholder) {
            color: var(--color-slate-500);
        }
        }
    </style>
</head>

<body class="font-sans antialiased">
    <div class="furimadeck-app-shell min-h-screen bg-slate-50 text-slate-900">
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
