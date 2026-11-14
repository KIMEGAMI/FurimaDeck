# FurimaDeck 1.0 - 2026年10月リリース Codex実装仕様書

> **旧計画書。現行1.0の実装判断には使用しないこと。** 後続のユーザー承認済み決定は `docs/FurimaDeck_1.0_FINAL_SPEC_2026-09-19.md` と `docs/FurimaDeck_1.0_CSV_FINAL_SPEC_2026-09-19.md` が正とする。商品IDは現物1点、会計はfreee/Money ForwardのCSV出力とOAuth読取のみ。API仕訳・取引の作成/更新/削除/送信/同期は1.1以降、弥生は1.0対象外。以下に残る旧API送信・複数数量・弥生記載で1.0を拡張しない。

- 文書種別: Codex実装・リリース仕様書
- 対象リポジトリ: `KIMEGAMI/FurimaDeck`
- 本番URL: `https://furimadeck.kimegami.jp`
- 目標: 2026年10月中の正式リリース
- 作成日: 2026-09-16
- 料金: Premium 月額980円
- 無料期間: 7日

## 1. 最重要方針

FurimaDeckはこれ以上、リリース前の機能追加を無制限に行わない。

本仕様に記載した機能をFurimaDeck 1.0のスコープとして凍結し、実装・修正・テスト・GO/NO-GO判定を優先する。

目的は「完璧なSaaSを作り続けること」ではなく、「実ユーザーが安全に登録、商品管理、売上管理、会計連携、課金まで行えるFurimaDeck 1.0を公開すること」である。

新しいアイデアは原則1.1以降のバックログへ送る。

## 2. 製品目的・対象ユーザー

FurimaDeckは、メルカリ、Yahoo!オークション、Yahoo!フリマ、ラクマ等で継続販売する個人・副業セラーを主要対象とする販売管理SaaSである。

主な価値:

- 商品/在庫を一元管理する。
- 出品先を管理する。
- SOLD・売上・利益を管理する。
- 販売手数料・送料を含めた実利益を確認する。
- CSVでバックアップ・復元・取込/出力を行う。
- ジャンル/出品先別に売上・利益を分析する。
- 確定申告/会計ソフトへ渡すデータを作る。

## 3. 現行リポジトリで確認できた事項

現行README/Routesでは以下が確認できる。新規実装前に既存コードを必ず調査すること。

### 新FurimaDeck側

- Googleログイン
- FurimaDeck Dashboard
- FurimaDeck Billing
- Account
- Activity
- Product CRUD
- Listing CRUD
- Supplier管理
- Product CSV Import preview/commit
- Product / Listing / Sales export
- Sale作成・キャンセル・返品・ステータス更新
- Stripe Webhook (`/furimadeck/stripe/webhook`)

### 旧FURUPRO互換側に残っている機能

- FurimaDeck形式CSV取込
- Yahoo!オークションCSV取込
- メルカリShops CSV取込
- 売上CSV
- 全商品バックアップCSV
- 復元用CSV
- 出品中CSV
- Category Sales
- 旧Stripe/Billing

重要:

旧側に実装が存在する機能は、同じものをゼロから重複開発しない。安全性・データモデル互換性を確認したうえで、新FurimaDeckのController/Service/Route/Viewへ移植・再利用する。

旧FURUPROルートを無計画に削除しない。新FurimaDeckへの移植・テスト完了後に整理する。

## 4. FurimaDeck 1.0 必須スコープ

### 4.1 コア商品・在庫管理

既存実装を維持し、回帰テストする。

- 商品登録
- 商品編集
- 商品削除
- 商品一覧
- 商品検索
- 複数画像
- 画像並び替え
- 出品先管理
- 販売価格
- 仕入価格
- 手数料
- 送料
- その他費用
- SOLD / 販売状態
- 実利益
- ユーザーごとの完全なデータ分離

既存仕様にない項目を勝手に増やさない。

## 5. 売上・利益解析

FURUPROに存在した最低限の解析をFurimaDeck 1.0へ復旧・統合する。

### 5.1 基本解析

最低限表示するもの:

- 対象期間売上
- SOLD件数
- 仕入合計
- 販売手数料合計
- 送料合計
- その他費用合計
- 実利益合計
- 月別売上
- 月別実利益
- 出品先別売上
- 出品先別SOLD件数
- 出品先別実利益
- ジャンル別売上
- ジャンル別SOLD件数
- ジャンル別実利益

### 5.2 ジャンル x 出品先 クロス分析

別ページとして実装する。

目的:

「どの大ジャンルが、どの出品先で売上/利益を出しているか」を比較する。

#### 行

- 大ジャンル
- 未設定も必ず集計

例:

- アウター
- トップス
- シューズ
- ボトムス
- バッグ
- アクセサリー
- その他
- 未設定

カテゴリ名はDB実データに従い、固定値のみで実装しない。

#### 列

- ヤフオク
- メルカリ
- ラクマ
- Yahooフリマ
- その他
- 合計

#### 各セル

- 売上金額
- SOLD件数
- 実利益

#### ルール

- DB実データから動的集計する。
- ログインユーザーのデータだけを対象にする。
- キャンセル/返品等の扱いはSale状態仕様と一致させる。
- 右端にジャンル単位合計を表示する。
- 最下部の全体合計は必要なら表示してよい。
- 期間フィルタを既存解析と共通化する。

## 6. CSV管理 - FurimaDeck内部データ用

会計/確定申告CSVとは完全に別機能として管理する。

ページ名称例: `CSV管理`

目的:

- FurimaDeck内のデータ移行
- バックアップ
- 復元
- 外部販売CSVからFurimaDeckへの取込

### 6.1 CSV出力

以下を提供する。

1. 売上CSV
2. 全商品バックアップCSV
3. 出品中のみCSV
4. 復元用CSV

### 6.2 全商品バックアップCSV

- 原則全期間/全商品。
- 確定申告用の期間フィルタを強制しない。
- 商品復旧に必要な項目を出力する。

### 6.3 復元用CSV

目的:

- 商品全削除前
- CSV一括取込前
- 端末変更前
- 大規模整理前
- 誤操作リスクのある作業前

同一ユーザー内ですでに同じ `management_id` の商品がある場合はスキップし、未登録商品のみ復元する。

### 6.4 FurimaDeck形式CSV取込

- 1行目をヘッダーとする。
- 最低必須列: `management_id`, `title`
- 文字コード差異を考慮する（既存実装を調査。READMEにはUTF-8/Shift-JIS対応の記載あり）。
- Preview → Commit方式を優先する。
- 行単位エラーを表示し、正常行まで全破棄しない設計を検討する。
- 同一ユーザー内管理ID重複を安全に処理する。

### 6.5 Yahoo!オークション売上CSV取込

旧FURUPRO仕様を調査・移植する。

条件:

- 状態が「売上確定」
- 商品IDが存在する
- 対象行のみSOLD商品として取り込む
- FurimaDeck共通項目へ変換する
- 既存商品の重複/更新ルールをテストで固定する

### 6.6 メルカリShops CSV取込

旧FURUPRO仕様を調査・移植する。

条件:

- キャンセル日が空
- 売上（税込）が存在する
- 注文番号 + 明細番号を管理IDとして扱う
- SOLD商品として取り込む

## 7. 確定申告・会計連携 - 別ページ

CSV管理とは別ページ・別責務とする。

ページ名称: `確定申告・会計連携`

FurimaDeck自身が確定申告書を作成したり、e-Taxへ直接申告したりしない。

FurimaDeckの責任範囲:

1. 販売/費用データを会計連携用の共通データへ変換。
2. ユーザーに対象期間と合計値を確認させる。
3. 会計ソフト向けCSVを出力する。
4. 対応可能な会計ソフトへOAuth/APIで送信する。

申告書作成・税額計算・e-Tax送信は会計ソフト側の責任範囲とする。

## 8. 会計対象期間

### 8.1 年度プリセット

個人向け標準としてカレンダー年を扱う。

例:

- 2026年分 -> 2026/01/01 ～ 2026/12/31
- 2025年分 -> 2025/01/01 ～ 2025/12/31

### 8.2 任意期間

開始日/終了日を手入力できる。

用途:

- 月次
- 四半期
- 上半期/下半期
- 税理士への部分提出
- 会計ソフトへの部分インポート

### 8.3 期間境界

日時のタイムゾーンを明示し、対象日の0:00/23:59相当で前後年のデータが混入しないようにする。

## 9. 会計連携画面

対象期間選択後、少なくとも以下を表示する。

- 売上合計
- 仕入/原価関連合計（会計上の扱いは後述の公式仕様優先）
- 販売手数料合計
- 送料合計
- その他経費合計
- FurimaDeck上の実利益合計
- 対象取引件数

重要:

FurimaDeck上の「実利益」は経営管理用の指標であり、税務上の所得・利益と同一と断定しない。

## 10. 会計共通データ層

freee/MF/弥生それぞれで売上計算ロジックを重複させない。

内部に会計連携用の共通DTO/Model/Serviceを用意する。

例:

`AccountingEntry`

候補項目:

- transaction_date
- management_id
- product_id
- sale_id
- title
- marketplace
- sale_amount
- purchase_cost
- selling_fee
- shipping_cost
- other_cost
- furimadeck_actual_profit
- note
- source_status

ただし「どの項目をどの勘定科目・税区分として会計ソフトに送るか」は推測で決めない。

Codexは「有能な経理担当者かつ会計システムエンジニア」として振る舞うが、必ず国税庁・freee・マネーフォワード・弥生の最新公式仕様を確認し、確認できない会計判断を勝手に固定しない。

## 11. 会計連携方式

### プランA - API直接連携

対応:

- freee
- マネーフォワード

ユーザーがOAuthで自身の会計アカウントを接続する。

APIで送信する前に必ず確認画面を表示し、初期リリースではSOLD直後の完全自動送信を行わない。

理由:

- 返品
- キャンセル
- 送料変更
- 手数料変更
- 売価修正

があり得るため。

### プランB - CSV連携

必須対応:

- freee形式CSV
- マネーフォワード形式CSV
- 弥生形式CSV

ユーザーがCSVをダウンロードし、各会計ソフトへ自分でインポートする。

CSVはAPI障害/OAuth切れ/ユーザーがAPIを使わない場合の正式な代替手段とする。

### 弥生API

FurimaDeck 1.0では本番API連携の必須要件にしない。

現時点で実用可能な公式API提供状況を実装時に再確認する。正式な本番連携が確認できない場合はCSVのみを正式対応とし、APIボタンを見せない。

## 12. freee API連携

実装要件:

- OAuth 2.0
- Client ID / Client Secretは環境変数管理
- Callback URLはFurimaDeck本番/開発で明示
- access token / refresh tokenを安全に保存
- 暗号化可能なLaravelの仕組みを利用する
- OAuth解除/期限切れに対応
- APIエラーをユーザーに日本語で通知
- 同一取引二重送信防止
- 外部取引ID/同期状態を保存

推奨環境変数名:

- `FURIMADECK_FREEE_CLIENT_ID`
- `FURIMADECK_FREEE_CLIENT_SECRET`
- `FURIMADECK_FREEE_REDIRECT_URI`

## 13. マネーフォワード API連携

実装要件:

- OAuth 2.0を基本とする
- Client ID / Client Secretは環境変数管理
- Callback URL明示
- token安全保管
- 二重送信防止
- API仕様差異をfreeeと同一実装に無理に合わせない

推奨環境変数名:

- `FURIMADECK_MF_CLIENT_ID`
- `FURIMADECK_MF_CLIENT_SECRET`
- `FURIMADECK_MF_REDIRECT_URI`

## 14. 開発者（人間）が外部サービス側で行う作業

Codexは以下を勝手に作成できない前提で実装する。

### 14.1 freee

人間が行う:

- freeeアカウント作成
- 開発用テスト事業所作成
- 開発アプリ作成
- Client ID取得
- Client Secret取得
- Codex実装後の正しいCallback URL登録

秘密値をGitHubへコミットしない。

### 14.2 マネーフォワード

人間が行う:

- マネーフォワードID作成
- 会計/確定申告用事業者作成
- アプリポータル利用開始
- FurimaDeckアプリ登録
- Client ID取得
- Client Secret取得
- Callback URL登録

### 14.3 弥生

1.0ではAPI利用登録を必須にしない。

CSV仕様を最新公式資料で確認して実装する。

### 14.4 Stripe

人間が行う:

- Stripeアカウント/対象アカウント確認
- Live Product作成
- 月額980円Price作成
- 7日trial仕様確認
- Webhook Endpoint作成
- Webhook signing secret取得
- Live Secret Key取得

### 14.5 Google OAuth

人間が行う:

- Google Cloud/認証設定
- OAuth Client ID/Secret
- 本番redirect URI登録

### 14.6 Mail

人間が行う:

- 利用メールサービスのアカウント/ドメイン確認
- SMTP/API認証情報
- SPF/DKIM等の必要設定

Codexは秘密情報そのものを要求・表示・コミットしない。

## 15. 認証

リリース前に以下を安定化する。

- 通常メール/パスワードログイン
- メール認証
- Googleログイン
- パスワード再設定
- ログアウト
- 未認証ユーザー制御
- OAuth失敗時の日本語エラー

必須テスト:

- 初回登録
- 認証メール
- 認証済み遷移
- Google初回
- Google既存ユーザー
- 同一メール衝突
- OAuthキャンセル
- パスワード再設定

## 16. Stripe課金 - PAY.JP移行は中止

FurimaDeck 1.0はStripeを継続使用する。

PAY.JPへは移行しない。

### 16.1 料金

- Premium: 980円/月
- Trial: 7日

### 16.2 実装

- Checkout
- Customer
- Subscription
- Webhook
- Billing Portal/契約管理
- 支払い成功
- 支払い失敗
- 解約
- 再契約
- trial

### 16.3 設定統一

新FurimaDeck課金は `FURIMADECK_` prefixへ統一する。

最低限:

- `FURIMADECK_STRIPE_SECRET`
- `FURIMADECK_STRIPE_WEBHOOK_SECRET`
- `FURIMADECK_STRIPE_PRICE_ID`
- `FURIMADECK_STRIPE_PORTAL_CONFIGURATION_ID`（必要な場合）
- `FURIMADECK_TRIAL_PERIOD_DAYS`
- `FURIMADECK_MONTHLY_PRICE_JPY`

旧 `STRIPE_*` / FURUPRO用設定との混在を調査し、新FurimaDeckコードがどちらを参照しているかを明確化する。

### 16.4 DBの古いStripe ID

APIキー/Stripeアカウントを作り直した場合、古い `stripe_customer_id` / `stripe_subscription_id` が新環境で無効になる可能性がある。

現在契約者0人であることを前提に、テストユーザーの不要な旧Stripe IDを安全に整理する手順を用意する。ただしDB変更前にバックアップし、全ユーザーを無条件削除/初期化しない。

### 16.5 Webhook

- Endpoint: 新FurimaDeck routeを使用。
- Stripe署名検証必須。
- Webhook Event IDによる冪等性を持つ。
- 同一イベント再送で二重更新しない。
- 2xxを返す条件とエラーログを整理。
- Secretをログへ出さない。

## 17. CSVセキュリティ

- importファイルサイズ上限を設定。
- 行数上限を検討。
- 許可拡張子だけで信用せず内容を検証。
- 不正な文字コード/壊れたCSVを安全に拒否。
- ユーザー入力由来の文字列をスプレッドシートで開くCSVへ出す場合、CSV Formula Injection（`=`, `+`, `-`, `@` 等）への対策を検討する。
- 会計ソフト指定形式を壊す対策は行わず、公式仕様と安全性を両立する。
- Importはログインユーザーのデータだけを作成/更新する。

## 18. テスト仕様書更新

既存テスト仕様書に本仕様の全項目を追記する。

### 18.1 CSV

- FurimaDeckバックアップCSV
- 復元CSV
- 出品中CSV
- 売上CSV
- FurimaDeck CSV import
- Yahoo CSV import
- Mercari Shops CSV import
- 重複management_id
- encoding
- malformed CSV
- 大量行上限

### 18.2 クロス分析

テストデータから手計算し、次を照合する。

- ジャンル x 出品先売上
- SOLD件数
- 実利益
- 行合計
- 全体合計
- 未設定
- 他ユーザー混入なし

### 18.3 確定申告期間境界

テスト例:

- 2025/12/31 -> 2026年分に含めない
- 2026/01/01 -> 含める
- 2026/12/31 -> 含める
- 2027/01/01 -> 含めない

画面合計とCSV合計が一致すること。

### 18.4 会計CSV

- freee形式
- MF形式
- 弥生形式
- 最新公式仕様に一致
- 対象期間一致
- 金額一致
- 文字コード/日付形式等一致

### 18.5 API

freee/MFそれぞれ:

- OAuth成功
- OAuth拒否
- token期限
- refresh
- API成功
- APIエラー
- 二重送信防止
- 連携解除
- 他ユーザーtokenへのアクセス不可

### 18.6 Stripe

- Checkout
- Trial
- Subscription
- Webhook署名
- 成功イベント
- 失敗イベント
- Event再送
- 解約
- 再契約
- Portal
- 古いCustomer ID時のエラー処理

### 18.7 認証

前述16章の全ケース。

## 19. バグ優先度とGO / NO-GO

### P0 - 致命的

例:

- ログイン不可
- 課金不可
- 他ユーザーのデータ閲覧
- データ消失
- 主要画面500
- CSVで別ユーザーデータ流出

1件でも残っていれば NO-GO。

### P1 - 重大

例:

- Google認証失敗
- メール認証失敗
- 売上/利益計算誤り
- 会計CSV金額誤り
- Stripe Webhookで契約状態不整合
- 復元CSVが復元できない

原則1件でも残っていれば NO-GO。

### P2 - 中

- 一部表示崩れ
- 操作性問題
- 非致命的な集計表示問題

内容を評価してGO判断可能。

### P3 - 軽微

- 文言
- 余白
- 色
- 軽微なUI問題

リリース後対応可。

## 20. リリース計画

目安:

### 9月後半

- 仕様凍結
- CSV復旧/移植
- 解析
- Google認証
- メール認証
- Stripe根本修正

### 10/1〜10/10

- 確定申告/会計CSV
- freee/MF API基盤
- 自動テスト追加

### 10/11〜10/20

- 総合テスト
- Stripe本番相当テスト
- 認証テスト
- CSV/金額検算
- セキュリティ確認

### 10/21〜10/27

- P0/P1修正
- 回帰テスト
- 本番環境確認

### 10/28〜10/31

- 最終GO/NO-GO
- GOなら正式リリース

日程より品質ゲートを優先する。P0/P1が残る場合は日付だけを理由に公開しない。

## 21. 1.0 非対象

リリース前に追加しない。

- e-Taxへの直接申告
- FurimaDeck自身による税額計算/確定申告書作成
- 弥生APIが正式実用可能でない状態での見せかけAPI連携
- フリマサイトへの非公式ブラウザ自動出品
- 自動スクレイピング
- Chrome拡張による自動入力/送信
- 新しい大型AI機能
- 紹介/アフィリエイトシステム
- 競合にあるという理由だけの追加機能

## 22. Codexへの会計実装指示

会計部分の実装時は次をプロンプトに含めること。

> あなたは有能な経理担当者であり、Laravel/PHPに精通した会計システムエンジニアです。ただし税務・会計仕様を推測して実装してはいけません。国税庁、freee、マネーフォワード、弥生の最新公式仕様を確認し、確認できた仕様だけを実装してください。不明点は勝手に判断せず「要確認」と明示してください。FurimaDeck内部の実利益計算と、税務上の所得・仕訳を混同しないでください。

## 23. Codex共通実装ルール

- 作業前に現行ソースを調査する。
- 既存機能削除禁止。
- 旧FURUPRO実装に同等機能がある場合は再利用/移植を優先する。
- 本番環境を直接編集しない。
- 開発 -> テスト -> GitHub -> 本番反映の順序を守る。
- migrationは可逆。
- destructive DB操作前にバックアップを要求する。
- ユーザーデータ分離を全queryで確認する。
- Secret/API Key/Password/Tokenをコード・テストログ・Gitへ出さない。
- 外部API障害時にユーザーデータを壊さない。
- 各機能の実装と同時に自動テストを書く。
- バグ修正後は関連テストだけでなく回帰テストを実行する。
- 新機能を勝手に追加しない。

## 24. 完了条件

FurimaDeck 1.0は以下をすべて満たした場合のみGO候補とする。

- コア商品/在庫/販売管理が正常。
- CSV管理4種 + 3系統importが正常。
- 基本解析 + ジャンルx出品先クロス分析が正常。
- freee/MF/弥生CSVが対象期間で正しく出る。
- freee/MF APIは実装・テストが完了したものだけ有効化。
- Google/メール認証が安定。
- Stripe 980円/7日trialが安定。
- 他ユーザーデータ混入がない。
- P0 = 0。
- P1 = 0。
- 自動テスト/回帰テスト合格。
- 本番設定チェック合格。

## 25. 参照すべき現行リポジトリ資料

- `README.md`
- `routes/web.php`
- `docs/furimadeck-discovery.md`
- `docs/furimadeck-cutover-handoff.md`
- `docs/furimadeck-new-database.md`
- `docs/ci-cd.md`
- 現行テスト一式

既存ドキュメントと本仕様が競合する場合、本仕様の「1.0リリーススコープ」に関する決定を優先し、技術的安全ルールは既存ドキュメントを維持する。
