<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-2xl font-black text-blue-400">CSV管理</h2>
                <p class="mt-1 text-sm text-cyan-200">
                    CSV取り込み、CSV出力、バックアップ、復元用CSVの管理を行います。
                </p>
            </div>

            <a href="{{ route('auction-items.index') }}" class="inline-flex items-center justify-center rounded-xl bg-slate-200 px-5 py-3 text-sm font-bold text-slate-700 shadow transition hover:bg-slate-300">
                商品一覧へ戻る
            </a>
        </div>
    </x-slot>

    <div class="min-h-screen bg-slate-100 py-10">
        <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
            @foreach (['success' => 'blue', 'error' => 'red'] as $flashKey => $color)
                @if(session($flashKey))
                    <div class="mb-6 rounded-2xl border border-{{ $color }}-200 bg-{{ $color }}-50 px-6 py-5">
                        <p class="font-bold text-{{ $color }}-700">{{ session($flashKey) }}</p>
                    </div>
                @endif
            @endforeach

            @if($errors->has('csv_file'))
                <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 px-6 py-5">
                    <p class="font-bold text-black">{{ $errors->first('csv_file') }}</p>
                </div>
            @endif

            @if($errors->has('yahoo_csv_file'))
                <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 px-6 py-5">
                    <p class="font-bold text-black">{{ $errors->first('yahoo_csv_file') }}</p>
                </div>
            @endif

            @if($errors->has('mercari_shops_csv_file'))
                <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 px-6 py-5">
                    <p class="font-bold text-black">{{ $errors->first('mercari_shops_csv_file') }}</p>
                </div>
            @endif

            <div class="space-y-6">
                <section class="rounded-3xl border border-emerald-200 bg-white p-6 shadow">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <p class="text-xs font-black tracking-widest text-emerald-700">EXPORT & BACKUP</p>
                            <h3 class="mt-2 text-xl font-black text-slate-950">CSV出力とバックアップ</h3>
                            <p class="mt-2 text-sm font-semibold leading-6 text-slate-600">
                                売上分析用CSV、全商品バックアップCSV、CSV取り込みへ戻しやすい復元用CSVをここから出力できます。定期的にバックアップしておくと、端末変更や誤操作時の復旧に使えます。
                            </p>
                        </div>
                        <button type="button" onclick="document.getElementById('csvExportHelp').showModal()" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-black text-emerald-700 hover:bg-emerald-100">
                            CSV出力項目を確認
                        </button>
                    </div>

                    <div class="mt-6 grid gap-3 md:grid-cols-4">
                        <a href="{{ route('sales.csv') }}" class="inline-flex items-center justify-center rounded-xl border border-cyan-200 bg-cyan-50 px-5 py-4 text-sm font-black text-cyan-900 shadow-sm hover:bg-cyan-100">
                            売上CSV出力
                        </a>
                        <a href="{{ route('sales.backup-csv') }}" class="inline-flex items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-black text-emerald-900 shadow-sm hover:bg-emerald-100">
                            全商品バックアップCSV
                        </a>
                        <a href="{{ route('sales.selling-csv') }}" class="inline-flex items-center justify-center rounded-xl border border-blue-200 bg-blue-50 px-5 py-4 text-sm font-black text-blue-900 shadow-sm hover:bg-blue-100">
                            出品中のみCSV
                        </a>
                        <a href="{{ route('sales.restore-csv') }}" class="inline-flex items-center justify-center rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm font-black text-amber-900 shadow-sm hover:bg-amber-100">
                            復元用CSV
                        </a>
                    </div>

                    <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                        <h4 class="text-sm font-black text-amber-950">復元しやすいバックアップ</h4>
                        <p class="mt-2 text-sm font-bold leading-6 text-amber-800">
                            「復元用CSV」は、商品を全削除する前、端末変更の前、誤ってデータを消す可能性がある作業の前に保存しておくCSVです。復元したい時は、この画面の「FurimaDeck形式CSVを一括登録」で復元用CSVを選び、「CSVを取り込む」を押してください。すでに同じ管理IDの商品がある場合はスキップされ、未登録の商品だけ戻ります。
                        </p>
                        <div class="mt-4 grid gap-3 md:grid-cols-3">
                            <div class="rounded-xl border border-amber-200 bg-white p-3">
                                <p class="text-sm font-black text-amber-950">いつ保存する？</p>
                                <p class="mt-1 text-xs font-bold leading-5 text-amber-800">商品全削除、CSV一括取り込み、端末変更、大きな整理作業の前です。</p>
                            </div>
                            <div class="rounded-xl border border-amber-200 bg-white p-3">
                                <p class="text-sm font-black text-amber-950">どれを保存する？</p>
                                <p class="mt-1 text-xs font-bold leading-5 text-amber-800">戻す目的なら「復元用CSV」、確認用なら「全商品バックアップCSV」です。</p>
                            </div>
                            <div class="rounded-xl border border-amber-200 bg-white p-3">
                                <p class="text-sm font-black text-amber-950">どう戻す？</p>
                                <p class="mt-1 text-xs font-bold leading-5 text-amber-800">下のFurimaDeck形式CSV取り込みで、保存した復元用CSVを選んで取り込みます。</p>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <p class="text-xs font-black tracking-widest text-slate-500">FurimaDeck CSV</p>
                            <h3 class="mt-2 text-xl font-black text-slate-950">FurimaDeck形式CSVを一括登録</h3>
                            <p class="mt-2 text-sm font-semibold leading-6 text-slate-600">
                                FurimaDeck用に整えたCSVをそのまま取り込みます。1行目はヘッダー行として扱います。
                            </p>
                        </div>
                        <button type="button" onclick="document.getElementById('csvImportHelp').showModal()" class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm font-black text-blue-700 hover:bg-blue-100">
                            CSV項目を確認
                        </button>
                    </div>

                    <form action="{{ route('auction-items.import') }}" method="POST" enctype="multipart/form-data" class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-12 lg:items-end">
                        @csrf
                        <div class="lg:col-span-8">
                            <label for="csv_file" class="block text-sm font-black text-slate-700">CSVファイル</label>
                            <input id="csv_file" type="file" name="csv_file" accept=".csv,.txt" class="mt-2 block w-full rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                            <p class="mt-2 text-xs font-semibold text-slate-500">
                                最低限、management_id と title の列が必要です。
                            </p>
                        </div>
                        <div class="lg:col-span-4">
                            <button type="submit" class="w-full rounded-xl bg-slate-800 px-5 py-3 text-sm font-bold text-white shadow transition hover:bg-slate-900">
                                CSVを取り込む
                            </button>
                        </div>
                    </form>
                </section>

                <section class="rounded-3xl border border-cyan-200 bg-white p-6 shadow">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <p class="text-xs font-black tracking-widest text-cyan-700">YAHOO AUCTIONS</p>
                            <h3 class="mt-2 text-xl font-black text-slate-950">ヤフオク売上CSVを変換して一括登録</h3>
                            <p class="mt-2 text-sm font-semibold leading-6 text-slate-600">
                                ヤフオクの売上CSVをFurimaDeck形式に変換し、SOLD商品として登録します。
                            </p>
                        </div>
                        <button type="button" onclick="document.getElementById('yahooCsvImportHelp').showModal()" class="rounded-lg border border-cyan-200 bg-cyan-50 px-4 py-3 text-sm font-black text-cyan-700 hover:bg-cyan-100">
                            ヤフオクCSV項目を確認
                        </button>
                    </div>

                    <form action="{{ route('auction-items.import.yahoo-auctions') }}" method="POST" enctype="multipart/form-data" class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-12 lg:items-end">
                        @csrf
                        <div class="lg:col-span-8">
                            <label for="yahoo_csv_file" class="block text-sm font-black text-slate-700">ヤフオク売上CSVファイル</label>
                            <input id="yahoo_csv_file" type="file" name="yahoo_csv_file" accept=".csv,.txt" class="mt-2 block w-full rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                            <p class="mt-2 text-xs font-semibold text-slate-500">
                                状態が「売上確定」で、商品IDが入っている行だけを取り込みます。
                            </p>
                        </div>
                        <div class="lg:col-span-4">
                            <button type="submit" class="w-full rounded-xl bg-cyan-700 px-5 py-3 text-sm font-bold text-white shadow transition hover:bg-cyan-800">
                                変換して一括登録
                            </button>
                        </div>
                    </form>
                </section>

                <section class="rounded-3xl border border-red-200 bg-white p-6 shadow">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <p class="text-xs font-black tracking-widest text-red-700">MERCARI SHOPS</p>
                            <h3 class="mt-2 text-xl font-black text-slate-950">メルカリShops CSVを変換して一括登録</h3>
                            <p class="mt-2 text-sm font-semibold leading-6 text-slate-600">
                                メルカリShopsの売上レポートCSVをFurimaDeck形式に変換し、SOLD商品として登録します。注文番号と明細番号を管理IDとして扱います。
                            </p>
                        </div>
                        <button type="button" onclick="document.getElementById('mercariShopsCsvImportHelp').showModal()" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-black text-red-700 hover:bg-red-100">
                            メルカリShops CSV項目を確認
                        </button>
                    </div>

                    <form action="{{ route('auction-items.import.mercari-shops') }}" method="POST" enctype="multipart/form-data" class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-12 lg:items-end">
                        @csrf
                        <div class="lg:col-span-8">
                            <label for="mercari_shops_csv_file" class="block text-sm font-black text-slate-700">メルカリShops CSVファイル</label>
                            <input id="mercari_shops_csv_file" type="file" name="mercari_shops_csv_file" accept=".csv,.txt" class="mt-2 block w-full rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                            <p class="mt-2 text-xs font-semibold text-slate-500">
                                キャンセル日が空で、売上（税込）が入っている行だけをSOLD商品として取り込みます。
                            </p>
                        </div>
                        <div class="lg:col-span-4">
                            <button type="submit" class="w-full rounded-xl bg-red-700 px-5 py-3 text-sm font-bold text-white shadow transition hover:bg-red-800">
                                変換して一括登録
                            </button>
                        </div>
                    </form>
                </section>
            </div>

            <dialog id="csvExportHelp" class="w-11/12 max-w-4xl rounded-2xl p-0 shadow-2xl backdrop:bg-slate-950/70">
                <div class="max-h-[85vh] overflow-y-auto bg-white p-6 text-slate-800">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-xl font-black text-slate-950">CSV出力項目</h3>
                            <p class="mt-2 text-sm font-bold leading-6 text-slate-600">
                                売上CSVはSOLD商品の集計用、全商品バックアップCSVは確認用、復元用CSVは削除前や端末変更前に保存しておき、あとからCSV取り込みで商品を戻すための控えです。いずれもExcelで開きやすいUTF-8 BOM付きで出力します。
                            </p>
                        </div>
                        <button type="button" onclick="document.getElementById('csvExportHelp').close()" class="rounded-lg bg-slate-100 px-3 py-2 text-sm font-black text-slate-700 hover:bg-slate-200">閉じる</button>
                    </div>

                    <div class="mt-5 overflow-x-auto">
                        <table class="min-w-full border-collapse text-left text-sm">
                            <thead class="bg-slate-100 text-xs font-black text-slate-700">
                                <tr>
                                    <th class="border border-slate-200 px-3 py-2">CSV</th>
                                    <th class="border border-slate-200 px-3 py-2">用途</th>
                                    <th class="border border-slate-200 px-3 py-2">主な項目</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="border border-slate-200 px-3 py-2 font-bold">売上CSV</td>
                                    <td class="border border-slate-200 px-3 py-2">SOLD商品の売上分析用です。</td>
                                    <td class="border border-slate-200 px-3 py-2">管理ID、タイトル、ジャンル、出品先、仕入れ値、売値、手数料、送料、実利益、SOLD日</td>
                                </tr>
                                <tr>
                                    <td class="border border-slate-200 px-3 py-2 font-bold">全商品バックアップCSV</td>
                                    <td class="border border-slate-200 px-3 py-2">全商品の控えと確認用です。</td>
                                    <td class="border border-slate-200 px-3 py-2">売上CSVの項目に加えて、商品画像URL、SOLD画像URL、コメント、作成日、更新日</td>
                                </tr>
                                <tr>
                                    <td class="border border-slate-200 px-3 py-2 font-bold">出品中のみCSV</td>
                                    <td class="border border-slate-200 px-3 py-2">売れていない出品中商品の控えです。</td>
                                    <td class="border border-slate-200 px-3 py-2">復元用CSVと同じ英字ヘッダ形式で、status は selling、SOLD日は空で出力します。</td>
                                </tr>
                                <tr>
                                    <td class="border border-slate-200 px-3 py-2 font-bold">復元用CSV</td>
                                    <td class="border border-slate-200 px-3 py-2">商品全削除前、端末変更前、データ整理前に保存しておき、必要になったらFurimaDeck形式CSV取り込みから戻します。</td>
                                    <td class="border border-slate-200 px-3 py-2">management_id、title、comment、platform、parent_category、category、価格、ステータス、SOLD日。同じ管理IDは重複登録されません。</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </dialog>

            <dialog id="csvImportHelp" class="w-11/12 max-w-4xl rounded-2xl p-0 shadow-2xl backdrop:bg-slate-950/70">
                <div class="max-h-[85vh] overflow-y-auto bg-white p-6 text-slate-800">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-xl font-black text-slate-950">FurimaDeck形式CSVの項目</h3>
                            <p class="mt-2 text-sm font-bold leading-6 text-slate-600">
                                CSVの1行目はヘッダー行として扱います。必須は management_id と title です。外部サービスのCSVを直接取り込む形式ではありません。
                            </p>
                        </div>
                        <button type="button" onclick="document.getElementById('csvImportHelp').close()" class="rounded-lg bg-slate-100 px-3 py-2 text-sm font-black text-slate-700 hover:bg-slate-200">閉じる</button>
                    </div>

                    <div class="mt-5 overflow-x-auto">
                        <table class="min-w-full border-collapse text-left text-sm">
                            <thead class="bg-slate-100 text-xs font-black text-slate-700">
                                <tr>
                                    <th class="border border-slate-200 px-3 py-2">CSVヘッダー名</th>
                                    <th class="border border-slate-200 px-3 py-2">画面項目</th>
                                    <th class="border border-slate-200 px-3 py-2">説明</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td class="border border-slate-200 px-3 py-2 font-mono font-bold">management_id</td><td class="border border-slate-200 px-3 py-2">管理ID</td><td class="border border-slate-200 px-3 py-2">必須。同じユーザー内では重複登録できません。</td></tr>
                                <tr><td class="border border-slate-200 px-3 py-2 font-mono font-bold">title</td><td class="border border-slate-200 px-3 py-2">商品タイトル</td><td class="border border-slate-200 px-3 py-2">必須。商品一覧や詳細画面に表示されます。</td></tr>
                                <tr><td class="border border-slate-200 px-3 py-2 font-mono font-bold">comment</td><td class="border border-slate-200 px-3 py-2">コメント</td><td class="border border-slate-200 px-3 py-2">任意。商品の説明やメモとして保存します。</td></tr>
                                <tr><td class="border border-slate-200 px-3 py-2 font-mono font-bold">platform</td><td class="border border-slate-200 px-3 py-2">出品先</td><td class="border border-slate-200 px-3 py-2">任意の出品先名です。外部CSV変換はヤフオク、メルカリShopsに対応しています。</td></tr>
                                <tr><td class="border border-slate-200 px-3 py-2 font-mono font-bold">大ジャンル</td><td class="border border-slate-200 px-3 py-2">大ジャンル</td><td class="border border-slate-200 px-3 py-2">登録済みジャンル名と一致した場合、小ジャンルと組み合わせて反映します。</td></tr>
                                <tr><td class="border border-slate-200 px-3 py-2 font-mono font-bold">小ジャンル</td><td class="border border-slate-200 px-3 py-2">小ジャンル</td><td class="border border-slate-200 px-3 py-2">大ジャンル配下の小ジャンル名です。一致しない場合は未設定になります。</td></tr>
                                <tr><td class="border border-slate-200 px-3 py-2 font-mono font-bold">purchase_price</td><td class="border border-slate-200 px-3 py-2">仕入れ値</td><td class="border border-slate-200 px-3 py-2">半角数字で入力します。空欄は0円扱いです。</td></tr>
                                <tr><td class="border border-slate-200 px-3 py-2 font-mono font-bold">sold_price</td><td class="border border-slate-200 px-3 py-2">売値</td><td class="border border-slate-200 px-3 py-2">販売予定額または販売額です。半角数字で入力します。</td></tr>
                                <tr><td class="border border-slate-200 px-3 py-2 font-mono font-bold">shipping_fee</td><td class="border border-slate-200 px-3 py-2">送料</td><td class="border border-slate-200 px-3 py-2">半角数字で入力します。利益計算に使用します。</td></tr>
                                <tr><td class="border border-slate-200 px-3 py-2 font-mono font-bold">sales_fee_rate</td><td class="border border-slate-200 px-3 py-2">販売手数料率</td><td class="border border-slate-200 px-3 py-2">10 や 8.8 など、%を除いた数値で入力します。空欄なら出品先ごとの標準率を使用します。</td></tr>
                                <tr><td class="border border-slate-200 px-3 py-2 font-mono font-bold">status</td><td class="border border-slate-200 px-3 py-2">ステータス</td><td class="border border-slate-200 px-3 py-2">selling、sold、draft のいずれかです。空欄や不明な値は selling になります。</td></tr>
                                <tr><td class="border border-slate-200 px-3 py-2 font-mono font-bold">sold_at</td><td class="border border-slate-200 px-3 py-2">SOLD日</td><td class="border border-slate-200 px-3 py-2">status が sold の場合に使用します。例: 2026-07-19</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-5 rounded-xl bg-slate-50 p-4 text-sm font-bold leading-6 text-slate-700">
                        ヘッダー例: management_id,title,comment,platform,大ジャンル,小ジャンル,purchase_price,sold_price,shipping_fee,sales_fee_rate,status,sold_at
                    </div>
                </div>
            </dialog>

            <dialog id="yahooCsvImportHelp" class="w-11/12 max-w-4xl rounded-2xl p-0 shadow-2xl backdrop:bg-slate-950/70">
                <div class="max-h-[85vh] overflow-y-auto bg-white p-6 text-slate-800">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-xl font-black text-slate-950">ヤフオク売上CSVの変換内容</h3>
                            <p class="mt-2 text-sm font-bold leading-6 text-slate-600">
                                1行目はヘッダー行として扱います。状態が「売上確定」で、商品IDが入っている行だけを取り込みます。チャージ行や商品IDが「-」の行はスキップします。
                            </p>
                        </div>
                        <button type="button" onclick="document.getElementById('yahooCsvImportHelp').close()" class="rounded-lg bg-slate-100 px-3 py-2 text-sm font-black text-slate-700 hover:bg-slate-200">閉じる</button>
                    </div>

                    <div class="mt-5 overflow-x-auto">
                        <table class="min-w-full border-collapse text-left text-sm">
                            <thead class="bg-slate-100 text-xs font-black text-slate-700">
                                <tr>
                                    <th class="border border-slate-200 px-3 py-2">ヤフオクCSV列</th>
                                    <th class="border border-slate-200 px-3 py-2">FurimaDeck項目</th>
                                    <th class="border border-slate-200 px-3 py-2">変換内容</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td class="border border-slate-200 px-3 py-2 font-bold">商品ID</td><td class="border border-slate-200 px-3 py-2 font-mono">management_id</td><td class="border border-slate-200 px-3 py-2">管理IDとして登録します。同じ商品IDが既にある場合はスキップします。</td></tr>
                                <tr><td class="border border-slate-200 px-3 py-2 font-bold">取引内容</td><td class="border border-slate-200 px-3 py-2 font-mono">title</td><td class="border border-slate-200 px-3 py-2">商品タイトルとして登録します。</td></tr>
                                <tr><td class="border border-slate-200 px-3 py-2 font-bold">取引日</td><td class="border border-slate-200 px-3 py-2 font-mono">sold_at</td><td class="border border-slate-200 px-3 py-2">SOLD日として登録します。</td></tr>
                                <tr><td class="border border-slate-200 px-3 py-2 font-bold">決済金額</td><td class="border border-slate-200 px-3 py-2 font-mono">sold_price</td><td class="border border-slate-200 px-3 py-2">購入者が支払った金額を売値として登録します。</td></tr>
                                <tr><td class="border border-slate-200 px-3 py-2 font-bold">落札システム利用料 / 販売手数料</td><td class="border border-slate-200 px-3 py-2 font-mono">sales_fee</td><td class="border border-slate-200 px-3 py-2">どちらかに入っている手数料を合計して登録します。</td></tr>
                                <tr><td class="border border-slate-200 px-3 py-2 font-bold">送料</td><td class="border border-slate-200 px-3 py-2 font-mono">shipping_fee</td><td class="border border-slate-200 px-3 py-2">送料として登録します。</td></tr>
                                <tr><td class="border border-slate-200 px-3 py-2 font-bold">状態</td><td class="border border-slate-200 px-3 py-2 font-mono">status</td><td class="border border-slate-200 px-3 py-2">「売上確定」の行だけをSOLDとして登録します。</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </dialog>

            <dialog id="mercariShopsCsvImportHelp" class="w-11/12 max-w-4xl rounded-2xl p-0 shadow-2xl backdrop:bg-slate-950/70">
                <div class="max-h-[85vh] overflow-y-auto bg-white p-6 text-slate-800">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-xl font-black text-slate-950">メルカリShops CSVの変換内容</h3>
                            <p class="mt-2 text-sm font-bold leading-6 text-slate-600">
                                メルカリShopsの売上レポートCSVをSOLD商品として登録します。仕入れ値は0円で登録されるため、必要に応じて登録後に編集してください。
                            </p>
                        </div>
                        <button type="button" onclick="document.getElementById('mercariShopsCsvImportHelp').close()" class="rounded-lg bg-slate-100 px-3 py-2 text-sm font-black text-slate-700 hover:bg-slate-200">閉じる</button>
                    </div>

                    <div class="mt-5 overflow-x-auto">
                        <table class="min-w-full border-collapse text-left text-sm">
                            <thead class="bg-slate-100 text-xs font-black text-slate-700">
                                <tr>
                                    <th class="border border-slate-200 px-3 py-2">メルカリShops CSV列</th>
                                    <th class="border border-slate-200 px-3 py-2">FurimaDeck項目</th>
                                    <th class="border border-slate-200 px-3 py-2">変換内容</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td class="border border-slate-200 px-3 py-2 font-bold">注文番号 + 明細番号</td><td class="border border-slate-200 px-3 py-2 font-mono">management_id</td><td class="border border-slate-200 px-3 py-2">「注文番号-明細番号」の形式で登録します。同じ管理IDが既にある場合はスキップします。</td></tr>
                                <tr><td class="border border-slate-200 px-3 py-2 font-bold">商品名</td><td class="border border-slate-200 px-3 py-2 font-mono">title</td><td class="border border-slate-200 px-3 py-2">商品タイトルとして登録します。</td></tr>
                                <tr><td class="border border-slate-200 px-3 py-2 font-bold">購入日</td><td class="border border-slate-200 px-3 py-2 font-mono">sold_at</td><td class="border border-slate-200 px-3 py-2">SOLD日として登録します。</td></tr>
                                <tr><td class="border border-slate-200 px-3 py-2 font-bold">売上（税込）</td><td class="border border-slate-200 px-3 py-2 font-mono">sold_price</td><td class="border border-slate-200 px-3 py-2">売値として登録します。</td></tr>
                                <tr><td class="border border-slate-200 px-3 py-2 font-bold">販売手数料（税込）</td><td class="border border-slate-200 px-3 py-2 font-mono">sales_fee</td><td class="border border-slate-200 px-3 py-2">販売手数料として登録します。</td></tr>
                                <tr><td class="border border-slate-200 px-3 py-2 font-bold">メルカリ便送料（税込） + 送料（税込）</td><td class="border border-slate-200 px-3 py-2 font-mono">shipping_fee</td><td class="border border-slate-200 px-3 py-2">送料として合算して登録します。</td></tr>
                                <tr><td class="border border-slate-200 px-3 py-2 font-bold">キャンセル日</td><td class="border border-slate-200 px-3 py-2 font-mono">status</td><td class="border border-slate-200 px-3 py-2">キャンセル日が入っている行は取り込みません。</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </dialog>
        </div>
    </div>
</x-app-layout>
