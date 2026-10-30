<x-guest-layout>
    <div class="mb-4 text-sm text-cyan-200">
        {{ __('認証対象のメールアドレスには認証リンクを送信します。メールのリンクを開いて認証を完了し、その後ログインしてください。') }}
    </div>

    @if (session('status') == 'verification-link-requested')
        <div class="mb-4 rounded-xl border border-cyan-300 bg-cyan-50 px-4 py-3 text-sm font-bold text-slate-900">
            {{ __('認証が必要なアカウントであれば、メールを送信しました。すでに登録済みの場合はログインしてください。') }}
        </div>
    @elseif (session('status') == 'verification-link-sent')
        <div class="mb-4 font-medium text-sm text-green-600">
            {{ __('新しい認証リンクを送信しました。') }}
        </div>
    @elseif (session('status') == 'verification-link-send-failed')
        <div class="mb-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-bold text-black">
            {{ __('確認メールを送信できませんでした。メール認証が完了するまで登録は完了しません。時間をおいて再送信するか、管理者へお問い合わせください。') }}
        </div>
    @endif

    @if ($errors->has('email') || $errors->has('password'))
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-black">
            {{ $errors->first('email') ?: $errors->first('password') }}
        </div>
    @endif

    <div class="mt-4">
        @auth
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <x-primary-button>{{ __('認証メールを再送信') }}</x-primary-button>
            </form>

            <form method="POST" action="{{ route('logout') }}" class="mt-4">
                @csrf
                <button type="submit" class="underline text-sm text-cyan-200 hover:text-cyan-50 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                    {{ __('ログアウト') }}
                </button>
            </form>
        @else
            <form method="POST" action="{{ route('verification.send') }}" class="space-y-4">
                @csrf
                <div>
                    <x-input-label for="email" :value="__('メールアドレス')" />
                    <x-text-input id="email" class="mt-1 block w-full" type="email" name="email" :value="old('email')" required autocomplete="email" />
                </div>
                <div>
                    <x-input-label for="password" :value="__('パスワード')" />
                    <x-text-input id="password" class="mt-1 block w-full" type="password" name="password" required autocomplete="current-password" />
                </div>
                <x-primary-button>{{ __('認証メールを再送信') }}</x-primary-button>
            </form>
            <p class="mt-4 text-sm text-cyan-200">
                {{ __('再送には登録時のメールアドレスとパスワードが必要です。') }}
                <a class="underline" href="{{ route('login') }}">{{ __('ログイン') }}</a>
            </p>
        @endauth
    </div>
</x-guest-layout>
