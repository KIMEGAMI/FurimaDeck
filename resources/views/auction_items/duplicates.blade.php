<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-2xl font-black text-blue-400">重複チェック</h2>
                <p class="mt-1 text-sm text-cyan-200">
                    商品タイトルと出品先が同じ商品を重複候補として確認できます。
                </p>
            </div>

            <a href="{{ route('auction-items.index') }}" class="inline-flex items-center justify-center rounded-xl bg-slate-200 px-5 py-3 text-sm font-bold text-slate-700 shadow transition hover:bg-slate-300">
                商品一覧へ戻る
            </a>
        </div>
    </x-slot>

    <div class="min-h-screen bg-slate-100 py-10">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
            @foreach (['success' => 'blue', 'error' => 'red'] as $flashKey => $color)
                @if(session($flashKey))
                    <div class="mb-6 rounded-2xl border border-{{ $color }}-200 bg-{{ $color }}-50 px-6 py-5">
                        <p class="font-bold text-{{ $color }}-700">{{ session($flashKey) }}</p>
                    </div>
                @endif
            @endforeach

            @if($errors->any())
                <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 px-6 py-5">
                    <p class="font-bold text-black">{{ $errors->first() }}</p>
                </div>
            @endif

            @if($duplicateGroups->isEmpty())
                <section class="rounded-3xl border border-cyan-200 bg-white p-10 text-center shadow">
                    <p class="text-sm font-black tracking-widest text-cyan-700">DUPLICATE CHECK</p>
                    <h3 class="mt-3 text-2xl font-black text-slate-950">重複候補はありません</h3>
                    <p class="mt-3 text-sm font-bold leading-7 text-slate-600">
                        同じ商品タイトルと出品先の組み合わせは見つかりませんでした。
                    </p>
                </section>
            @else
                <div class="mb-6 rounded-3xl border border-amber-200 bg-white p-6 shadow">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <p class="text-sm font-black text-amber-700">確認してから削除してください</p>
                            <p class="mt-2 text-sm font-bold leading-7 text-slate-600">
                                重複候補は「商品タイトルを正規化したもの」と「出品先」が同じ商品です。削除すると商品画像も削除されます。
                            </p>
                        </div>
                        <form
                            method="POST"
                            action="{{ route('auction-items.duplicates.destroy') }}"
                            data-confirm="すべての重複候補について、各グループの最新商品だけを残し、それ以外を削除します。商品画像も削除されます。本当に実行しますか？"
                            class="shrink-0"
                        >
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="delete_mode" value="all_latest">
                            <button type="submit" class="w-full rounded-xl bg-red-700 px-5 py-3 text-sm font-black text-white shadow transition hover:bg-red-800 lg:w-auto">
                                すべての重複を削除
                            </button>
                        </form>
                    </div>
                </div>

                <div class="space-y-6">
                    @foreach($duplicateGroups as $groupIndex => $group)
                        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="text-xs font-black tracking-widest text-cyan-700">重複候補 {{ $groupIndex + 1 }}</p>
                                    <h3 class="mt-2 text-xl font-black text-slate-950">{{ $group['title'] }}</h3>
                                    <p class="mt-1 text-sm font-bold text-amber-700">判定: {{ $group['reason'] ?? '出品先 + 商品タイトル' }}</p>
                                    <p class="mt-1 text-sm font-bold text-slate-600">出品先: {{ $group['platform'] }}</p>
                                </div>
                                <div class="flex flex-col gap-3 sm:items-end">
                                    <p class="rounded-full bg-amber-50 px-4 py-2 text-sm font-black text-amber-700">
                                        {{ $group['items']->count() }}件
                                    </p>
                                    <form
                                        method="POST"
                                        action="{{ route('auction-items.duplicates.destroy') }}"
                                        data-confirm="この重複候補で最新の商品だけを残し、残りをすべて削除します。商品画像も削除されます。本当に実行しますか？"
                                    >
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="delete_mode" value="latest">
                                        <input type="hidden" name="duplicate_key" value="{{ $group['key'] }}">
                                        <button type="submit" class="rounded-xl border border-red-300 bg-white px-5 py-3 text-sm font-black text-red-700 shadow transition hover:bg-red-50">
                                            最新を残して残りは削除
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <form
                                method="POST"
                                action="{{ route('auction-items.duplicates.destroy') }}"
                                class="mt-5"
                                data-confirm="選択した商品だけを残し、この重複候補の他の商品を削除します。商品画像も削除されます。本当に実行しますか？"
                            >
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="delete_mode" value="selected">

                                <div class="overflow-x-auto">
                                    <table class="min-w-full border-collapse text-left text-sm">
                                        <thead class="bg-slate-100 text-xs font-black text-slate-700">
                                            <tr>
                                                <th class="border border-slate-200 px-3 py-2">残す</th>
                                                <th class="border border-slate-200 px-3 py-2">管理ID</th>
                                                <th class="border border-slate-200 px-3 py-2">ステータス</th>
                                                <th class="border border-slate-200 px-3 py-2">売値</th>
                                                <th class="border border-slate-200 px-3 py-2">SOLD日</th>
                                                <th class="border border-slate-200 px-3 py-2">登録日</th>
                                                <th class="border border-slate-200 px-3 py-2">詳細</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($group['items'] as $itemIndex => $item)
                                                <tr>
                                                    <td class="border border-slate-200 px-3 py-2">
                                                        <label class="inline-flex items-center gap-2 font-bold text-slate-700">
                                                            <input type="radio" name="keep_item_id" value="{{ $item->id }}" @checked($itemIndex === 0) class="border-slate-300 text-cyan-700 focus:ring-cyan-500">
                                                            残す
                                                            @if($itemIndex === 0)
                                                                <span class="rounded-full bg-cyan-50 px-2 py-1 text-xs font-black text-cyan-700">最新</span>
                                                            @endif
                                                        </label>
                                                    </td>
                                                    <td class="border border-slate-200 px-3 py-2 font-mono font-bold">{{ $item->management_id }}</td>
                                                    <td class="border border-slate-200 px-3 py-2">{{ $item->status }}</td>
                                                    <td class="border border-slate-200 px-3 py-2">¥{{ number_format((int) $item->sold_price) }}</td>
                                                    <td class="border border-slate-200 px-3 py-2">{{ $item->sold_at?->format('Y-m-d') ?? '-' }}</td>
                                                    <td class="border border-slate-200 px-3 py-2">{{ $item->created_at?->format('Y-m-d') ?? '-' }}</td>
                                                    <td class="border border-slate-200 px-3 py-2">
                                                        <a href="{{ route('auction-items.show', $item) }}" class="font-black text-cyan-700 hover:text-cyan-900">確認</a>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>

                                <div class="mt-5 flex justify-end">
                                    <button type="submit" class="rounded-xl bg-red-600 px-6 py-3 text-sm font-black text-white shadow transition hover:bg-red-700">
                                        選択以外を削除
                                    </button>
                                </div>
                            </form>
                        </section>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
