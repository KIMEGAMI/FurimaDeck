<section>
    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="space-y-6">
        @csrf
        @method('patch')

        <div>
            <label for="name" class="block text-sm font-bold text-slate-700">
                ユーザー名
            </label>

            <input
                id="name"
                name="name"
                type="text"
                value="{{ old('name', $user->name) }}"
                required
                autofocus
                autocomplete="name"
                class="mt-3 block w-full rounded-xl border-slate-300 px-4 py-4 text-black placeholder:text-slate-500 shadow-sm focus:border-blue-500 focus:ring-blue-500"
            >

            @error('name')
                <p class="mt-2 text-sm font-bold text-black">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="email" class="block text-sm font-bold text-slate-700">
                メールアドレス
            </label>

            <input
                id="email"
                name="email"
                type="email"
                value="{{ old('email', $user->email) }}"
                required
                autocomplete="username"
                class="mt-3 block w-full rounded-xl border-slate-300 px-4 py-4 text-black placeholder:text-slate-500 shadow-sm focus:border-blue-500 focus:ring-blue-500"
            >

            @error('email')
                <p class="mt-2 text-sm font-bold text-black">{{ $message }}</p>
            @enderror

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div class="mt-4 rounded-2xl border border-yellow-200 bg-yellow-50 p-4">
                    <p class="text-sm font-semibold text-yellow-800">
                        メールアドレスが未確認です。
                    </p>

                    <button
                        form="send-verification"
                        class="mt-2 text-sm font-bold text-blue-700 hover:text-blue-900"
                    >
                        確認メールを再送信する
                    </button>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 text-sm font-bold text-green-600">
                            確認メールを送信しました。
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <div class="flex items-center gap-4">
            <button
                type="submit"
                class="rounded-xl bg-blue-700 px-6 py-3 text-sm font-bold text-white shadow transition hover:bg-blue-800"
            >
                保存する
            </button>

            @if (session('status') === 'profile-updated')
                <p
                    data-auto-dismiss="2000"
                    class="text-sm font-bold text-blue-700"
                >
                    保存しました。
                </p>
            @endif
        </div>
    </form>
</section>
