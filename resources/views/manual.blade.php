<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-black text-cyan-200">FurimaDeckの使い方</h2>
            <p class="mt-1 text-sm font-semibold text-cyan-100">画面を見ながら、上から順に操作できます。</p>
        </div>
    </x-slot>

    @php
        $imageLimit = (int) config('furimadeck.product_images.max_count');
        $imageSizeMb = number_format((float) config('furimadeck.product_images.max_size_kilobytes') / 1024, 1);
        $csvLimitMb = number_format((float) config('furimadeck.csv_import.max_size_kilobytes') / 1024, 1);
        $csvRowLimit = number_format((int) config('furimadeck.csv_import.max_rows'));
        $previewRows = (int) config('furimadeck.csv_import.preview_rows_per_page');
        $rankingLimit = (int) config('furimadeck.analytics.category_ranking_limit');
    @endphp

    <div class="min-h-screen bg-white py-8 text-slate-950">
        <div class="mx-auto max-w-5xl divide-y divide-slate-300 px-4 sm:px-6 lg:px-8">
            <section class="border-l-4 border-cyan-700 bg-white p-5 text-slate-900 shadow-sm" aria-labelledby="manual-start">
                <h3 id="manual-start" class="text-lg font-black">まず覚えること</h3>
                <p class="mt-2 leading-7">FurimaDeckは、商品・出品情報・売上・利益を記録して管理するサービスです。フリマサイトへ自動で出品するサービスではありません。実際の出品、購入者とのやり取り、発送は各フリマサイトで行ってください。</p>
                <p class="mt-2 leading-6">画面やボタンに「Premium」と表示される機能は、対象プランの利用が必要です。利用できる機能や契約状態は<a class="font-bold text-blue-800 underline" href="{{ route('furimadeck-billing.index') }}">契約・プラン</a>で確認できます。</p>
                <ol class="mt-3 list-inside list-decimal space-y-2 leading-7">
                    <li><a class="font-bold text-blue-800 underline" href="{{ route('products.create') }}">商品を登録</a>します。</li>
                    <li>必要なら出品情報を登録し、フリマサイトで実際に出品します。</li>
                    <li>商品が売れたら、商品一覧から「売上を記録」を押して実際の金額を記録します。</li>
                    <li>分析や会計データ連携で、登録した記録を確認します。</li>
                </ol>
                <p class="mt-3 rounded border border-amber-300 bg-amber-50 p-3 font-semibold leading-6 text-amber-950">大切な注意：画面に出る利益・会計データは、入力された内容から作る管理用の数字です。領収書などの原資料と照合してください。FurimaDeckが税額を決めたり、確定申告を提出したりするものではありません。</p>
            </section>

            <nav class="border-y border-slate-300 bg-white py-5" aria-label="マニュアル目次">
                <h3 class="font-black text-slate-900">見たい機能へ移動</h3>
                <ul class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <li><a class="text-blue-800 underline" href="#dashboard">HOME・お知らせ</a></li>
                    <li><a class="text-blue-800 underline" href="#products">商品登録・編集・画像</a></li>
                    <li><a class="text-blue-800 underline" href="#listings">出品情報</a></li>
                    <li><a class="text-blue-800 underline" href="#inventory">商品検索・在庫</a></li>
                    <li><a class="text-blue-800 underline" href="#sales">売上・販売履歴</a></li>
                    <li><a class="text-blue-800 underline" href="#suppliers">仕入先</a></li>
                    <li><a class="text-blue-800 underline" href="#csv">CSV管理</a></li>
                    <li><a class="text-blue-800 underline" href="#analytics">分析・改善候補</a></li>
                    <li><a class="text-blue-800 underline" href="#accounting">会計データ連携</a></li>
                    <li><a class="text-blue-800 underline" href="#billing">契約・プラン</a></li>
                    <li><a class="text-blue-800 underline" href="#account">アカウント・データ削除</a></li>
                    <li><a class="text-blue-800 underline" href="#other">操作履歴・アプリ追加・問い合わせ</a></li>
                    @if (auth()->user()->isAdmin())
                        <li><a class="text-blue-800 underline" href="#admin">管理者向け機能</a></li>
                    @endif
                </ul>
            </nav>

            <section id="dashboard" class="scroll-mt-6 py-6">
                <h3 class="text-xl font-black text-slate-950">1. HOME・お知らせ</h3>
                <p class="mt-2 leading-7 text-slate-800">ナビの「HOME」を押すと、月ごとの売上・利益の概要、商品や在庫に関する案内、お知らせが表示されます。</p>
                <ol class="mt-3 list-inside list-decimal space-y-2 leading-7 text-slate-800">
                    <li>月のグラフは「前の月」「次の月」で表示月を切り替えます。</li>
                    <li>売上や利益を確認するときは、先に販売日・売価・原価・手数料・送料が正しく登録されているか確認します。</li>
                    <li>登録がない項目は、推測で補われません。商品・売上を記録した後にもう一度確認してください。</li>
                    <li>お知らせの本文やリンクを開くと、運営からの案内を確認できます。</li>
                </ol>
            </section>

            <section id="products" class="scroll-mt-6 py-6">
                <h3 class="text-xl font-black text-slate-950">2. 商品を登録・編集する</h3>
                <p class="mt-2 leading-7 text-slate-800">商品情報は、商品ごとの管理番号と一緒に保存します。同じ管理番号を別の商品に使わないでください。</p>
                <ol class="mt-3 list-inside list-decimal space-y-2 leading-7 text-slate-800">
                    <li><a class="font-bold text-blue-800 underline" href="{{ route('products.create') }}">「商品を登録」</a>を開きます。</li>
                    <li>商品ID（自分で決める管理番号）、商品名、カテゴリ、商品の状態、商品説明などを入力します。画面に「必須」とある項目は必ず入力します。仕入日・仕入単価・送料・仕入先は、ここではなく会計データで登録します。</li>
                    <li>カテゴリは「大項目」から順に選びます。次の階層がある場合は選択欄が増えるので、中項目、小項目、詳細カテゴリの順で選びます。最後に選んだカテゴリが商品に保存されます。</li>
                    <li>仕入情報は商品を登録した後に、メニューの「会計データ」→「商品別仕入情報」から登録します。仕入先の候補が必要な場合は、先に<a class="font-bold text-blue-800 underline" href="{{ route('suppliers.index') }}">仕入先</a>で登録します。</li>
                    <li>商品説明には、傷・汚れ・付属品・サイズなど、後で自分が確認したい内容を記録します。</li>
                    <li>画像は任意です。1枚ずつ選ぶ、複数枚をまとめて選ぶ、フォルダから選ぶ、または枠へドラッグ＆ドロップできます。登録前の画像はドラッグして順番を変えられます。最大{{ $imageLimit }}枚、1枚{{ $imageSizeMb }}MBまでです。</li>
                    <li>最後に登録ボタンを押します。登録後は商品一覧へ戻ります。</li>
                </ol>
                <div class="mt-4 border-l-4 border-blue-700 bg-blue-50 p-4 text-blue-950">
                    <h4 class="font-black">登録済みの商品を直す</h4>
                    <p class="mt-1 leading-6">商品一覧で該当商品の「詳細・編集」を押し、商品名・説明・ジャンル・出品情報・画像などを変更して「更新する」を押します。仕入日・原価・仕入先など会計に使う情報は、会計データの「商品別仕入情報」で管理します。新しく追加する画像の順番は、保存前にドラッグして並べます。登録済み画像の順番は、画像をドラッグして置いた時点で自動保存されます。</p>
                </div>
                <p class="mt-3 text-sm leading-6 text-slate-700">Freeプランでは登録数に上限があります。登録時に上限の案内が出た場合は、<a class="font-bold text-blue-800 underline" href="{{ route('furimadeck-billing.index') }}">契約・プラン</a>画面を確認してください。</p>
            </section>

            <section id="listings" class="scroll-mt-6 py-6">
                <h3 class="text-xl font-black text-slate-950">3. 出品情報を登録する</h3>
                <p class="mt-2 leading-7 text-slate-800">出品情報は、フリマサイトへ出すための商品説明や価格などをFurimaDeck内に控えておく機能です。ここで保存しても、外部サイトへの出品は行われません。</p>
                <ol class="mt-3 list-inside list-decimal space-y-2 leading-7 text-slate-800">
                    <li><a class="font-bold text-blue-800 underline" href="{{ route('listings.create') }}">出品情報を登録</a>を開きます。出品管理はメインメニューに表示していませんが、このリンクから開けます。</li>
                    <li>登録済み商品と販売先を選びます。</li>
                    <li>出品タイトル、出品先カテゴリ、販売先での状態、説明文、出品価格を入力します。商品情報を反映するボタンを使った場合も、反映内容を必ず読み直してください。</li>
                    <li>販売先の標準手数料率が表示されます。自分の契約やキャンペーンで率が異なる場合は、実際の率に直します。</li>
                    <li>送料・配送方法・送料負担・発送元・発送日数などを、選んだ販売先の画面に合わせて入力します。</li>
                    <li>保存後、実際のフリマサイトで出品します。公開された後に、必要なら出品URLや出品IDをFurimaDeckへ記録します。</li>
                </ol>
                <p class="mt-3 rounded border border-amber-300 bg-amber-50 p-3 leading-6 text-amber-950"><strong>重要：</strong>出品状態の「下書き」「出品中」などはFurimaDeck内の記録です。実際のフリマサイトの状態と自動同期しません。外部サイトで変更・削除したときは、FurimaDeck側も必要に応じて更新してください。</p>
                <p class="mt-3">保存済みの情報は<a class="font-bold text-blue-800 underline" href="{{ route('listings.index') }}">出品情報一覧</a>で確認できます。</p>
            </section>

            <section id="inventory" class="scroll-mt-6 py-6">
                <h3 class="text-xl font-black text-slate-950">4. 商品を検索し、在庫を確認する</h3>
                <ol class="mt-3 list-inside list-decimal space-y-2 leading-7 text-slate-800">
                    <li><a class="font-bold text-blue-800 underline" href="{{ route('products.index') }}">商品一覧</a>を開きます。</li>
                    <li>商品ID・商品名・型番をキーワード欄に入力し、「検索」を押します。</li>
                    <li>ジャンル、仕入先、在庫状態を選ぶと、条件に合う商品だけを表示できます。「解除」を押すと検索条件を外します。</li>
                    <li>未販売の日数を入力するか「{{ (int) config('furimadeck.inventory.long_term_days', 30) }}日以上未販売」を押すと、出品日から指定日数以上、売上記録がなく在庫が残る商品を絞り込みます。未出品商品は対象外です。解除ボタンで元に戻せます。</li>
                    <li>商品カードには、登録されている売上合計・利益合計・最新の売却日・販売先などが表示されます。商品名やカードを押すと編集画面を開けます。</li>
                </ol>
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <div class="border border-slate-300 p-4"><h4 class="font-black text-slate-950">在庫なし</h4><p class="mt-1 leading-6 text-slate-800">今売れる在庫がない状態です。破損・廃棄などで在庫がなくなった場合も含みます。これだけでは売れたことになりません。</p></div>
                    <div class="border border-slate-300 p-4"><h4 class="font-black text-slate-950">SOLD・売却済み</h4><p class="mt-1 leading-6 text-slate-800">有効な売上記録があり、在庫が0になった商品に表示されます。売上記録がない在庫0の商品には表示されません。</p></div>
                </div>
            </section>

            <section id="sales" class="scroll-mt-6 py-6">
                <h3 class="text-xl font-black text-slate-950">5. 売上を記録し、販売履歴を管理する</h3>
                <h4 class="mt-4 font-black text-slate-900">商品が売れたとき</h4>
                <ol class="mt-2 list-inside list-decimal space-y-2 leading-7 text-slate-800">
                    <li>商品一覧で売れた商品の「売上を記録」を押します。</li>
                    <li>実際に売れた販売先、売上金額、販売送料、販売日を入力します。販売先を選ぶと標準手数料率が入り、売価に応じて手数料額が自動計算されます。率は実際の条件に合わせて調整できます。</li>
                    <li>この画面では販売の基本情報だけを登録します。原価や販売時に別途かかった費用は、保存後に会計データで登録します。</li>
                    <li>「販売を確定する」を押します。保存後は商品一覧に戻ります。</li>
                </ol>
                <h4 class="mt-5 font-black text-slate-900">仕入情報・原価・販売時の費用は会計データで管理</h4>
                <p class="mt-2 leading-6 text-slate-800">仕入の金額や販売時の追加費用など、利益計算・会計CSVに使う情報は「会計データ」の中で登録します。商品登録・販売記録の画面には会計用入力欄を置いていません。</p>
                <ol class="mt-2 list-inside list-decimal space-y-2 leading-7 text-slate-800">
                    <li>仕入日・仕入単価・仕入送料・その他仕入費・仕入先は、会計データの「商品別仕入情報」から商品を検索し、「仕入情報を編集」を開いて登録します。</li>
                    <li>梱包費・修理費・清掃費・その他費用や、それぞれの証憑・税区分・発行者・支払元は、会計データの「明細の確認・確定」で販売明細の「販売会計データを確認」を開いて登録します。</li>
                    <li>金額がある費用は、領収書や請求書の内容を確認して入力してください。証憑情報や支払元が不足・不一致の明細は、会計CSVの出力が停止します。分からない項目を推測して埋めないでください。</li>
                    <li>商品原価や販売費用を変更すると実利益の管理値にも反映します。会計CSVに使う内容は、CSVを出す前に原資料と照合してください。</li>
                </ol>
                <p class="mt-3 rounded border border-amber-300 bg-amber-50 p-3 leading-6 text-amber-950">売上記録はFurimaDeckの記録だけです。フリマサイト側の売上や発送状態を自動取得しません。販売先と金額は、実際に売れた取引に合わせてください。</p>
                <h4 class="mt-5 font-black text-slate-900">発送・キャンセル・返品</h4>
                <p class="mt-2 leading-7 text-slate-800">販売履歴は<a class="font-bold text-blue-800 underline" href="{{ route('furimadeck-sales.index') }}">販売履歴</a>から開けます。通常メニューには表示していません。</p>
                <ol class="mt-2 list-inside list-decimal space-y-2 leading-7 text-slate-800">
                    <li>販売状況に合わせて、発送待ち・発送済み・取引完了などを更新します。</li>
                    <li>キャンセル時は対象取引を選び、理由を記録してキャンセルします。戻る在庫数を確認してください。</li>
                    <li>返品時は返品理由・返金額・返送送料などを、実際の取引内容に合わせて記録します。在庫へ戻すかどうかも実態に合わせます。</li>
                    <li>キャンセル・返品は、売上や利益、SOLD表示、会計データに影響します。会計ソフトへ既に登録した取引は、会計ソフト側も別途確認してください。</li>
                </ol>
            </section>

            <section id="suppliers" class="scroll-mt-6 py-6">
                <h3 class="text-xl font-black text-slate-950">6. 仕入先を登録する</h3>
                <ol class="mt-3 list-inside list-decimal space-y-2 leading-7 text-slate-800">
                    <li><a class="font-bold text-blue-800 underline" href="{{ route('suppliers.index') }}">仕入先</a>を開きます。</li>
                    <li>仕入先名と、必要なら種別・メモを入力して登録します。</li>
                    <li>商品の仕入先は、会計データの「商品別仕入情報」から商品ごとに選択します。</li>
                    <li>商品一覧の仕入先欄は、会計データで登録した仕入先を使って商品を絞り込めます。</li>
                </ol>
                <p class="mt-2 leading-6 text-slate-700">仕入先を消せない場合、その仕入先を使っている商品が残っていないか確認してください。</p>
            </section>

            <section id="csv" class="scroll-mt-6 py-6">
                <h3 class="text-xl font-black text-slate-950">7. CSVを出力・取り込む</h3>
                <p class="mt-2 leading-7 text-slate-800">CSVは表計算ソフトで開ける一覧ファイルです。取り込み確定前に、画面のプレビューとエラーを必ず確認してください。</p>
                <h4 class="mt-4 font-black text-slate-900">商品CSVの取り込み</h4>
                <p class="mt-2 leading-6 text-slate-800">列名が分からないときは、CSV管理画面の「CSVヘッダの説明を開く」を押してください。最小構成の例は <code>internal_sku,product_name</code> です。商品IDと商品名の列名は必須です。売上履歴も一緒に取り込む場合は、画面にある売上用の列説明を読み、販売先・日付・金額が揃っていることを確認してください。</p>
                <ol class="mt-2 list-inside list-decimal space-y-2 leading-7 text-slate-800">
                    <li><a class="font-bold text-blue-800 underline" href="{{ route('products.imports.create') }}">CSV管理</a>を開きます。CSV機能はPremium対象です。</li>
                    <li>商品CSVファイルを選び、画面の説明に沿って文字コード・列名を確認します。通常のExcel CSVは文字コードが異なる場合があるため、文字化けがあれば別の文字コードを選び直します。</li>
                    <li>「プレビュー」を押します。プレビューは1ページ{{ $previewRows }}件ずつ表示されます。ページ番号を押して、先頭から最後まで確認します。</li>
                    <li>エラー行、商品IDの重複、商品名・価格・在庫などの値を確認します。エラーがあるファイルはそのまま確定せず、元CSVを直して再度プレビューします。</li>
                    <li>問題ないと確認できたら、確認画面で取り込みを確定します。ボタンを押すまでは商品は登録されません。</li>
                </ol>
                <p class="mt-3 leading-6 text-slate-800">現在の標準上限は、ファイル{{ $csvLimitMb }}MB、{{ $csvRowLimit }}行です。実際の上限は運用設定で変わることがあります。商品IDが既にある場合の扱いは画面のプレビューに表示されます。通常取込とバックアップ復元は別機能なので、目的に合う方を選んでください。</p>
                <h4 class="mt-5 font-black text-slate-900">CSVの出力・バックアップ・復元</h4>
                <p class="mt-2 leading-6 text-slate-800">CSV管理画面には、販売CSV・完全バックアップCSV・復元用CSV・出品中のみCSVの説明リンクがあります。何の列が出るか、復元時に何が登録されるかは、対象の説明を開いてからダウンロードしてください。</p>
                <ol class="mt-2 list-inside list-decimal space-y-2 leading-7 text-slate-800">
                    <li>CSV管理から、商品・出品・売上の一覧CSVやバックアップをダウンロードします。</li>
                    <li>バックアップCSVは、復元画面でプレビューし、内容と対象アカウントを確認してから確定します。</li>
                    <li>復元は既存の商品を無条件で上書きする機能ではありません。SKUの重複やスキップ結果をプレビューで確認してください。</li>
                    <li>Yahoo!オークション等の外部売上CSVを使う場合は、画面に表示された対応形式を使います。対象外の行、キャンセル、重複行の扱いはプレビューで確認します。</li>
                </ol>
                <p class="mt-3 leading-6 text-slate-700">外部サービスの売上CSV取込に対応しているのは、画面に表示されるYahoo!オークションとメルカリShopsです。メルカリ、ラクマ、Yahoo!フリマの一般販売履歴を自動取得する機能ではありません。</p>
                <p class="mt-3 rounded border border-red-300 bg-red-50 p-3 leading-6 text-red-950"><strong>注意：</strong>データ全削除や月単位削除はCSV取込とは別の操作です。必要なデータは事前にバックアップし、削除対象を確認してから実行してください。</p>
            </section>

            <section id="analytics" class="scroll-mt-6 py-6">
                <h3 class="text-xl font-black text-slate-950">8. 分析と改善候補を見る</h3>
                <p class="mt-2 leading-7 text-slate-800">分析は、FurimaDeckに登録された商品・売上だけを使います。フリマ全体の相場データを自動で集める機能ではありません。売上を登録していない期間は、売上・利益が空または0件になることがあります。</p>
                <ul class="mt-3 list-inside list-disc space-y-2 leading-7 text-slate-800">
                    <li><a class="font-bold text-blue-800 underline" href="{{ route('furimadeck-analytics.index') }}">売上分析</a>：期間の売上、販売手数料、利益、利益率などを確認します。</li>
                    <li><a class="font-bold text-blue-800 underline" href="{{ route('furimadeck-analytics.categories') }}">ジャンル別分析</a>：大・中・小ジャンルごとの売上・実利益を比較します。最大{{ $rankingLimit }}件までの表示で、データが少なければ表示件数も少なくなります。</li>
                    <li><a class="font-bold text-blue-800 underline" href="{{ route('furimadeck-analytics.cross') }}">クロス分析</a>：ジャンルと販売先の組み合わせごとに実績を確認します。</li>
                    <li><a class="font-bold text-blue-800 underline" href="{{ route('furimadeck-analytics.advanced') }}">高度分析</a>：日付範囲や販売状況を変えて、登録データを詳しく見ます。「データ不足」は計算に必要な売上記録などが足りないという意味です。</li>
                    <li><a class="font-bold text-blue-800 underline" href="{{ route('furimadeck-analytics.suitability') }}">販売先適性</a>：登録済みの自分の販売履歴を販売先ごとに比較します。市場全体の人気を示すものではありません。</li>
                    <li><a class="font-bold text-blue-800 underline" href="{{ route('furimadeck-improvement.index') }}">販売改善</a>：滞留在庫や価格・再出品などの確認候補を表示します。候補は提案であり、価格変更や再出品を自動実行しません。商品名を選ぶと該当商品の編集へ進めます。</li>
                </ul>
                <p class="mt-3 leading-6 text-slate-700">数値が思ったものと違う場合は、対象期間、売上日、販売先、売価、原価、手数料、送料、キャンセル・返品状態を商品・販売履歴で確認してください。</p>
            </section>

            <section id="accounting" class="scroll-mt-6 py-6">
                <h3 class="text-xl font-black text-slate-950">9. 会計ソフトへデータを渡す</h3>
                <p class="mt-2 rounded border border-amber-300 bg-amber-50 p-3 leading-6 text-amber-950"><strong>FurimaDeckで確定申告はしません。</strong>販売・仕入・費用のデータを確認し、CSVで会計サービスへ登録することが目的です。確定申告書の作成・提出は会計サービス側で行います。仕訳や税区分は領収書などの原資料と照合してください。</p>
                <h4 class="mt-4 font-black text-slate-900">基本の流れ</h4>
                <ol class="mt-2 list-inside list-decimal space-y-2 leading-7 text-slate-800">
                    <li><a class="font-bold text-blue-800 underline" href="{{ route('furimadeck-accounting.index') }}">会計データ連携</a>を開き、対象年または開始日・終了日を選びます。</li>
                    <li><a class="font-bold text-blue-800 underline" href="{{ route('furimadeck-accounting.index', ['section' => 'overview']) }}">入力状況</a>を見ます。商品別の仕入情報は<a class="font-bold text-blue-800 underline" href="{{ route('furimadeck-accounting.index', ['section' => 'purchase-info']) }}">商品別仕入情報</a>で、販売日・売価・販売先は販売記録で整えます。</li>
                    <li>「確認用データを更新」を押します。これはFurimaDeck内の確認データを作り直す操作で、会計ソフトへの登録ではありません。</li>
                    <li><a class="font-bold text-blue-800 underline" href="{{ route('furimadeck-accounting.index', ['section' => 'review']) }}">明細の確認・確定</a>で取引ごとに内容と原資料を照合します。「確定」はFurimaDeck内の状態で、確定申告の完了を意味しません。</li>
                    <li><a class="font-bold text-blue-800 underline" href="{{ route('furimadeck-accounting.index', ['section' => 'csv']) }}">CSVで登録</a>から会計ソフト用CSVを作成し、会計サービス側で手動取り込みします。FurimaDeck 1.0では会計APIによる仕訳送信・仕訳読み戻しは行いません。プレビューで日付・金額・科目・税区分を確認してから登録してください。</li>
                    <li>登録後、会計ソフト側に仕訳ができたことを確認してから、FurimaDeckに取込済みを記録します。</li>
                </ol>
                <h4 class="mt-5 font-black text-slate-900">freee・Money Forwardとの接続</h4>
                <ol class="mt-2 list-inside list-decimal space-y-2 leading-7 text-slate-800">
                    <li><a class="font-bold text-blue-800 underline" href="{{ route('furimadeck-accounting.index', ['section' => 'connections']) }}">会計ソフト設定</a>で利用するサービスを接続し、公式画面で許可します。</li>
                    <li>接続後、設定画面で事業所・会計年度・勘定科目などを選び、保存します。設定名だけで税務上の正しさは判断できません。</li>
                    <li>適格請求書発行事業者ではない相手先から2023/10/1以後に課税仕入れをした場合は、先に<a class="font-bold text-blue-800 underline" href="{{ route('furimadeck-accounting.purchase-caps.index') }}">仕入先の確認・年間上限</a>で証憑の相手先名、実際の課税期間、FurimaDeck外の累計を登録します。商品や販売費用の証憑にも同じ相手先記録を指定してください。確認が完了しない明細はCSV出力されません。</li>
                    <li>上限は取引日ではなく課税期間の開始日で判定します。2024/10/1以後に始まる期間は相手先ごと税込10億円、2026/10/1以後に始まる期間は税込1億円です。対象仕入れの摘要には仕入先名と控除区分が出ます。期間や累計を判断できない場合は推測で登録せず、税務資料を確認してください。制度上の適用判断は税務署の相談窓口、会計ソフトの画面・取込動作は会計サービスのサポートへ確認してください。</li>
                    <li>2026/9/1以後に古物商等特例を使う場合は、仕入商品が特例対象から除外された「特定金属くず」ではないことも確認します。該当する可能性がある、または判断できない場合は特例を選ばず、税務署の相談窓口へ確認してください。</li>
                    <li>「確認用データを更新」は、FurimaDeck内の会計用確認データを作り直す操作です。会計ソフトへの登録は行いません。</li>
                    <li>freee・Money Forwardの接続は、CSV用の勘定科目や税区分候補を取得するために使います。仕訳の登録は会計サービス側で行います。</li>
                    <li>会計ソフトへの登録はCSVで行います。FurimaDeckの「CSV取込状況」は、CSVを登録した後の手動記録と過去の状態を確認する画面です。</li>
                    <li>freee CSVは、freeeの「取引入力」→「振替伝票」→「インポート」から取り込みます。このCSVには税額列がないため、出力対象の金額がすべて税込と確認できる場合に限り、取込設定で「全ての金額が内税（税込）※税額自動計算あり」を選びます。税抜金額が混在する、または税込か判断できない場合は登録しないでください。列の割当画面では「伝票番号」がfreeeの伝票番号（管理番号）に割り当てられていることを確認します。同じ伝票番号の行は1組の仕訳としてまとまります。プレビューでは日付・金額・勘定科目・税区分と計算された税額を確認し、想定した件数と違う場合は再取込せず履歴を確認してください。</li>
                    <li>Money Forward CSVは「会計帳簿」→「仕訳帳」→「インポート」→「仕訳帳」から取り込み、「その他（UTF-8）」を選びます。FurimaDeckは確認できない税区分・インボイス区分を推測して出力しません。プレビューで日付・金額・勘定科目・区分を確認し、画面と原資料が一致しない場合は登録を中止してください。</li>
                    <li>Money Forwardの「帳簿管理」→「仕訳入力の期間制限」で制限日付が設定されている場合、CSVの取引日が制限日付当日またはそれ以前だと取り込めません。CSVの取引日が制限日付より後か確認してください。「インポート可能な仕訳がありません」と表示された場合は、最終画面で各行が取込対象になっているか、赤いエラー表示がないかも確認します。この表示だけで原因を制限日付と決めつけないでください。制限日付を変えると仕訳を登録・編集・削除できる期間も変わるため、影響を確認せずに変更しないでください。</li>
                    <li>Money Forwardサポート回答（2026/10/26）では、会計設定が「免税事業者」の場合、仕訳に税区分・インボイス欄がなく、CSVの該当列を空欄または省略しても仕訳として取り込めます。ただし、空欄は税区分として登録されず、仕訳メモにも転記されません。FurimaDeckは免税確認済みの期間では該当欄を空欄にして出力します。簡易課税・原則課税の場合、税区分欄が空欄または省略なら勘定科目に設定された税区分が適用されるとの回答です。課税・免税状態が分からない場合は、Money Forwardの画面表示だけで判断せず「状態未確認」のままCSV出力を止めてください。インボイス欄を利用できる仕訳帳CSVの空欄は「適格」として扱われると公式ガイドで案内されています。免税設定で欄自体がない場合とは別の仕様です。詳しくは<a class="font-bold text-blue-800 underline" href="https://biz.moneyforward.com/support/tax-return/guide/import-books/ib01.html" target="_blank" rel="noopener noreferrer">Money Forward公式ガイド</a>を確認してください。</li>
                    <li>Money Forwardの取込画面では、列の割当、値の対応付け、登録可能な仕訳プレビューの順に確認します。最終プレビューで日付・借貸・金額・勘定科目・税区分・摘要を見直し、登録後は仕訳帳にも反映を確認してください。プレビューの確認だけでは会計ソフトへ登録されません。</li>
                    <li>同じ会計CSVを再度取り込むと、同じ取引の仕訳が重複する可能性があります。再取込前に会計ソフト側で登録済みか確認してください。</li>
                    <li>CSVを出力した後、ファイルを受け取れたか・登録できたか分からない場合は、すぐ再出力しないでください。CSV出力時と同じ会計事業者の仕訳帳と取込履歴を確認します。登録済みなら「CSV取込済みを記録」を押します。未登録と確認できた場合だけ「仕訳なしを記録」を押すと再出力できます。登録有無を判断できない場合は操作せず、会計サービス側を確認してください。</li>
                </ol>
                <h4 class="mt-5 font-black text-slate-900">会計CSVで出力できる取引</h4>
                <ul class="mt-2 list-inside list-disc space-y-2 leading-7 text-slate-800">
                    <li>共通: FurimaDeck内で確認・確定した明細だけが対象です。会計サービスの勘定科目・税区分と証憑を照合できない場合、CSVは作成されません。販売送料が記録された明細は、支払方法を会計上どう扱うか確認できないため、どちらのCSVでも出力しません。出力後の取込結果は会計サービス側で確認し、確認後にだけFurimaDeckへ取込済みを記録します。</li>
                    <li>freee: 対応するのは確認済みの売上仕訳です。原価は仕入・在庫との照合が済んだ場合だけ売上CSVに含まれます。販売手数料を含む明細は実取込の検証が未完了のため出力しません。仕入CSVと、販売送料・別途支払った販売費用を含む販売CSVもFurimaDeck 1.0では出力対象外です。該当する仕入や費用はfreee側で別途登録してください。</li>
                    <li>Money Forward: 取引日の課税事業者状態と、勘定科目・税区分・インボイス区分を確認できた売上仕訳が対象です。免税状態または課税状態が未確認の期間は出力できません。販売手数料を含む明細は実取込の検証が未完了のため出力しません。FurimaDeck 1.0では仕入CSVに対応していません。また、販売送料や別口座などから別途支払った梱包費・修理費などを含む明細も出力対象外です。</li>
                    <li>出品取消・返品済み、取引の確認待ち、会計サービスへの登録結果が未確定の明細は出力できません。停止理由が表示された場合は、示された仕入情報・証憑・設定を確認してください。会計ソフトの取込仕様は各サービスのサポートへ、本人の課税・免税状態などの税務判断は税務署の相談窓口へ確認してください。確認できるまでは推測で入力せず、CSV出力を止めてください。</li>
                </ul>
                <h4 class="mt-5 font-black text-slate-900">登録後の照合</h4>
                <p class="mt-2 leading-6">CSVを取り込んだ後、会計サービス側の仕訳帳で日付・借方と貸方・金額・勘定科目・税区分を確認します。FurimaDeckの取込済み記録は利用者が登録結果を確認したことを残すもので、APIによる自動読み戻しや完全一致確認ではありません。内容が違う場合は取込済みにせず、会計サービス側の登録内容を確認してください。</p>
                <h4 class="mt-5 font-black text-slate-900">過去の仕入仕訳を商品ごとに手動照合する</h4>
                <p class="mt-2 rounded border border-amber-300 bg-amber-50 p-3 leading-6 text-amber-950">この操作は会計ソフトに仕訳を作りません。会計ソフトへ既に登録されている仕入仕訳の証跡をFurimaDeckに記録するだけです。仕訳が見つからない、別の仕訳か判断できない、金額や税区分が分からない場合は、推測で入力せず中止してください。</p>
                <ol class="mt-2 list-inside list-decimal space-y-2 leading-7 text-slate-800">
                    <li>先に対象の会計ソフトを開き、該当商品の仕入れが記録された仕訳を確認します。仕訳のID、取引日、借方・貸方の勘定科目と合計を控えます。</li>
                    <li><a class="font-bold text-blue-800 underline" href="{{ route('furimadeck-accounting.index', ['section' => 'review']) }}">会計データ</a>の「明細の確認・確定」を開き、「既存商品の仕入情報・照合待ち」から対象商品の「会計データを確認・照合」を選びます。</li>
                    <li>仕訳が登録されている会計サービスの欄を選び、仕訳IDと会計サービス側の日付、借方・貸方の勘定科目を転記します。商品に仕入日が設定されている場合、仕訳日が完全一致しなければ照合できません。</li>
                    <li>借方合計と貸方合計には、この商品の金額ではなく仕訳全体の税込合計を入力します。借方と貸方は同額である必要があります。商品への配分額は、商品の仕入金額から自動計算されます。</li>
                    <li>画面に金額が表示された費目ごとに、領収書・請求書を見ながら証憑の種類、税区分、発行者区分、会計ソフト画面に表示された税区分を入力します。さらに仕訳帳の勘定科目を見て、「在庫として計上」か「仕入時に費用計上」かを選びます。在庫計上分だけが売却時の原価として送られ、仕入時に費用計上済みの分は重ねて送られません。勘定科目から判断できない場合は選択せず、照合を止めてください。仕入先別の年間上限確認が必要な場合は、先に「仕入先の確認・年間上限」を登録し、証憑にその確認記録を指定します。</li>
                    <li>複数商品が1つの仕入仕訳に含まれる場合は、「会計データ」の「明細の確認・確定」から各商品の「会計データを確認・照合」を開き、同じ会計サービス・仕訳ID・取引日・仕訳全体の借方／貸方合計を使って商品ごとの証憑と配分額を登録します。例：仕訳全体が10,000円で、商品Aが6,000円、商品Bが4,000円なら、2商品の合計が10,000円になった時点で配分完了です。</li>
                    <li>画面に「配分待ち」と表示されている間は、関連商品の会計CSV出力が停止します。仕訳全体に商品以外の金額が含まれる、または配分合計が一致しない場合、その差額を無理に商品へ割り当てず、登録を止めて仕訳を確認してください。</li>
                    <li>最後に確認欄を読み、証憑と仕訳が一致すると確認できた場合だけ「手動照合の証跡を保存」を押します。保存後も状態は「手動照合」であり、会計サービスとの自動照合済みを意味しません。</li>
                </ol>
                <p class="mt-3 leading-6 text-slate-700">既に会計ソフトへ登録した仕訳を、FurimaDeckが自動で削除・訂正することはありません。取消・返品・金額変更があった場合は、会計ソフト側も照合してください。</p>
                <h4 class="mt-5 font-black text-slate-900">取消・返品・金額変更があったとき</h4>
                <ol class="mt-2 list-inside list-decimal space-y-2 leading-7 text-slate-800">
                    <li>「会計データ」の「明細の確認・確定」で対象の販売記録と、freee・Money ForwardそれぞれのCSV取込記録を確認します。過去の登録結果が確認できない明細はCSVを再出力せず、会計ソフト側の仕訳帳と照合してください。</li>
                    <li>会計ソフトの仕訳帳で元の販売日・金額・管理IDなどを使って検索します。見つからない場合も、取消・返品済みの売上は再送しないでください。</li>
                    <li>元仕訳が見つかったら削除・上書きせず、実際の返金額、販売手数料、返送送料などを取引履歴や明細で確認します。</li>
                    <li>訂正仕訳が必要な場合は、確認した実額に基づいて会計ソフト側で処理します。「照合用CSV」は確認記録であり、会計ソフトへ取り込む仕訳ではありません。金額や税区分の税務判断に迷う場合は登録を止め、税務署の相談窓口へ確認してください。会計ソフトの画面や取込方法は、各会計サービスのサポートへ確認してください。</li>
                </ol>
            </section>

            <section id="billing" class="scroll-mt-6 py-6">
                <h3 class="text-xl font-black text-slate-950">10. 契約・プランを確認する</h3>
                <ol class="mt-3 list-inside list-decimal space-y-2 leading-7 text-slate-800">
                    <li><a class="font-bold text-blue-800 underline" href="{{ route('furimadeck-billing.index') }}">契約・解約</a>を開き、現在のプラン・試用期間・次回請求などの表示を確認します。</li>
                    <li>有料機能を使う場合は、画面に表示された条件と金額を確認してから申込ボタンを押します。</li>
                    <li>支払い後、契約状態がすぐに変わらない場合は、画面を再読み込みして少し待ちます。Webhook反映を待つ案内が出ている間は、同じ契約操作を何度も繰り返さないでください。</li>
                    <li>解約・支払方法などは、画面にあるStripe契約管理ボタンから確認します。最終的な契約状態と請求日はStripe側の表示も確認してください。</li>
                </ol>
            </section>

            <section id="account" class="scroll-mt-6 py-6">
                <h3 class="text-xl font-black text-slate-950">11. アカウント設定とデータ削除</h3>
                <p class="mt-2 leading-7 text-slate-800"><a class="font-bold text-blue-800 underline" href="{{ route('furimadeck-account.edit') }}">アカウント設定</a>では、表示名・メールアドレス・パスワードを変更できます。メールアドレス変更後は、画面の指示に従って認証をやり直してください。</p>
                <h4 class="mt-4 font-black text-slate-900">削除操作の違い</h4>
                <ul class="mt-2 list-inside list-disc space-y-2 leading-7 text-slate-800">
                    <li><strong>期間（月）の削除：</strong>指定した月の売上関連データを削除します。商品・出品情報・アカウントは残ります。</li>
                    <li><strong>FurimaDeckデータの全削除：</strong>商品・画像・出品・販売・CSV取込履歴・会計データなど、このアカウントの業務データを削除します。アカウントや契約は残ります。</li>
                    <li><strong>アカウント削除：</strong>ログインアカウント自体を削除する操作です。契約状態などの条件により実行できないことがあります。</li>
                </ul>
                <p class="mt-3 rounded border border-red-300 bg-red-50 p-3 leading-6 text-red-950">削除は取り消せません。操作前に対象月・対象データを読み直し、必要なCSVバックアップを保存してください。画面に確認欄や現在のパスワード入力がある場合は、指示に従ってください。</p>
            </section>

            <section id="other" class="scroll-mt-6 py-6">
                <h3 class="text-xl font-black text-slate-950">12. 操作履歴・アプリ追加・問い合わせ</h3>
                <ul class="mt-3 list-inside list-disc space-y-2 leading-7 text-slate-800">
                    <li>新しく使い始めるときは、ログイン画面の「新規登録」から登録します。認証メールが届いたら、メール内のリンクを開いて認証を完了してください。認証前はログイン後に認証案内が表示されます。</li>
                    <li>パスワードを忘れた場合は、ログイン画面の「パスワードを忘れた方」から登録メールアドレスを送信し、届いたメールのリンクから新しいパスワードを設定します。</li>
                    <li>Googleログインを利用する場合は、ログイン画面のGoogleボタンを押してGoogleの確認画面を進めます。ボタンが表示されない場合は、その環境でGoogleログインが利用できる設定になっていません。</li>
                    <li><a class="font-bold text-blue-800 underline" href="{{ route('furimadeck-activity.index') }}">操作履歴</a>では、自分の操作名と日時を確認できます。商品本文などの全内容を記録する画面ではありません。</li>
                    <li>スマートフォンでアプリとして追加する場合は、ブラウザーの共有・メニューから「ホーム画面に追加」を選びます。表示されない場合は、対応ブラウザーでFurimaDeckを開いてください。</li>
                    <li>解決しない場合は<a class="font-bold text-blue-800 underline" href="{{ route('legal.contact') }}">お問い合わせ</a>を開き、困っている画面、押した操作、表示されたエラーを記載します。パスワードやAPIキーは送らないでください。</li>
                    <li><a class="font-bold text-blue-800 underline" href="{{ route('legal.faq') }}">よくある質問</a>、<a class="font-bold text-blue-800 underline" href="{{ route('legal.privacy') }}">プライバシーポリシー</a>、<a class="font-bold text-blue-800 underline" href="{{ route('legal.terms') }}">利用規約</a>も確認できます。</li>
                </ul>
            </section>

            @if (auth()->user()->isAdmin())
                <section id="admin" class="scroll-mt-6 border-l-4 border-red-700 bg-red-50 p-5 text-slate-950">
                    <h3 class="text-xl font-black">管理者向け機能</h3>
                    <p class="mt-2 leading-7">以下は管理者だけが使う運用画面です。利用者全体や他の利用者に影響する操作があるため、対象と内容を確認してから実行してください。</p>
                    <ul class="mt-3 list-inside list-disc space-y-2 leading-7">
                        <li><a class="font-bold text-blue-800 underline" href="{{ route('admin.maintenance.index') }}">メンテナンス・お知らせ</a>：メンテナンス状態や利用者向けのお知らせを管理します。</li>
                        <li><a class="font-bold text-blue-800 underline" href="{{ route('admin.users.index') }}">ユーザー管理</a>：登録ユーザーを確認します。削除は本人データ・契約への影響を確認してから行ってください。</li>
                        <li><a class="font-bold text-blue-800 underline" href="{{ route('admin.bulk-mail.index') }}">一括メール</a>：複数の利用者へメールを送る機能です。送信後は取り消せません。宛先・本文・対象を送信前に確認してください。</li>
                        <li><a class="font-bold text-blue-800 underline" href="{{ route('admin.growth.index') }}">運営状況</a>：問い合わせなどを確認・処理します。</li>
                    </ul>
                </section>
            @endif

            <div class="flex flex-wrap gap-3 pb-8">
                <a href="{{ route('furimadeck-dashboard') }}" class="inline-flex items-center justify-center border border-slate-700 bg-slate-900 px-4 py-2 font-bold text-white hover:bg-slate-800">HOMEへ戻る</a>
                <a href="{{ route('products.index') }}" class="inline-flex items-center justify-center border border-blue-800 bg-blue-800 px-4 py-2 font-bold text-white hover:bg-blue-900">商品一覧を開く</a>
                <a href="#manual-start" class="inline-flex items-center justify-center border border-slate-500 bg-white px-4 py-2 font-bold text-slate-900 hover:bg-slate-100">ページの先頭へ</a>
            </div>
        </div>
    </div>
</x-app-layout>
