<x-app-layout>
    <x-slot name="header"><h2 class="text-2xl font-black text-cyan-200">CSV管理</h2></x-slot>
    <div class="min-h-screen bg-slate-100 py-8"><div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
        <section class="rounded bg-white p-6 shadow">
            <h3 class="text-lg font-black text-slate-900">完全バックアップCSVを復元</h3>
            <p class="mt-2 text-sm text-slate-600">sellingは出品中、soldは売却済みとして復元します。既存の管理IDは上書きせずスキップします。</p>
            <form method="POST" action="{{ route('products.imports.restore-preview') }}" enctype="multipart/form-data" class="mt-5 space-y-5">
                @csrf
                <label class="block text-sm font-bold text-slate-800">復元CSV
                    <input required type="file" name="csv_file" accept=".csv,text/csv" class="mt-2 block w-full rounded border-slate-300 text-slate-900">
                </label>
                <button class="rounded bg-amber-400 px-4 py-2 text-sm font-black text-slate-950 hover:bg-amber-300">復元内容を検証</button>
            </form>
        </section>
        <section class="rounded bg-white p-6 shadow">
            <h3 class="text-lg font-black text-slate-900">FurimaDeck商品CSV取込</h3>
            <p class="mt-2 text-sm text-slate-600">1行目をヘッダとして読み込みます。UTF-8 / UTF-8 BOM / CP932 / Shift_JIS / SJIS-win / EUC-JP、10MB以内・5,000行以内に対応しています。</p>
            <div class="mt-4 rounded border border-cyan-200 bg-cyan-50 p-4 text-sm text-slate-800">
                <p class="font-black">最小構成（この2列だけで作成できます）</p>
                <code class="mt-2 block overflow-x-auto whitespace-nowrap rounded bg-white px-3 py-2 text-xs text-slate-900">internal_sku,product_name</code>
                <code class="mt-2 block overflow-x-auto whitespace-nowrap rounded bg-white px-3 py-2 text-xs text-slate-900">FD-001,黒いジャケット</code>
                <p class="mt-2 leading-6"><code>internal_sku</code>は自分で決める管理ID、<code>product_name</code>は商品名です。同じユーザー内で管理IDが重複する行は登録できません。未指定の数量・原価・在庫状態は初期値が設定されます。</p>
            </div>
            <details class="mt-4 rounded border border-slate-200 bg-slate-50 p-4">
                <summary class="cursor-pointer font-black text-slate-900">CSVヘッダの説明を開く</summary>
                <div class="mt-4 space-y-5 text-sm text-slate-800">
                    <div>
                        <h4 class="font-black text-slate-900">そのまま使える推奨ヘッダ</h4>
                        <code class="mt-2 block overflow-x-auto whitespace-nowrap rounded bg-white px-3 py-2 text-xs text-slate-900">internal_sku,product_name,condition,purchase_unit_cost,purchase_quantity,quantity_available,inventory_status,description_base,purchase_date,purchase_shipping_cost,other_purchase_expense,jan_ean,isbn,manufacturer_model_number,serial_number,storage_location,memo,marketplace_code,listed_at,listing_price,sale_state,sale_quantity,sold_price,sold_at,sales_fee,shipping_fee,sale_purchase_shipping_cost,packing_cost,repair_cost,cleaning_cost,sale_other_expense</code>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full border-collapse text-left text-xs">
                            <thead class="bg-slate-200 text-slate-900"><tr><th class="border border-slate-300 px-3 py-2">ヘッダ</th><th class="border border-slate-300 px-3 py-2">必須</th><th class="border border-slate-300 px-3 py-2">内容・入力例</th></tr></thead>
                            <tbody>
                                <tr><td class="border border-slate-300 px-3 py-2 font-mono font-bold">internal_sku</td><td class="border border-slate-300 px-3 py-2 font-black">必須</td><td class="border border-slate-300 px-3 py-2">管理ID。例: FD-001。ユーザー内で重複不可。</td></tr>
                                <tr><td class="border border-slate-300 px-3 py-2 font-mono font-bold">product_name</td><td class="border border-slate-300 px-3 py-2 font-black">必須</td><td class="border border-slate-300 px-3 py-2">商品名。例: 黒いジャケット。</td></tr>
                                <tr><td class="border border-slate-300 px-3 py-2 font-mono">condition</td><td class="border border-slate-300 px-3 py-2">任意</td><td class="border border-slate-300 px-3 py-2">商品状態。new / unused / like_new / used_good / used / damaged / junk。省略時は used。</td></tr>
                                <tr><td class="border border-slate-300 px-3 py-2 font-mono">purchase_unit_cost</td><td class="border border-slate-300 px-3 py-2">任意</td><td class="border border-slate-300 px-3 py-2">1個あたりの仕入れ値（円）。0以上の整数。省略時は0。</td></tr>
                                <tr><td class="border border-slate-300 px-3 py-2 font-mono">purchase_quantity</td><td class="border border-slate-300 px-3 py-2">任意</td><td class="border border-slate-300 px-3 py-2">仕入れ数量。1以上の整数。省略時は1。</td></tr>
                                <tr><td class="border border-slate-300 px-3 py-2 font-mono">quantity_available</td><td class="border border-slate-300 px-3 py-2">任意</td><td class="border border-slate-300 px-3 py-2">現在の在庫数。0以上の整数。省略時は通常1、soldなら0。</td></tr>
                                <tr><td class="border border-slate-300 px-3 py-2 font-mono">inventory_status</td><td class="border border-slate-300 px-3 py-2">任意</td><td class="border border-slate-300 px-3 py-2">在庫状態。例: in_stock / out_of_stock。quantity_availableから自動判定されます。指定する場合も数量と一致させてください。</td></tr>
                                <tr><td class="border border-slate-300 px-3 py-2 font-mono">parent_category / category</td><td class="border border-slate-300 px-3 py-2">任意</td><td class="border border-slate-300 px-3 py-2">大ジャンル / 小ジャンル。登録済みの組み合わせと一致した場合に反映。</td></tr>
                                <tr><td class="border border-slate-300 px-3 py-2 font-mono">purchase_date</td><td class="border border-slate-300 px-3 py-2">任意</td><td class="border border-slate-300 px-3 py-2">仕入日。YYYY-MM-DD形式。例: 2026-09-20。</td></tr>
                                <tr><td class="border border-slate-300 px-3 py-2 font-mono">purchase_shipping_cost / other_purchase_expense</td><td class="border border-slate-300 px-3 py-2">任意</td><td class="border border-slate-300 px-3 py-2">仕入送料 / その他仕入経費（円）。0以上の整数。</td></tr>                                <tr><td class="border border-slate-300 px-3 py-2 font-mono">description_base / memo</td><td class="border border-slate-300 px-3 py-2">任意</td><td class="border border-slate-300 px-3 py-2">商品説明の元文 / 管理用メモ。</td></tr>
                                <tr><td class="border border-slate-300 px-3 py-2 font-mono">category_id / supplier_id</td><td class="border border-slate-300 px-3 py-2">任意</td><td class="border border-slate-300 px-3 py-2">既存のカテゴリID / 仕入先ID。指定する場合は1以上の整数。</td></tr>
                                <tr><td class="border border-slate-300 px-3 py-2 font-mono">jan_ean / isbn / manufacturer_model_number</td><td class="border border-slate-300 px-3 py-2">任意</td><td class="border border-slate-300 px-3 py-2">JAN・EAN / ISBN / メーカー型番。</td></tr>
                                <tr><td class="border border-slate-300 px-3 py-2 font-mono">serial_number / storage_location</td><td class="border border-slate-300 px-3 py-2">任意</td><td class="border border-slate-300 px-3 py-2">シリアル番号 / 保管場所。</td></tr>
                                <tr><td class="border border-slate-300 px-3 py-2 font-mono">marketplace_code / listed_at / listing_price</td><td class="border border-slate-300 px-3 py-2">条件付き</td><td class="border border-slate-300 px-3 py-2">出品先コード / 出品日 / 出品価格。listed_atを指定する場合は3項目を入力。</td></tr>
                                <tr><td class="border border-slate-300 px-3 py-2 font-mono">sale_state</td><td class="border border-slate-300 px-3 py-2">任意</td><td class="border border-slate-300 px-3 py-2">stockまたはsold。省略時はstock。soldではProduct、Listing、Saleを作成。</td></tr>
                                <tr><td class="border border-slate-300 px-3 py-2 font-mono">sale_quantity / sold_price / sold_at</td><td class="border border-slate-300 px-3 py-2">sold時必須</td><td class="border border-slate-300 px-3 py-2">販売数量 / 売価 / 売却日。YYYY-MM-DD、金額と数量は0以上または1以上の整数。</td></tr>
                                <tr><td class="border border-slate-300 px-3 py-2 font-mono">sales_fee / shipping_fee</td><td class="border border-slate-300 px-3 py-2">任意</td><td class="border border-slate-300 px-3 py-2">販売手数料 / 販売送料。空欄は0円。</td></tr>
                                <tr><td class="border border-slate-300 px-3 py-2 font-mono">sale_purchase_shipping_cost / packing_cost / repair_cost / cleaning_cost / sale_other_expense</td><td class="border border-slate-300 px-3 py-2">任意</td><td class="border border-slate-300 px-3 py-2">売上側の追加経費。空欄は0円。利益はサーバー側で再計算。</td></tr>
                                <tr><td class="border border-slate-300 px-3 py-2 font-mono">memo / comment</td><td class="border border-slate-300 px-3 py-2">任意</td><td class="border border-slate-300 px-3 py-2">メモ・商品説明。カンマや改行を含む場合はCSVのルールに従いダブルクォートで囲む。</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="rounded border border-emerald-200 bg-emerald-50 p-4 text-sm leading-6">
                        <h4 class="font-black text-emerald-950">売上実績を同時に取り込む列</h4>
                        <p class="mt-1">在庫だけを登録する場合は <code>sale_state=stock</code> または列を省略します。売上履歴も登録する場合は、次の列を指定してください。</p>
                        <code class="mt-2 block overflow-x-auto whitespace-nowrap rounded bg-white px-3 py-2 text-xs text-slate-900">marketplace_code,listed_at,listing_price,sale_state,sale_quantity,sold_price,sold_at,sales_fee,shipping_fee,sale_purchase_shipping_cost,packing_cost,repair_cost,cleaning_cost,sale_other_expense</code>
                        <p class="mt-2"><code>listed_at</code> がある行は <code>marketplace_code</code> と <code>listing_price</code> も必須です。<code>sale_state=sold</code> のときは <code>marketplace_code</code>、<code>listed_at</code>、<code>listing_price</code>、<code>sale_quantity</code>、<code>sold_price</code>、<code>sold_at</code> が必須です。出品先は <code>mercari</code>、<code>yahoo_flea_market</code>、<code>yahoo_auctions</code>、<code>rakuma</code>、<code>other</code> のいずれかを指定します。</p><p class="mt-2"><code>sale_state=stock</code> の販売列はすべて空欄にしてください。<code>sale_purchase_shipping_cost</code>、<code>packing_cost</code>、<code>repair_cost</code>、<code>cleaning_cost</code>、<code>sale_other_expense</code> は売上時の追加費用です。すべて円単位の0以上の整数です。利益はサーバー側で再計算します。</p>
                        <p class="mt-2">利益は「売値 - 仕入原価（仕入単価×販売数量）- 各費用」で再計算します。<code>inventory_status=out_of_stock</code> だけではSOLDになりません。数量0かつ有効な販売状態の履歴がある場合だけ商品一覧にSOLDを表示します。</p>
                        <pre class="mt-2 overflow-x-auto rounded bg-white p-3 text-xs text-slate-900">internal_sku,product_name,purchase_unit_cost,purchase_quantity,quantity_available,inventory_status,marketplace_code,listed_at,listing_price,sale_state,sale_quantity,sold_price,sold_at,sales_fee,shipping_fee
FD-SOLD-001,売却済み商品,1000,1,0,out_of_stock,mercari,2026-09-10,4000,sold,1,3500,2026-09-20,350,750</pre>
                    </div>                    <div class="rounded border border-amber-200 bg-amber-50 p-3 leading-6">
                        <p class="font-black text-amber-950">旧CSVのヘッダも利用できます</p>
                        <p class="mt-1"><code>management_id</code> / <code>商品ID</code> / <code>管理ID</code> → <code>internal_sku</code>、<code>title</code> / <code>商品タイトル</code> / <code>タイトル</code> → <code>product_name</code>、<code>purchase_price</code> / <code>仕入れ値</code> / <code>仕入値</code> → <code>purchase_unit_cost</code> など、既存の商品専用CSVの別名は引き続き利用できます。売上を含むCSVは今回の正式ヘッダーを使用してください。</p>
                    </div>
                    <div>
                        <h4 class="font-black text-slate-900">作成例（そのまま貼り付け可）</h4>
                        <pre class="mt-2 overflow-x-auto rounded bg-slate-900 p-3 text-xs leading-5 text-white">internal_sku,product_name,condition,purchase_unit_cost,purchase_quantity,quantity_available,inventory_status,marketplace_code,listed_at,listing_price,sale_state
FD-001,黒いジャケット,used,1200,1,1,in_stock,mercari,2026-09-15,4980,stock</pre>
                    </div>
                </div>
            </details>
            <form method="POST" action="{{ route('products.imports.preview') }}" enctype="multipart/form-data" class="mt-5 space-y-5">
                @csrf
                <label class="block text-sm font-bold text-slate-800">商品CSV
                    <input required type="file" name="csv_file" accept=".csv,text/csv" class="mt-2 block w-full rounded border-slate-300 text-slate-900">
                </label>
                @error('csv_file')<p class="text-sm font-bold text-red-700">{{ $message }}</p>@enderror
                <button class="rounded bg-cyan-500 px-4 py-2 text-sm font-black text-slate-950 hover:bg-cyan-400">CSVを検証して登録確認へ</button>
            </form>
        </section>


        <section class="rounded bg-white p-6 shadow">
            <h3 class="text-lg font-black text-slate-900">FurimaDeck CSV出力</h3>
            <p class="mt-2 text-sm text-slate-600">会計用CSVとは別の、商品・販売管理用バックアップです。出力はUTF-8 BOM付きです。</p>
            <div class="mt-4 flex flex-wrap gap-3">
                <a href="{{ route('furimadeck-export.sales-spec') }}" class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-800 hover:bg-slate-50">販売CSV</a>
                <a href="{{ route('furimadeck-export.backup-spec') }}" class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-800 hover:bg-slate-50">完全バックアップCSV</a>
                <a href="{{ route('furimadeck-export.restore-spec') }}" class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-800 hover:bg-slate-50">復元用CSV</a>
                <a href="{{ route('furimadeck-export.active-listings') }}" class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-800 hover:bg-slate-50">出品中のみCSV</a>
            </div>
        </section>


        <section class="rounded bg-white p-6 shadow">
            <h3 class="text-lg font-black text-slate-900">外部サービス売上CSV取込</h3>
            <p class="mt-2 text-sm text-slate-600">ヤフオクとメルカリShopsの売上CSVを取り込みます。取込前に元ファイルを保存してください。</p>
            <div class="mt-5 grid gap-6 md:grid-cols-2">
                <form method="POST" action="{{ route('furimadeck-sales.import.yahoo-auctions-preview') }}" enctype="multipart/form-data" class="space-y-3 rounded border border-slate-200 p-4">
                    @csrf
                    <h4 class="font-black text-slate-900">ヤフオク売上CSV</h4>
                    <input required type="file" name="yahoo_csv_file" accept=".csv,text/csv" class="block w-full text-sm text-slate-900">
                    <button class="rounded bg-slate-900 px-4 py-2 text-sm font-bold text-white hover:bg-slate-700">ヤフオクCSVを取込</button>
                </form>
                <form method="POST" action="{{ route('furimadeck-sales.import.mercari-shops-preview') }}" enctype="multipart/form-data" class="space-y-3 rounded border border-slate-200 p-4">
                    @csrf
                    <h4 class="font-black text-slate-900">メルカリShops売上CSV</h4>
                    <input required type="file" name="mercari_shops_csv_file" accept=".csv,text/csv" class="block w-full text-sm text-slate-900">
                    <button class="rounded bg-slate-900 px-4 py-2 text-sm font-bold text-white hover:bg-slate-700">メルカリShops CSVを取込</button>
                </form>
            </div>
        </section>

        <section class="rounded bg-slate-50 p-6 shadow-inner">
            <h3 class="text-lg font-black text-slate-900">旧形式CSV（互換用）</h3>
            <div class="mt-4 flex flex-wrap gap-3">
                <a href="{{ route('furimadeck-export.products') }}" class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-800 hover:bg-slate-50">旧商品CSV</a>
                <a href="{{ route('furimadeck-export.products-backup') }}" class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-800 hover:bg-slate-50">旧バックアップCSV</a>
                <a href="{{ route('furimadeck-export.listings') }}" class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-800 hover:bg-slate-50">旧出品CSV</a>
            </div>
        </section>
    </div></div>
</x-app-layout>
