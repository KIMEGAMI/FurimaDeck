<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-black text-blue-400">
                プロフィール設定
            </h2>
            <p class="mt-1 text-sm text-cyan-200">
                アカウント情報、メールアドレス、パスワードを確認・変更できます。
            </p>
        </div>
    </x-slot>

    <div class="min-h-screen bg-slate-100 py-10">
        <div class="mx-auto max-w-5xl space-y-8 px-4 sm:px-6 lg:px-8">
            <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow">
                <div class="border-b border-slate-200 bg-gradient-to-r from-blue-700 to-blue-500 px-8 py-6">
                    <h3 class="text-xl font-black text-white">基本情報</h3>
                    <p class="mt-1 text-sm text-blue-50">
                        ユーザー名とメールアドレスを変更できます。
                    </p>
                </div>

                <div class="p-8">
                    @include('profile.partials.update-profile-information-form')

                    <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-5">
                        <p class="text-sm font-black text-emerald-900">累計売上</p>
                        <p class="mt-2 text-2xl font-black text-slate-950">¥{{ number_format($salesTotal) }}</p>
                        <p class="mt-1 text-xs font-bold text-emerald-800">キャンセル・返品を除く販売確定分</p>
                    </div>
                </div>
            </div>

            <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow">
                <div class="border-b border-slate-200 px-8 py-6">
                    <h3 class="text-xl font-black text-slate-900">パスワード変更</h3>
                    <p class="mt-1 text-sm text-slate-700">
                        安全のため、定期的なパスワード変更をおすすめします。
                    </p>
                </div>

                <div class="p-8">
                    @include('profile.partials.update-password-form')
                </div>
            </div>


                <div class="overflow-hidden rounded-3xl border border-amber-200 bg-white shadow">
                    <div class="border-b border-amber-200 bg-amber-50 px-8 py-6">
                        <h3 class="text-xl font-black text-amber-900">FurimaDeckデータ管理</h3>
                        <p class="mt-1 text-sm font-bold text-amber-900">アカウントを残したまま、登録データを削除できます。</p>
                    </div>
                    <div class="flex flex-wrap gap-3 p-8">
                        <a href="{{ route('furimadeck-account.data-delete.confirm') }}" class="rounded bg-amber-700 px-4 py-2 font-black text-white hover:bg-amber-800">データ全削除</a>
                        <a href="{{ route('furimadeck-account.monthly-data-delete.confirm', ['month' => now()->format('Y-m')]) }}" class="rounded border border-amber-700 px-4 py-2 font-black text-amber-900 hover:bg-amber-100">期間（月）の削除</a>
                    </div>
                </div>

            @if ($canDeleteAccount)
                <div class="overflow-hidden rounded-3xl border border-red-100 bg-white shadow">
                    <div class="border-b border-red-100 bg-red-50 px-8 py-6">
                        <h3 class="text-xl font-black text-red-700">アカウント削除</h3>
                        <p class="mt-1 text-sm text-red-600">
                            アカウントを削除すると復元できません。
                        </p>
                    </div>

                    <div class="p-8">
                        @include('profile.partials.delete-user-form')
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
