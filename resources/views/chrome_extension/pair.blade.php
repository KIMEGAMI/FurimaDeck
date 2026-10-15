<x-app-layout>
    <div class="mx-auto max-w-xl px-4 py-10">
        <section class="rounded-xl border border-cyan-700 bg-slate-950 p-6 text-cyan-50 shadow-xl">
            <p class="text-sm font-semibold uppercase tracking-wide text-cyan-300">FurimaDeck Chrome拡張</p>
            <h1 class="mt-2 text-2xl font-bold">この端末を連携しますか？</h1>
            <p class="mt-4 leading-7">「{{ $pairing->device_name }}」に、商品情報の読み取りと、あなたが確認した出品履歴・定型文の管理権限を付与します。</p>
            <ul class="mt-4 list-inside list-disc space-y-1 text-sm text-cyan-100">
                <li>商品名・商品ID・説明・状態・在庫数</li>
                <li>出品ドラフトと商品画像</li>
                <li>実際に掲載されたとあなたが確認したURL・価格の出品履歴</li>
                <li>あなたが登録する定型文の表示・追加・編集・削除</li>
                <li>あなた自身の商品・出品履歴・売上のCSV出力</li>
            </ul>
            <p class="mt-4 text-sm text-cyan-200">商品情報や定型文の変更はFurimaDeckへ保存されます。出品操作やフリマサイトへの送信権限はありません。連携はFurimaDeck側で失効できます。</p>
            <form method="POST" action="{{ route('chrome-extension.pairing.complete', $pairing->id) }}" class="mt-6">
                @csrf
                <button type="submit" class="rounded-lg bg-cyan-700 px-5 py-3 font-semibold text-white hover:bg-cyan-600">この端末を連携する</button>
            </form>
        </section>
    </div>
</x-app-layout>
