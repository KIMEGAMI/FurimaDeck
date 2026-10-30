<section class="space-y-6">
    <p class="text-sm leading-7 text-slate-600">
        アカウントを削除すると、登録済みデータも含めて復元できません。
        通常利用ではこの操作は使用しないでください。
    </p>

    <button
        type="button"
        data-modal-open="confirm-user-deletion"
        class="rounded-xl bg-red-600 px-6 py-3 text-sm font-bold text-white shadow transition hover:bg-red-700"
    >
        アカウントを削除する
    </button>

    <x-modal name="confirm-user-deletion" :show="$errors->userDeletion->isNotEmpty()" focusable>
        <form method="post" action="{{ route('profile.destroy') }}" class="p-8">
            @csrf
            @method('delete')

            <h2 class="text-xl font-black text-red-700">
                本当にアカウントを削除しますか？
            </h2>

            <p class="mt-3 text-sm leading-7 text-slate-600">
                削除すると復元できません。確認のためパスワードを入力してください。
            </p>

            <div class="mt-6">
                <label for="password" class="sr-only">
                    パスワード
                </label>

                <input
                    id="password"
                    name="password"
                    type="password"
                    placeholder="パスワード"
                    class="block w-full rounded-xl border-slate-300 px-4 py-4 text-black placeholder:text-slate-500 shadow-sm focus:border-red-500 focus:ring-red-500"
                >

                @if ($errors->userDeletion->get('password'))
                    <p class="mt-2 text-sm font-bold text-black">
                        {{ $errors->userDeletion->first('password') }}
                    </p>
                @endif
            </div>

            <div class="mt-8 flex justify-end gap-3">
                <button
                    type="button"
                    data-modal-close
                    class="rounded-xl bg-slate-100 px-6 py-3 text-sm font-bold text-slate-700 transition hover:bg-slate-200"
                >
                    キャンセル
                </button>

                <button
                    type="submit"
                    class="rounded-xl bg-red-600 px-6 py-3 text-sm font-bold text-white transition hover:bg-red-700"
                >
                    削除する
                </button>
            </div>
        </form>
    </x-modal>
</section>
