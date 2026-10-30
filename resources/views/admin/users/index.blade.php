<x-app-layout>
    <div class="min-h-screen bg-slate-100 py-8">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm font-black text-blue-700">ADMIN</p>
                    <h1 class="mt-2 text-3xl font-black text-slate-950">ユーザ一覧</h1>
                    <p class="mt-2 text-sm font-bold text-slate-700">
                        登録ユーザのプラン状態を確認できます。削除は取り消せないため、対象メールアドレスの入力を必須にしています。
                    </p>
                </div>

                <a href="{{ route('admin.maintenance.index') }}" class="inline-flex w-fit items-center justify-center rounded-xl bg-slate-900 px-5 py-3 text-sm font-black text-white hover:bg-slate-800">
                    管理者画面へ戻る
                </a>
            </div>

            @if (session('status'))
                <div class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-bold text-emerald-800">
                    {{ session('status') }}
                </div>
            @endif

            @if (session('error'))
                <div class="mb-5 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-bold text-red-800">
                    {{ session('error') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-5 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-bold text-red-800">
                    入力内容を確認してください。
                </div>
            @endif

            <section class="overflow-hidden rounded-3xl bg-white shadow-xl">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-950">
                            <tr>
                                <th class="px-5 py-4 text-left text-xs font-black tracking-wider text-white">ユーザ</th>
                                <th class="px-5 py-4 text-left text-xs font-black tracking-wider text-white">プラン</th>
                                <th class="px-5 py-4 text-left text-xs font-black tracking-wider text-white">権限</th>
                                <th class="px-5 py-4 text-right text-xs font-black tracking-wider text-white">商品数</th>
                                <th class="px-5 py-4 text-left text-xs font-black tracking-wider text-white">登録日</th>
                                <th class="px-5 py-4 text-right text-xs font-black tracking-wider text-white">操作</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 bg-white">
                            @foreach ($users as $listedUser)
                                @php
                                    $isCurrentUser = $listedUser->is(Auth::user());
                                    $isPremium = $listedUser->isPremium();
                                    $canForceDelete = ! $isCurrentUser && ! $listedUser->isAdmin();
                                @endphp

                                <tr>
                                    <td class="px-5 py-4 align-top">
                                        <div class="font-black text-slate-950">{{ $listedUser->name }}</div>
                                        <div class="mt-1 text-sm font-bold text-slate-600">{{ $listedUser->email }}</div>
                                    </td>
                                    <td class="px-5 py-4 align-top">
                                        @if ($isPremium)
                                            <span class="inline-flex rounded-full bg-emerald-100 px-3 py-1 text-xs font-black text-emerald-900">Premium</span>
                                        @else
                                            <span class="inline-flex rounded-full bg-slate-200 px-3 py-1 text-xs font-black text-slate-900">Free</span>
                                        @endif

                                        @if ($listedUser->subscription_status)
                                            <div class="mt-2 text-xs font-bold text-slate-600">
                                                {{ $listedUser->subscription_status }}
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-5 py-4 align-top">
                                        @if ($listedUser->isAdmin())
                                            <span class="inline-flex rounded-full bg-blue-100 px-3 py-1 text-xs font-black text-blue-900">管理者</span>
                                        @else
                                            <span class="inline-flex rounded-full bg-white px-3 py-1 text-xs font-black text-slate-700 ring-1 ring-slate-300">一般</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-4 text-right text-sm font-black text-slate-950 align-top">
                                        {{ number_format($listedUser->auction_items_count ?? 0) }}
                                    </td>
                                    <td class="px-5 py-4 text-sm font-bold text-slate-700 align-top">
                                        {{ $listedUser->created_at?->format('Y/m/d H:i') }}
                                    </td>
                                    <td class="px-5 py-4 text-right align-top">
                                        @if ($canForceDelete)
                                            <form method="POST" action="{{ route('admin.users.destroy', $listedUser) }}" class="ml-auto flex max-w-xs flex-col items-end gap-2" data-confirm="このユーザを削除します。Stripe契約がある場合は停止し、商品、通知、セッションも削除されます。実行しますか？">
                                                @csrf
                                                @method('DELETE')
                                                <label for="confirmation_email_{{ $listedUser->id }}" class="sr-only">確認用メールアドレス</label>
                                                <input
                                                    id="confirmation_email_{{ $listedUser->id }}"
                                                    name="confirmation_email"
                                                    type="email"
                                                    autocomplete="off"
                                                    placeholder="{{ $listedUser->email }}"
                                                    class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm font-bold text-slate-950 placeholder:text-slate-500 focus:border-red-500 focus:ring-red-500"
                                                >
                                                <button type="submit" class="rounded-xl bg-red-600 px-4 py-2 text-sm font-black text-white hover:bg-red-700">
                                                    削除
                                                </button>
                                            </form>
                                        @else
                                            <span class="inline-flex rounded-xl bg-slate-100 px-4 py-2 text-sm font-black text-slate-500">
                                                削除不可
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($users->hasPages())
                    <div class="border-t border-slate-200 px-5 py-4">
                        {{ $users->links() }}
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
