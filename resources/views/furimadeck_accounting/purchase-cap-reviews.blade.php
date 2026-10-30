<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold leading-tight text-gray-900">仕入先の確認・年間上限</h2></x-slot>
    <div class="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        @if(session('success'))<div class="rounded border border-green-300 bg-green-50 p-4 text-sm font-semibold text-green-900">{{ session('success') }}</div>@endif
        @if($errors->any())<div class="rounded border border-red-300 bg-red-50 p-4 text-sm font-semibold text-red-900">{{ $errors->first() }}</div>@endif
        <a href="{{ route('furimadeck-accounting.index', ['section' => 'review']) }}" class="text-sm font-semibold text-blue-800 underline">会計データへ戻る</a>
        <section class="rounded border border-amber-300 bg-amber-50 p-5 text-amber-950">
            <h3 class="font-bold">何を登録しますか？</h3>
            <p class="mt-2 text-sm leading-6">適格請求書発行事業者ではない相手先からの課税仕入れについて、相手先ごとに確認します。2023/10/1以後の対象取引は、仕入先名と控除区分を仕訳摘要にも記録します。対象の課税期間では、FurimaDeckに記録された仕入商品代・送料・その他費用を自動集計し、FurimaDeck外の累計額を加えて上限を確認します。</p>
            <p class="mt-2 text-sm leading-6">相手先名は暗号化して保存します。期間は暦年と決めつけず、会計サービスまたは税務資料で確認した課税期間の開始日・終了日を入力してください。FurimaDeck内の対象取引は同じ確認記録にすべて結び付け、外部の累計も確認日まで漏れなく入力してください。確認が不完全なら送信・CSV出力を止め、上限をまたぐ費用の自動分割もしません。</p>
            <p class="mt-2 text-sm font-semibold">上限は課税期間の開始日で判定します。2024/10/1以後に開始する課税期間は相手先ごとに税込10億円、2026/10/1以後に開始する課税期間は税込1億円です。対象期間の控除適用は、税務資料や税理士にも確認してください。</p>
        </section>

        <section class="rounded border border-gray-300 bg-white p-5">
            <h3 class="font-bold text-gray-900">相手先の確認記録を追加</h3>
            <form method="post" action="{{ route('furimadeck-accounting.purchase-caps.store') }}" class="mt-4 grid gap-4 md:grid-cols-2">
                @csrf
                <label class="block text-sm font-semibold text-gray-800">証憑に記載された相手先名
                    <input required maxlength="255" name="counterparty_name" value="{{ old('counterparty_name') }}" class="mt-1 block w-full rounded border-gray-400 text-gray-900">
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block text-sm font-semibold text-gray-800">課税期間の開始日<input required type="date" name="period_from" value="{{ old('period_from') }}" class="mt-1 block w-full rounded border-gray-400 text-gray-900"></label>
                    <label class="block text-sm font-semibold text-gray-800">課税期間の終了日<input required type="date" name="period_to" value="{{ old('period_to') }}" class="mt-1 block w-full rounded border-gray-400 text-gray-900"></label>
                </div>
                <label class="block text-sm font-semibold text-gray-800">FurimaDeck外の対象累計（税込・円）
                    <input required type="number" min="0" max="{{ \App\Services\SaleProfitCalculator::MAX_UNSIGNED_AMOUNT }}" name="external_qualifying_purchase_amount" value="{{ old('external_qualifying_purchase_amount', 0) }}" class="mt-1 block w-full rounded border-gray-400 text-gray-900">
                    <span class="mt-1 block text-xs font-normal text-gray-700">同じ相手先から購入した、対象期間・確認日までの課税仕入れのうち、FurimaDeckに登録していない税込合計です。2024/10/1より前に始まる課税期間では上限判定に使いませんが、相手先確認記録は必要です。</span>
                </label>
                <label class="block text-sm font-semibold text-gray-800">累計を確認した日
                    <input required type="date" name="reviewed_through" value="{{ old('reviewed_through') }}" class="mt-1 block w-full rounded border-gray-400 text-gray-900">
                    <span class="mt-1 block text-xs font-normal text-gray-700">FurimaDeck外の累計を確認した最終日です。確認日より後の日付の仕訳は、累計を更新するまで送信できません。</span>
                </label>
                <div class="space-y-3 md:col-span-2">
                    <label class="flex items-start gap-2 text-sm font-semibold text-gray-900"><input required type="checkbox" name="furimadeck_history_complete" value="1" class="mt-1"><span>同じ相手先のFurimaDeck内の対象仕入れは、同じ確認記録に結び付けてあることを確認しました。</span></label>
                    <label class="flex items-start gap-2 text-sm font-semibold text-gray-900"><input required type="checkbox" name="external_history_complete" value="1" class="mt-1"><span>確認日までのFurimaDeck外の対象仕入れを確認し、上の金額に漏れなく含めたことを確認しました。</span></label>
                </div>
                <button class="w-fit rounded bg-slate-900 px-4 py-2 font-semibold text-white">確認記録を保存</button>
            </form>
        </section>

        <section class="space-y-4">
            <h3 class="font-bold text-gray-900">登録済みの相手先</h3>
            @forelse($reviews as $review)
                <article class="rounded border border-gray-300 bg-white p-4">
                    <div class="grid gap-2 text-sm text-gray-900 md:grid-cols-4">
                        <p><span class="font-semibold">相手先：</span>{{ $review->counterparty_name }}</p>
                        <p><span class="font-semibold">課税期間：</span>{{ $review->period_from->format('Y/m/d') }} ～ {{ $review->period_to->format('Y/m/d') }}</p>
                        <p><span class="font-semibold">FurimaDeck外累計：</span>{{ number_format($review->external_qualifying_purchase_amount) }}円</p>
                        <p><span class="font-semibold">確認日：</span>{{ $review->reviewed_through->format('Y/m/d') }}</p>
                    </div>
                    <form method="post" action="{{ route('furimadeck-accounting.purchase-caps.update', $review) }}" class="mt-4 grid gap-3 md:grid-cols-3">
                        @csrf @method('PUT')
                        <label class="block text-sm font-semibold text-gray-800">FurimaDeck外の累計（税込・円）<input required type="number" min="0" max="{{ \App\Services\SaleProfitCalculator::MAX_UNSIGNED_AMOUNT }}" name="external_qualifying_purchase_amount" value="{{ $review->external_qualifying_purchase_amount }}" class="mt-1 block w-full rounded border-gray-400 text-gray-900"></label>
                        <label class="block text-sm font-semibold text-gray-800">累計を確認した日<input required type="date" name="reviewed_through" value="{{ $review->reviewed_through->format('Y-m-d') }}" min="{{ $review->period_from->format('Y-m-d') }}" max="{{ $review->period_to->format('Y-m-d') }}" class="mt-1 block w-full rounded border-gray-400 text-gray-900"></label>
                        <div class="space-y-2">
                            <label class="flex items-start gap-2 text-xs font-semibold text-gray-800"><input type="checkbox" name="furimadeck_history_complete" value="1" @checked($review->furimadeck_history_complete) class="mt-0.5"><span>FurimaDeck内の同一仕入先記録をすべて結び付けた</span></label>
                            <label class="flex items-start gap-2 text-xs font-semibold text-gray-800"><input type="checkbox" name="external_history_complete" value="1" @checked($review->external_history_complete) class="mt-0.5"><span>確認日までのFurimaDeck外取引を累計に含めた</span></label>
                            <button class="rounded bg-blue-800 px-3 py-2 text-sm font-semibold text-white">累計を更新</button>
                        </div>
                    </form>
                </article>
            @empty
                <p class="rounded border border-gray-300 bg-white p-4 text-sm text-gray-700">確認記録はまだありません。必要な対象取引は、記録を作成して証憑に指定するまで送信・CSV出力が保留されます。</p>
            @endforelse
            {{ $reviews->links() }}
        </section>
    </div>
</x-app-layout>
