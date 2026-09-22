<x-app-layout>
    <x-slot name="header"><h2 class="text-2xl font-black text-cyan-200">FurimaDeckデータの全削除</h2></x-slot>

    <div class="min-h-screen bg-slate-100 py-8">
        <div class="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
            <section class="rounded border-2 border-red-300 bg-white p-6 shadow">
                <h3 class="text-xl font-black text-red-900">本当に削除しますか？</h3>
                <p class="mt-3 font-bold leading-7 text-slate-800">この操作は取り消せません。次のデータを、このアカウントについてすべて削除します。</p>
                <ul class="mt-4 list-disc space-y-1 pl-6 font-bold text-slate-800">
                    <li>商品、商品画像、仕入先</li>
                    <li>出品、販売、売上分析の元データ</li>
                    <li>CSV取込履歴、会計データ、AI利用履歴</li>
                    <li>操作履歴、旧オークション管理データ</li>
                </ul>
                <p class="mt-4 rounded bg-amber-50 p-4 font-bold text-amber-900">アカウント、ログイン情報、契約情報、共有マスターデータ、他ユーザーのデータは削除しません。</p>

                <form method="POST" action="{{ route('furimadeck-account.data-delete') }}" class="mt-6 space-y-4">
                    @csrf
                    @method('delete')
                    <div>
                        <label for="data-delete-password" class="block text-sm font-bold text-red-900">現在のパスワード</label>
                        <input id="data-delete-password" name="password" type="password" autocomplete="current-password" required class="mt-2 w-full rounded border-red-300 text-slate-900 shadow-sm">
                        @error('password', 'dataDeletion')<p class="mt-2 text-sm font-bold text-red-700">{{ $message }}</p>@enderror
                    </div>
                    <label class="flex items-start gap-2 text-sm font-bold text-red-900"><input name="confirm_data_deletion" value="1" type="checkbox" required class="mt-1">削除対象を確認し、データを全削除することに同意します。</label>
                    @error('confirm_data_deletion', 'dataDeletion')<p class="text-sm font-bold text-red-700">{{ $message }}</p>@enderror
                    <div class="flex flex-wrap gap-3">
                        <button type="submit" class="rounded bg-red-700 px-4 py-2 font-black text-white hover:bg-red-800">データを完全に削除する</button>
                        <a href="{{ route('furimadeck-account.edit') }}" class="rounded border border-slate-300 px-4 py-2 font-black text-slate-700 hover:bg-slate-50">キャンセル</a>
                    </div>
                </form>
            </section>
        </div>
    </div>
</x-app-layout>