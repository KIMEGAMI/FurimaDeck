@php
    $title = $title ?? 'サービスは一時的に利用できません';
    $message = $message ?? '現在メンテナンス中です。作業完了後、通常どおりログインしてご利用いただけます。';
@endphp

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#F8FAFC">
    <title>{{ $title }} | FurimaDeck</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
    <main class="flex min-h-screen items-center justify-center bg-slate-50 px-4 py-8 sm:px-6 sm:py-10">
        <section class="w-full max-w-2xl rounded-3xl border border-slate-200 bg-white p-5 text-center shadow-lg sm:p-8">
            <div class="text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">FurimaDeck</div>

            <p class="mt-8 text-xs font-black tracking-[0.2em] text-emerald-800 sm:text-sm">MAINTENANCE</p>
            <h1 class="mt-4 text-2xl font-black leading-tight text-slate-950 sm:text-3xl md:text-4xl">{{ $title }}</h1>
            <p class="mx-auto mt-5 max-w-xl text-base font-medium leading-7 text-slate-700">
                {{ $message }}
            </p>

            <div class="mt-7 flex justify-center">
                <form method="POST" action="{{ route('maintenance.login') }}">
                    @csrf
                    <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-slate-900 px-5 py-3 text-sm font-bold text-white transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-700 focus:ring-offset-2">
                        管理者ログインへ
                    </button>
                </form>
            </div>

            <div class="mt-8 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-left sm:p-5">
                <p class="text-sm font-black text-slate-900">ご利用中のお客様へ</p>
                <p class="mt-2 text-sm font-medium leading-6 text-slate-700">
                    登録済みの商品データと画像は保持されます。作業完了後、通常どおりログインしてご利用いただけます。
                </p>
            </div>
        </section>
    </main>
</body>
</html>
