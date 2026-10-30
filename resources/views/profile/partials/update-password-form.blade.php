<section>
    <form method="post" action="{{ route('password.update') }}" class="space-y-6">
        @csrf
        @method('put')

        <div>
            <label for="update_password_current_password" class="block text-sm font-bold text-slate-700">
                現在のパスワード
            </label>

            <input
                id="update_password_current_password"
                name="current_password"
                type="password"
                autocomplete="current-password"
                class="mt-3 block w-full rounded-xl border-slate-300 px-4 py-4 text-black placeholder:text-slate-500 shadow-sm focus:border-blue-500 focus:ring-blue-500"
            >

            @if ($errors->updatePassword->get('current_password'))
                <p class="mt-2 text-sm font-bold text-black">
                    {{ $errors->updatePassword->first('current_password') }}
                </p>
            @endif
        </div>

        <div>
            <label for="update_password_password" class="block text-sm font-bold text-slate-700">
                新しいパスワード
            </label>

            <input
                id="update_password_password"
                name="password"
                type="password"
                autocomplete="new-password"
                class="mt-3 block w-full rounded-xl border-slate-300 px-4 py-4 text-black placeholder:text-slate-500 shadow-sm focus:border-blue-500 focus:ring-blue-500"
            >

            @if ($errors->updatePassword->get('password'))
                <p class="mt-2 text-sm font-bold text-black">
                    {{ $errors->updatePassword->first('password') }}
                </p>
            @endif
        </div>

        <div>
            <label for="update_password_password_confirmation" class="block text-sm font-bold text-slate-700">
                新しいパスワード確認
            </label>

            <input
                id="update_password_password_confirmation"
                name="password_confirmation"
                type="password"
                autocomplete="new-password"
                class="mt-3 block w-full rounded-xl border-slate-300 px-4 py-4 text-black placeholder:text-slate-500 shadow-sm focus:border-blue-500 focus:ring-blue-500"
            >
        </div>

        <div class="flex items-center gap-4">
            <button
                type="submit"
                class="rounded-xl bg-blue-700 px-6 py-3 text-sm font-bold text-white shadow transition hover:bg-blue-800"
            >
                パスワードを変更する
            </button>

            @if (session('status') === 'password-updated')
                <p
                    data-auto-dismiss="2000"
                    class="text-sm font-bold text-blue-700"
                >
                    変更しました。
                </p>
            @endif
        </div>
    </form>
</section>
