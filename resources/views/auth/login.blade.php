<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,follow">
    <title>ログイン | FurimaDeck</title>

    <x-pwa-head />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-slate-100">
    <main class="flex min-h-screen items-center justify-center bg-cover bg-center p-4 lg:bg-none" style="background-image: linear-gradient(rgba(240, 255, 253, 0.78), rgba(240, 255, 253, 0.9)), url('{{ asset('images/furimadeck-hero.png') }}');">
        <div class="grid min-h-[720px] w-full max-w-7xl grid-cols-1 overflow-hidden rounded-lg bg-white shadow-2xl lg:grid-cols-2">
            <section class="relative hidden bg-blue-900 lg:block">
                <img
                    src="{{ asset('images/furimadeck-hero.png') }}"
                    alt="FurimaDeck"
                    class="absolute inset-0 h-full w-full object-cover"
                >
                <div class="absolute inset-0 bg-gradient-to-r from-blue-950/35 to-transparent"></div>
                <div class="absolute left-8 top-8 text-white">
                    <div class="text-sm font-bold tracking-[0.18em]">FLEA MARKET PROFIT SaaS</div>
                    <div class="mt-4 h-1 w-24 rounded-full bg-white"></div>
                </div>
            </section>

            <section class="flex items-center justify-center px-6 py-10 lg:px-16">
                <div class="w-full max-w-xl">
                    <div class="mb-10 text-center">
                        <a href="{{ route('home') }}" class="mb-6 inline-flex text-3xl font-black tracking-[0.22em] text-cyan-700">
                            FurimaDeck
                        </a>

                        <h1 class="text-4xl font-black tracking-tight text-blue-800">FurimaDeck</h1>
                        <p class="mt-3 text-sm font-bold tracking-[0.18em] text-cyan-700">FLEA MARKET PROFIT SaaS</p>
                        <p class="mt-6 font-semibold text-slate-600">商品・販売・利益を、もっとスマートに。</p>
                        <div class="mx-auto mt-6 h-1 w-16 rounded-full bg-blue-600"></div>
                    </div>

                    <button
                        type="button"
                        data-pwa-install
                        hidden
                        disabled
                        class="mb-6 w-full rounded-md border border-cyan-300 bg-cyan-50 px-5 py-4 text-center text-sm font-black text-cyan-900 shadow-sm transition hover:bg-cyan-100 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        FurimaDeckをアプリとして追加
                    </button>

                    @if (session('status'))
                        <div class="mb-6 rounded-lg bg-blue-50 px-4 py-3 text-sm text-blue-700">
                            {{ session('status') }}
                        </div>
                    @endif

                    <form method="POST" action="{{ route('login') }}" class="rounded-lg border border-slate-200 bg-white p-8 shadow-xl">
                        @csrf

                        <div>
                            <label for="email" class="block text-sm font-bold text-slate-700">メールアドレス</label>
                            <input
                                id="email"
                                name="email"
                                type="email"
                                value="{{ old('email') }}"
                                required
                                autofocus
                                autocomplete="username"
                                placeholder="メールアドレスを入力してください"
                                class="mt-3 block w-full rounded-md border-slate-300 px-4 py-4 text-slate-800 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            >
                            @error('email')
                                <p class="mt-2 text-sm text-red-700">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="mt-6">
                            <label for="password" class="block text-sm font-bold text-slate-700">パスワード</label>
                            <input
                                id="password"
                                name="password"
                                type="password"
                                required
                                autocomplete="current-password"
                                placeholder="パスワードを入力してください"
                                class="mt-3 block w-full rounded-md border-slate-300 px-4 py-4 text-slate-800 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            >
                            @error('password')
                                <p class="mt-2 text-sm text-red-700">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="mt-6 flex items-center justify-between gap-4">
                            <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-600">
                                <input type="checkbox" name="remember" class="rounded border-slate-300 text-blue-600 shadow-sm focus:ring-blue-500">
                                ログイン状態を保存する
                            </label>

                            @if (Route::has('password.request'))
                                <a href="{{ route('password.request') }}" class="text-sm font-bold text-blue-700 hover:text-blue-900">
                                    パスワードを忘れた方
                                </a>
                            @endif
                        </div>

                        <button type="submit" class="mt-8 w-full rounded-md border border-cyan-300 bg-white px-6 py-4 text-center font-bold text-black shadow-lg transition hover:bg-cyan-50">
                            ログイン
                        </button>

                        <div class="mt-6 text-center">
                            <a href="{{ route('register') }}" class="text-sm font-bold text-cyan-700 hover:text-cyan-900">
                                アカウントを新規作成する
                            </a>
                        </div>
                    </form>

                    @if ((bool) config('demo.user_enabled'))
                        <form method="POST" action="{{ route('login.demo') }}" class="mt-6">
                            @csrf
                            <button type="submit" class="w-full rounded-md border border-cyan-300 bg-cyan-50 px-6 py-4 text-center font-bold text-cyan-700 shadow-sm transition hover:border-cyan-400 hover:bg-cyan-100">
                                デモを見る
                            </button>
                        </form>
                    @endif

                    <div class="mt-6">
                        <div class="relative">
                            <div class="absolute inset-0 flex items-center">
                                <div class="w-full border-t border-gray-300"></div>
                            </div>
                            <div class="relative flex justify-center text-sm">
                                <span class="bg-white px-2 text-black">または</span>
                            </div>
                        </div>

                        <a href="{{ route('google.redirect') }}" class="mt-4 flex w-full items-center justify-center gap-3 rounded-md border border-cyan-300 bg-white px-4 py-3 text-sm font-black text-black shadow-sm hover:bg-cyan-50">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-slate-100">
                                <img
                                    src="{{ asset('images/google-login-icon.png') }}"
                                    alt=""
                                    class="h-full w-full scale-[3.4] object-cover"
                                    loading="lazy"
                                >
                            </span>
                            <span>Googleでログイン</span>
                        </a>
                    </div>

                    <p class="mt-8 text-center text-xs text-slate-500">&copy; 2026 FurimaDeck All rights reserved.</p>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
