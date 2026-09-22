<x-app-layout>
    <x-slot name="header"><h2 class="text-2xl font-black text-cyan-200">期間（月）のデータ削除</h2></x-slot>

    <div class="min-h-screen bg-slate-100 py-8">
        <div class="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
            <section class="rounded border-2 border-red-300 bg-white p-6 shadow">
                <h3 class="text-xl font-black text-red-900">{{ $month }} の売上関連データを削除します</h3>
                <p class="mt-3 font-bold leading-7 text-slate-800">この操作は取り消せません。指定月の販売データ、紐づく会計データ、操作履歴を削除します。</p>
                <p class="mt-4 rounded bg-emerald-50 p-4 font-bold text-emerald-900">商品、商品画像、出品情報、仕入先、アカウント、他の月のデータは削除しません。</p>

                <form method="GET" action="{{ route('furimadeck-account.monthly-data-delete.confirm') }}" class="mt-6 flex flex-wrap items-end gap-3">
                    <label class="block text-sm font-bold text-slate-800">削除する月<input name="month" type="month" value="{{ $month }}" required class="mt-2 block rounded border-slate-300 text-slate-900 shadow-sm"></label>
                    <button class="rounded border border-slate-400 px-4 py-2 font-black text-slate-700 hover:bg-slate-50">月を変更</button>
                </form>

                <form method="POST" action="{{ route('furimadeck-account.monthly-data-delete') }}" class="mt-6 space-y-4">
                    @csrf
                    @method('delete')
                    <input type="hidden" name="month" value="{{ $month }}">
                    <div>
                        <label for="monthly-delete-password" class="block text-sm font-bold text-red-900">現在のパスワード</label>
                        <input id="monthly-delete-password" name="password" type="password" autocomplete="current-password" required class="mt-2 w-full rounded border-red-300 text-slate-900 shadow-sm">
                        @error('password', 'monthlyDataDeletion')<p class="mt-2 text-sm font-bold text-red-700">{{ $message }}</p>@enderror
                    </div>
                    <label class="flex items-start gap-2 text-sm font-bold text-red-900"><input name="confirm_monthly_data_deletion" value="1" type="checkbox" required class="mt-1">対象月と削除対象を確認し、削除することに同意します。</label>
                    @error('confirm_monthly_data_deletion', 'monthlyDataDeletion')<p class="text-sm font-bold text-red-700">{{ $message }}</p>@enderror
                    @error('month', 'monthlyDataDeletion')<p class="text-sm font-bold text-red-700">{{ $message }}</p>@enderror
                    <div class="flex flex-wrap gap-3">
                        <button type="submit" class="rounded bg-red-700 px-4 py-2 font-black text-white hover:bg-red-800">{{ $month }}を削除する</button>
                        <a href="{{ route('furimadeck-account.edit') }}" class="rounded border border-slate-300 px-4 py-2 font-black text-slate-700 hover:bg-slate-50">キャンセル</a>
                    </div>
                </form>
            </section>
        </div>
    </div>
</x-app-layout>