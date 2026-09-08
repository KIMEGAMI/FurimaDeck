<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @php
        $pageSeo = config('seo.pages.register');
        $siteName = config('seo.site_name', 'FurimaDeck');
        $image = asset(ltrim(config('seo.image', '/images/furimadeck-hero.png'), '/'));
    @endphp
    <title>{{ $pageSeo['title'] ?? '無料アカウント作成 | FurimaDeck' }}</title>
    <meta name="description" content="{{ $pageSeo['description'] ?? 'FurimaDeckの無料アカウント作成ページです。' }}">
    <meta name="robots" content="index,follow,max-image-preview:large">
    <link rel="canonical" href="{{ route('register') }}">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="{{ config('seo.locale', 'ja_JP') }}">
    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:title" content="{{ $pageSeo['title'] ?? '無料アカウント作成 | FurimaDeck' }}">
    <meta property="og:description" content="{{ $pageSeo['description'] ?? 'FurimaDeckの無料アカウント作成ページです。' }}">
    <meta property="og:url" content="{{ route('register') }}">
    <meta property="og:image" content="{{ $image }}">
    <meta name="twitter:card" content="{{ config('seo.twitter_card', 'summary_large_image') }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
</head>

<body class="min-h-screen bg-slate-100">
    <main class="flex min-h-screen items-center justify-center bg-cover bg-center p-4 lg:bg-none" style="background-image: linear-gradient(rgba(240, 255, 253, 0.78), rgba(240, 255, 253, 0.9)), url('{{ asset('images/furimadeck-hero.png') }}');">
        <div class="grid min-h-[720px] w-full max-w-7xl grid-cols-1 overflow-hidden rounded-[2rem] bg-white shadow-2xl lg:grid-cols-2">
            <section class="relative hidden bg-blue-900 lg:block">
                <img
                    src="{{ asset('images/furimadeck-hero.png') }}"
                    alt="FurimaDeck"
                    class="absolute inset-0 h-full w-full object-cover"
                >
                <div class="absolute inset-0 bg-gradient-to-r from-blue-950/40 to-transparent"></div>
                <div class="absolute left-8 top-8 text-white">
                    <div class="text-sm font-bold tracking-[0.18em]">FLEA MARKET PROFIT SaaS</div>
                    <div class="mt-4 h-1 w-24 rounded-full bg-white"></div>
                </div>
                <div class="absolute bottom-10 left-8 right-8 text-white">
                    <h2 class="text-4xl font-black leading-tight">
                        フリマ販売の収益を、<br>
                        もっとスマートに。
                    </h2>
                    <p class="mt-5 text-sm leading-7 text-blue-50">
                        画像、管理ID、商品タイトル、コメント、仕入れ値、売値、利益をまとめて管理できます。
                    </p>
                </div>
            </section>

            <section class="flex items-center justify-center px-6 py-10 lg:px-16">
                <div class="w-full max-w-xl">
                    <div class="mb-8 text-center">
                        <a href="{{ route('home') }}" class="mb-6 inline-flex text-3xl font-black tracking-[0.22em] text-cyan-700">
                            FurimaDeck
                        </a>

                        <h1 class="text-4xl font-black tracking-tight text-blue-800">アカウント作成</h1>
                        <p class="mt-3 text-sm font-bold tracking-[0.25em] text-cyan-700">CREATE ACCOUNT</p>
                        <p class="mt-6 font-semibold text-slate-700">FurimaDeckを無料で始めましょう。</p>
                        <div class="mx-auto mt-6 h-1 w-16 rounded-full bg-blue-600"></div>
                    </div>

                    <div class="mb-6">
                        <a href="{{ route('google.redirect') }}" class="flex w-full items-center justify-center gap-3 rounded-xl border border-cyan-300 bg-white px-4 py-4 text-sm font-black text-black shadow-lg transition hover:bg-cyan-50">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-slate-100">
                                <img
                                    src="{{ asset('images/google-login-icon.png') }}"
                                    alt=""
                                    class="h-full w-full scale-[3.4] object-cover"
                                    loading="lazy"
                                >
                            </span>
                            <span>Googleでアカウント作成</span>
                        </a>

                        <div class="relative mt-6">
                            <div class="absolute inset-0 flex items-center">
                                <div class="w-full border-t border-slate-200"></div>
                            </div>
                            <div class="relative flex justify-center text-sm">
                                <span class="bg-white px-3 font-bold text-slate-600">またはメールで登録</span>
                            </div>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('register') }}" class="rounded-3xl border border-slate-200 bg-white p-8 shadow-xl">
                        @csrf

                        <div>
                            <label for="name" class="block text-sm font-bold text-slate-700">ユーザー名</label>
                            <input
                                id="name"
                                name="name"
                                type="text"
                                value="{{ old('name') }}"
                                required
                                autofocus
                                autocomplete="name"
                                placeholder="ユーザー名を入力してください"
                                class="mt-3 block w-full rounded-xl border-slate-300 px-4 py-4 text-black shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            >
                            @error('name')
                                <p class="mt-2 text-sm text-black">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="mt-6">
                            <label for="email" class="block text-sm font-bold text-slate-700">メールアドレス</label>
                            <input
                                id="email"
                                name="email"
                                type="email"
                                value="{{ old('email') }}"
                                required
                                autocomplete="username"
                                placeholder="メールアドレスを入力してください"
                                class="mt-3 block w-full rounded-xl border-slate-300 px-4 py-4 text-black shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            >
                            @error('email')
                                <p class="mt-2 text-sm text-black">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="mt-6">
                            <label for="password" class="block text-sm font-bold text-slate-700">パスワード</label>
                            <input
                                id="password"
                                name="password"
                                type="password"
                                required
                                autocomplete="new-password"
                                placeholder="パスワードを入力してください"
                                class="mt-3 block w-full rounded-xl border-slate-300 px-4 py-4 text-black shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            >

                            <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                                <div id="password-strength-bar" class="h-full w-0 bg-blue-600 transition-all duration-300"></div>
                            </div>
                            <p id="password-strength-text" class="mt-2 text-xs font-bold text-slate-600">パスワード強度: 未入力</p>

                            @error('password')
                                <p class="mt-2 text-sm text-black">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="mt-6">
                            <label for="password_confirmation" class="block text-sm font-bold text-slate-700">パスワード確認</label>
                            <input
                                id="password_confirmation"
                                name="password_confirmation"
                                type="password"
                                required
                                autocomplete="new-password"
                                placeholder="もう一度パスワードを入力してください"
                                class="mt-3 block w-full rounded-xl border-slate-300 px-4 py-4 text-black shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            >
                            <p id="password-match-text" class="mt-2 text-xs font-bold text-slate-600">パスワード確認を入力してください</p>
                        </div>

                        <div class="mt-6 space-y-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <label for="terms_accepted" class="flex items-start gap-3 text-sm font-bold text-slate-900">
                                <input
                                    id="terms_accepted"
                                    name="terms_accepted"
                                    type="checkbox"
                                    value="1"
                                    required
                                    @checked(old('terms_accepted'))
                                    class="mt-1 rounded border-slate-400 text-blue-700 shadow-sm focus:ring-blue-600"
                                >
                                <span>
                                    <a href="{{ route('legal.terms') }}" target="_blank" rel="noopener noreferrer" class="text-blue-800 underline hover:text-blue-950">利用規約</a>に同意します
                                </span>
                            </label>
                            @error('terms_accepted')
                                <p class="text-sm font-bold text-black">利用規約への同意が必要です。</p>
                            @enderror

                            <label for="privacy_accepted" class="flex items-start gap-3 text-sm font-bold text-slate-900">
                                <input
                                    id="privacy_accepted"
                                    name="privacy_accepted"
                                    type="checkbox"
                                    value="1"
                                    required
                                    @checked(old('privacy_accepted'))
                                    class="mt-1 rounded border-slate-400 text-blue-700 shadow-sm focus:ring-blue-600"
                                >
                                <span>
                                    <a href="{{ route('legal.privacy') }}" target="_blank" rel="noopener noreferrer" class="text-blue-800 underline hover:text-blue-950">プライバシーポリシー</a>に同意します
                                </span>
                            </label>
                            @error('privacy_accepted')
                                <p class="text-sm font-bold text-black">プライバシーポリシーへの同意が必要です。</p>
                            @enderror
                        </div>

                        <button
                            type="submit"
                            class="mt-8 w-full rounded-xl border border-cyan-300 bg-white px-6 py-4 text-center font-bold text-black shadow-lg transition hover:bg-cyan-50"
                        >
                            新規登録
                        </button>

                        <div class="mt-6 text-center">
                            <a href="{{ route('login') }}" class="text-sm font-bold text-cyan-800 hover:text-cyan-950">
                                すでにアカウントをお持ちの方はこちら
                            </a>
                        </div>
                    </form>

                    <p class="mt-8 text-center text-xs text-slate-600">&copy; 2026 FurimaDeck All rights reserved.</p>
                </div>
            </section>
        </div>
    </main>

    <script>
        $(function () {
            const $password = $('#password')
            const $passwordConfirmation = $('#password_confirmation')
            const $strengthBar = $('#password-strength-bar')
            const $strengthText = $('#password-strength-text')
            const $matchText = $('#password-match-text')

            function updatePasswordStrength() {
                const value = $password.val()
                let score = 0

                if (value.length >= 8) {
                    score += 1
                }
                if (/[A-Z]/.test(value)) {
                    score += 1
                }
                if (/[0-9]/.test(value)) {
                    score += 1
                }
                if (/[^A-Za-z0-9]/.test(value)) {
                    score += 1
                }

                const width = value.length === 0 ? 0 : Math.max(25, score * 25)
                $strengthBar.css('width', width + '%')

                if (value.length === 0) {
                    $strengthBar.removeClass().addClass('h-full w-0 bg-blue-600 transition-all duration-300')
                    $strengthText.text('パスワード強度: 未入力').removeClass().addClass('mt-2 text-xs font-bold text-slate-600')
                    return
                }

                if (score <= 1) {
                    $strengthBar.removeClass().addClass('h-full bg-red-500 transition-all duration-300')
                    $strengthText.text('パスワード強度: 弱い').removeClass().addClass('mt-2 text-xs font-bold text-red-600')
                    return
                }

                if (score <= 3) {
                    $strengthBar.removeClass().addClass('h-full bg-blue-500 transition-all duration-300')
                    $strengthText.text('パスワード強度: 普通').removeClass().addClass('mt-2 text-xs font-bold text-blue-700')
                    return
                }

                $strengthBar.removeClass().addClass('h-full bg-blue-700 transition-all duration-300')
                $strengthText.text('パスワード強度: 強い').removeClass().addClass('mt-2 text-xs font-bold text-blue-800')
            }

            function updatePasswordMatch() {
                const password = $password.val()
                const confirmation = $passwordConfirmation.val()

                if (confirmation.length === 0) {
                    $matchText.text('パスワード確認を入力してください').removeClass().addClass('mt-2 text-xs font-bold text-slate-600')
                    return
                }

                if (password === confirmation) {
                    $matchText.text('パスワードが一致しています').removeClass().addClass('mt-2 text-xs font-bold text-blue-700')
                    return
                }

                $matchText.text('パスワードが一致していません').removeClass().addClass('mt-2 text-xs font-bold text-red-600')
            }

            $password.on('input', function () {
                updatePasswordStrength()
                updatePasswordMatch()
            })
            $passwordConfirmation.on('input', updatePasswordMatch)
        })
    </script>
</body>
</html>
