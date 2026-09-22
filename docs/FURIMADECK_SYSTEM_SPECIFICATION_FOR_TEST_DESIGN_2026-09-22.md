# FurimaDeck システム詳細仕様書

## 1. 文書情報

- 文書名: FurimaDeck システム詳細仕様書（テスト仕様書作成用）
- 作成日: 2026-09-22
- 対象システム: FurimaDeck
- 対象リポジトリ: `C:\MyDeveloper\FurimaDeck`
- 技術: Laravel 13 / PHP 8.4 / Blade / MySQL または MariaDB / Stripe / Google OAuth
- 主目的: 本書を入力として、別のChatGPTが詳細なテスト仕様書、テストデータ、期待結果、回帰試験表を作成できるようにする
- 本書の優先順位: 現行ソースコード、現行DBスキーマ、最新正式仕様書、画面表示の順に確認する。矛盾は勝手に補完せず、差分として記録する。
- 本番環境のDB、Stripe Liveデータ、利用者の実データをテストで変更しない。

## 2. システムの目的

FurimaDeckは、個人・小規模事業者がフリマ販売の商品、在庫、出品、販売、利益、分析、確定申告準備データを一元管理するSaaSである。

業務の基本経路は次のとおり。

```text
仕入れ -> 商品登録 -> 在庫管理 -> 出品情報登録 -> 販売記録
       -> Sale確定 -> 手数料・送料・費用計算 -> 分析
       -> 販売改善 -> 会計・確定申告用データ出力または連携
```

重要な設計原則:

1. 商品、出品、販売履歴、分析、CSV、会計連携で同じ元データを使用する。
2. `inventory_status=out_of_stock` は在庫がないことを示すだけで、売却済みの証拠ではない。
3. 売却済みの正式な根拠は、有効な状態の `sales` レコードである。
4. 利益計算は整数の日本円で行い、画面・CSV・会計データで差が出ないようにする。
5. ユーザー所有データは、すべての画面、URL、検索、集計、更新、削除で分離する。
6. 外部フリマサイトへの自動出品は1.0の基本機能ではない。出品管理と出品情報の保存を行う。
7. FurimaDeckは税額確定、確定申告書作成、e-Tax送信を行わない。申告準備データの整理と出力・会計連携を行う。

## 3. 対象範囲と対象外

### 3.1 対象範囲

- 公開ページ、利用規約、プライバシーポリシー、特商法表記、FAQ、問い合わせ
- 登録、メール認証、ログイン、ログアウト、パスワードリセット、Google OAuth
- Free、Premium、7日間トライアル、デモアカウント
- 商品CRUD、カテゴリ、仕入先、画像、検索、一覧、詳細、編集
- 出品情報、出品先、出品価格、出品状態、外部URL
- Sale登録、発送状態、完了、キャンセル、返品、返金、在庫戻し
- CSVプレビュー、ページ分割、確定取込、バックアップ、復元、外部売上CSV
- 売上分析、利益分析、カテゴリ分析、出品先分析、クロス分析、高度分析
- 滞留在庫、販売改善、出品先適性、利益速度、確定申告準備状況
- freee、Money ForwardのOAuth・マスタ取得・CSV・API連携
- Stripe Checkout、Webhook、Customer Portal、解約、決済失敗、3-Dセキュア
- 管理者、メンテナンス、通知、問い合わせ管理、メール送信
- PWAマニフェスト、Service Worker、SEO用エンドポイント

### 3.2 対象外または明示確認が必要な機能

- メルカリ、Yahoo!オークション、Yahoo!フリマ、ラクマへの自動出品
- 非公式API、スクレイピング、ログイン情報を使った外部サイト操作
- 税務上の所得税・消費税・棚卸評価の最終判定
- e-Taxへの申告書送信
- YayoiのAPI直接連携
- 市場全体の相場を外部データなしに推測する機能
- AIが根拠なく販売価格や出品先を断定する機能

## 4. 環境とデータ保護

### 4.1 ローカル開発

- 作業ディレクトリ: `C:\MyDeveloper\FurimaDeck`
- ローカルURL例: `http://127.0.0.1:8001`
- ローカルDBは本番DBと分離する。
- 本番の`.env`、Stripe Live秘密鍵、OAuth秘密情報をローカルへコピーしない。
- テストは専用DB、専用ユーザー、専用メールアドレスを使う。

### 4.2 本番

- 本番URLは環境変数と正式リリース設定を確認して確定する。
- `APP_ENV=production`、`APP_DEBUG=false`、HTTPS、Secure Cookieを必須とする。
- 本番migration前にDBバックアップを人間が確認する。
- テストのために本番ユーザー、商品、売上、契約を作成・削除しない。

### 4.3 禁止事項

- `migrate:fresh`、`db:wipe`、無条件DELETE、TRUNCATE、DROP TABLE
- `git reset --hard`、`git clean -fd`、強制push
- シークレットのコード、ログ、画面、CSVへの出力
- ユーザー入力のSQL文字列連結
- 本番環境でのデバッグモード起動

## 5. 利用者、ロール、権限

### 5.1 未認証利用者

利用可能:

- トップページ、機能、料金、利用例、FAQ
- 利用規約、プライバシーポリシー、特商法表記、問い合わせ
- 登録、ログイン、Google OAuth開始

禁止:

- 商品、販売、分析、会計、契約、プロフィールへの認証なしアクセス
- 他ユーザーのIDを指定したデータ参照

### 5.2 Free利用者

- 商品登録数上限は基本50件。
- Premium限定のCSV、分析、外部売上取込、会計連携などはサーバー側で拒否する。
- 画面で非表示にするだけでは不十分で、直接URL、POST、PATCH、DELETEでも拒否する。
- 上限ちょうどの登録は成功し、上限超過は失敗する。

### 5.3 Premium・Trial利用者

- 月額980円。
- 無料トライアルは7日間。
- TrialingはPremium相当として扱う。
- StripeのCheckout成功画面だけでPremiumにしない。WebhookまたはStripe APIの状態を正とする。
- 契約終了、取消、決済失敗後の権限はStripe状態と現行Entitlementルールに従う。

### 5.4 デモアカウント

- 契約なしでPremium相当の画面を確認できる。
- Checkout、契約変更、アカウント削除、重要データ破壊は制限する。
- デモユーザーのデータは通常ユーザーのデータと分離する。
- デモ機能の残存は将来廃止予定だが、現行テストでは動作を確認する。

### 5.5 管理者

- 管理者判定はサーバー側で行う。
- 一般ユーザーが管理者URLへアクセスしても403または安全な拒否画面になる。
- ユーザー管理、メンテナンス、通知、問い合わせ対応、成長情報にアクセスできる。
- 管理者操作は対象、操作者、結果を監査可能にする。

## 6. URL・画面・機能一覧

### 6.1 公開URL

| 種別 | パス | 目的 |
|---|---|---|
| トップ | `/` | サービス入口 |
| 機能 | `/features` | 機能説明 |
| 料金 | `/pricing` | 980円・7日トライアル表示 |
| 利用例 | `/use-cases` | 利用例 |
| FAQ | `/faq` | よくある質問 |
| 規約 | `/terms` | 利用規約 |
| プライバシー | `/privacy` | プライバシーポリシー |
| 特商法 | `/commercial-transactions` | 販売条件 |
| 問い合わせ | `/contact` | 問い合わせ登録・メール送信 |
| Google開始 | `/auth/google` | OAuth開始 |
| Google戻り | `/auth/google/callback` | OAuth処理 |
| マニフェスト | `/manifest.webmanifest` | PWA設定 |
| Service Worker | `/service-worker.js` | PWAオフライン処理 |
| sitemap | `/sitemap.xml` | SEO |
| robots | `/robots.txt` | クローラー制御 |

### 6.2 FurimaDeck画面

| パス | 機能 | 主な権限 |
|---|---|---|
| `/furimadeck-dashboard` | 基本ダッシュボード | 認証済み |
| `/products` | 商品一覧 | 認証済み |
| `/products/create` | 商品登録 | 認証済み |
| `/products/{product}/edit` | 商品編集 | 所有者 |
| `/products/import` | CSV取込 | Premium相当 |
| `/listings` | 出品管理 | 認証済み |
| `/listings/create` | 出品登録 | 認証済み |
| `/furimadeck-sales` | 売上一覧 | Premium相当 |
| `/furimadeck-sales/create` | 売上登録 | Premium相当 |
| `/furimadeck-analytics` | 基本分析 | Premium相当 |
| `/furimadeck-analytics/categories` | カテゴリ分析 | Premium相当 |
| `/furimadeck-analytics/cross` | カテゴリ×出品先 | Premium相当 |
| `/furimadeck-analytics/advanced` | 高度分析 | Premium相当 |
| `/furimadeck-analytics/suitability` | 出品先適性 | Premium相当 |
| `/furimadeck-improvement` | 販売改善 | Premium相当 |
| `/furimadeck-accounting` | 会計・申告準備 | Premium相当 |
| `/furimadeck-billing` | FurimaDeck契約 | 認証済み |
| `/furimadeck-account` | アカウント・データ管理 | 認証済み |
| `/furimadeck-activity` | 利用履歴 | 認証済み |

### 6.3 既存互換URL

旧FURUPRO系URLが残っている場合、互換用途か切替中かを確認する。新しい画面と旧画面で別の計算・権限・データ源を持たせない。

## 7. データモデル

### 7.1 User

主な責務: 認証、所有者、契約・権限、管理者判定。

テスト観点:

- email一意性、認証状態、Google連携状態
- demoフラグ、管理者フラグ、Stripe customer/subscription識別子
- 退会時の契約確認、所有データの保護

### 7.2 Product

主な項目:

- `id`: 主キー
- `user_id`: 所有ユーザー
- `internal_sku`: ユーザー内管理ID。CSV重複判定にも使用
- `product_name`: 商品名
- `category_id`:カテゴリ
- `condition`: `new`, `unused`, `like_new`, `used_good`, `used`, `damaged`, `junk`
- `description_base`: 商品登録の基本説明
- `purchase_date`: 仕入日
- `purchase_unit_cost`: 仕入単価、0以上の整数円
- `purchase_quantity`: 仕入数量、1以上の整数
- `quantity_available`: 現在庫数量、0以上の整数
- `purchase_shipping_cost`: 仕入送料
- `other_purchase_expense`: その他仕入費用
- `supplier_id`: 仕入先
- `jan_ean`, `isbn`, `manufacturer_model_number`, `serial_number`
- `storage_location`, `memo`
- `inventory_status`: `in_stock` または `out_of_stock`

`inventory_status`は保存時に数量から同期される。数量が1以上なら`in_stock`、0以下なら`out_of_stock`。

### 7.3 ProductCategory

- 階層は大ジャンル、中ジャンル、小ジャンルを想定する。
- 既存カテゴリを勝手に削除・縮小しない。
- 親子関係、重複名、未設定カテゴリをテストする。
- CSVで親名・子名を指定した場合、組み合わせがDBに存在しないとエラーにする。

### 7.4 ProductImage

- 商品所有者を経由して認可する。
- 最大10枚。
- 1枚最大2MBを基本とする。
- JPEG、PNG、WEBPを受け付け、処理後はWebP保存を基本とする。
- 最大長辺2000px、サムネイル最大400px、品質80を基本値とする。
- EXIF向き補正を行う。
- オリジナルとサムネイルを区別する。
- ドラッグ&ドロップ、複数選択、フォルダからの複数選択、1枚ずつ選択、並び替えを支援する。
- 10枚超、2MB超、壊れた画像、偽装拡張子を拒否する。

### 7.5 Supplier

仕入先名を商品に紐付ける。ユーザー間でデータを共有しない。削除時に商品参照がある場合の挙動を明示する。

### 7.6 Marketplace

主な出品先コード:

- `mercari`
- `yahoo_auctions`
- `yahoo_flea_market`
- `rakuma`
- `mercari_shops`
- `other`

初期販売手数料率:

| コード | 初期率 |
|---|---:|
| mercari | 10.00% |
| yahoo_flea_market | 5.00% |
| yahoo_auctions | 10.00% |
| rakuma | 10.00%（段階制のため初期見積値） |
| other | 0.00% |

DBの`default_fee_rate`が存在する場合、個別マスタ値を優先する。外部仕様変更があれば設定値と説明を同時に更新する。

### 7.7 Listing

商品と出品先を結ぶ。主な項目:

- `user_id`, `product_id`, `marketplace_id`
- タイトル、説明、出品価格、数量
- 出品日時、終了日時、外部URL、外部ID
- 配送方法、送料負担、送料、発送日数、販売形式
- 状態: `draft`, `ready`, `active`, `sold`, `ended`, `cancelled`（実DBの許容値を確認）

売上登録時に`ready`または`active`のListingだけを販売確定へ使用する。売却時に関連Listingを`sold`へ更新する。

### 7.8 Sale

主な項目:

- 所有者: `user_id`
- 商品: `product_id`
- 出品: `listing_id`（任意）
- 出品先: `marketplace_id`
- スナップショット: `internal_sku_snapshot`, `product_name_snapshot`
- `quantity`: 販売数量
- `sold_price`: 売れた価格
- `sold_at`: 売却日時
- 状態: `pending`, `awaiting_shipment`, `shipped`, `completed`, `cancelled`, `returned`
- `cost_basis`: 原価
- `sales_fee`: 販売手数料額
- `sales_fee_rate`: 販売手数料率
- `shipping_fee`: 販売送料
- `purchase_shipping_cost`: 仕入送料配賦
- `packing_cost`, `repair_cost`, `cleaning_cost`, `other_expense`
- `net_profit`: 実利益
- 発送、完了、キャンセル、返品、返金、再入庫関連日時・金額

有効な販売状態は`awaiting_shipment`, `shipped`, `completed`。`pending`, `cancelled`, `returned`は通常のSOLD・売上集計から除外する。

## 8. SOLD判定と在庫ルール

### 8.1 SOLD判定

次の両方を満たすときだけ商品をSOLD扱いにする。

```text
quantity_available = 0
かつ
有効なSaleが1件以上存在する
```

以下だけではSOLDにしない。

- `inventory_status=out_of_stock`
- 商品が削除・非表示になった
- Listingが終了した
- 仕入数量が0になった

### 8.2 部分販売

仕入数量3、販売数量2、在庫1の場合:

- Saleは作成する。
- `quantity_available=1`。
- 在庫状態は`in_stock`。
- SOLD表示はしない。

### 8.3 完売

仕入数量1、販売数量1、在庫0、有効Saleありの場合:

- `inventory_status=out_of_stock`。
- SOLD表示する。
- Listingがあれば`sold`へ更新する。

### 8.4 キャンセル

- Sale状態を`cancelled`へ変更する。
- 販売数量を在庫へ戻す。
- 在庫状態を数量から再計算する。
- SOLD表示を消す。
- 通常売上・通常利益・会計送信対象から除外する。
- 二重キャンセルはエラーにする。

### 8.5 返品

- Sale状態を`returned`へ変更する。
- 返金額、返品送料、返品理由を保持する。
- 再入庫する場合は`quantity_available`を増やす。
- 再入庫しない場合は数量を増やさない。
- どちらの場合も`returned`は通常のSOLD判定から除外する。
- 返品後の損失を分析・会計へどう含めるかは、画面定義とAccountingEntry定義を一致させる。

## 9. 金額計算

### 9.1 実利益の正式式

```text
実利益 = 売れた価格
       - 原価
       - 販売手数料
       - 販売送料
       - 仕入送料
       - 梱包費
       - 修理費
       - クリーニング費
       - その他費用
```

全金額は0以上の整数円として入力し、実利益だけは赤字を許容する整数とする。

### 9.2 手数料

出品先選択で手数料率を自動設定する。手数料額が未入力で自動計算対象の場合:

```text
販売手数料 = floor(売れた価格 × 手数料率 ÷ 100)
```

実装で`round`や`ceil`を使用している場合は、画面、CSV、テスト仕様にその方式を明記する。途中計算の浮動小数点誤差を許容しない。

### 9.3 利益率

```text
実利益率 = 実利益 ÷ 売れた価格 × 100
```

売れた価格が0の場合はゼロ除算せず、0またはN/Aの仕様を統一する。

### 9.4 計算確認例

売価5,000円、原価3,000円、手数料500円、送料250円の場合:

```text
5,000 - 3,000 - 500 - 250 = 1,250円
```

原価6,800円、売価5,000円、手数料500円、送料250円の場合:

```text
5,000 - 6,800 - 500 - 250 = -2,550円
```

## 10. 商品画面仕様

### 10.1 商品登録

必須または基本項目:

- 管理ID/SKU
- 商品名
- 商品状態
- 商品説明
- カテゴリ
- 仕入単価
- 仕入日
- 仕入数量
- 現在庫数量
- 仕入先、保管場所、メモ
- 画像
- 出品情報（出品先、出品価格等）

商品登録成功後は商品一覧へ遷移する。入力エラー時はフォームへ戻り、入力値と項目別エラーを維持する。

### 10.2 商品編集

- 商品基本情報、商品説明、出品情報、販売情報を同一ページで管理する。
- 出品先はプルダウンで選択する。
- 出品先選択時に手数料率を自動表示する。
- 売れた価格、送料、売却先を入力して保存できる。
- 売却情報が揃った場合はSale同期・SOLD判定を行う。
- 保存成功後は商品一覧へ遷移する。
- 保存失敗時はトランザクションをロールバックし、部分更新を残さない。

### 10.3 商品一覧

表示対象:

- 商品名、管理ID、カテゴリ、画像
- 在庫状態、在庫経過時間
- 出品先、出品価格
- 売れた価格、売上、SOLD
- 仕入値、利益関連表示
- 滞留在庫、30日以上等のフィルタ

在庫経過は小数日ではなく、分、時間、日、時間など人間が読める単位で表示する。

「30日以上」などのフィルタは選択式の状態として扱い、別のフィルタ選択時に古い条件が意図せず残らない。基準日は仕様に従い、登録日時または仕入日を勝手に混在させない。

## 11. 出品管理仕様

- 商品に複数Listingを持てるかは現行DB制約を確認する。
- 出品先、出品価格、説明、配送、外部URLを保存する。
- 出品先は販売確定時ではなく、出品情報登録時に選択する。
- `active`や`ready`以外のListingから販売確定できない。
- 他ユーザーのListing IDを指定した販売登録は拒否する。
- 出品価格と売れた価格を混同しない。
- 出品価格は予定価格、売れた価格は確定売上である。

## 12. 販売画面仕様

### 12.1 登録

入力:

- 商品
- 販売数量
- 売れた価格
- 売却日時
- 出品先またはListing
- 手数料
- 送料
- その他費用

処理:

1. 商品とユーザーの所有関係を確認する。
2. 数量が現在庫を超えないことを確認する。
3. Listing指定時は同じ商品・同じユーザー・`ready`/`active`であることを確認する。
4. Sale作成、在庫減算、在庫状態同期、Listing更新を同一トランザクションで行う。
5. 利益を正式Calculatorで計算する。

### 12.2 状態遷移

```text
pending -> awaiting_shipment -> shipped -> completed
pending/awaiting_shipment/shipped/completed -> cancelled または returned
cancelled, returned -> 再度のキャンセル・返品は禁止
```

許可されない状態遷移はエラー画面または入力画面へ安全に戻す。

### 12.3 重複・同時実行

- 同じSaleの二重送信を防ぐ。
- 在庫数量1に対する同時販売2件は、片方のみ成功し、もう片方は在庫不足で失敗する。
- 行ロックまたは同等の整合性を確認する。

## 13. CSV取込・出力仕様

### 13.1 共通制約

- 最大ファイルサイズ: 10,240KB
- 最大行数: 5,000行
- 最大セル文字数: 10,000文字
- プレビュー表示: 1ページ50行を初期値とする
- UTF-8、BOM、主要日本語CSVのエンコーディングを処理する
- ヘッダー重複、未知列、列数超過、必須列不足を検知する
- CSVの値をHTMLへ表示するときはエスケープする
- 数式インジェクションを防止する
- 取込はプレビューと確定を分離する
- プレビューだけではDBへ保存しない
- 確定時は同一バッチの二重確定を防止する

### 13.2 商品CSV必須列

```text
internal_sku
product_name
condition
purchase_unit_cost
purchase_quantity
quantity_available
inventory_status
```

### 13.3 商品CSV任意列

```text
description_base
purchase_date
purchase_shipping_cost
other_purchase_expense
jan_ean
isbn
manufacturer_model_number
serial_number
storage_location
memo
category_id
parent_category
category
supplier_id
listing_price
listed_at
marketplace_code
```

### 13.4 売上CSV任意列

```text
sale_state
sale_quantity
sold_price
sold_at
marketplace_code
sales_fee
sales_fee_rate
shipping_fee
sale_purchase_shipping_cost
packing_cost
repair_cost
cleaning_cost
sale_other_expense
sale_status
```

### 13.5 `sale_state`

- `stock`: 商品だけを作成する。売上列は空欄であること。
- `sold`: 商品、Listing、Saleを必要に応じて作成する。
- `sold`の場合、marketplace_code、listed_at、listing_price、sale_quantity、sold_price、sold_atを必須とする。
- `sale_quantity`は1以上、purchase_quantity以下。
- `quantity_available + sale_quantity <= purchase_quantity`。
- `sold`行では`quantity_available = purchase_quantity - sale_quantity`。
- 出品先コードは有効なMarketplaceマスタに存在しなければならない。
- 未知の出品先は推測登録せず、エラーにする。

### 13.6 日付

- 基本形式は`YYYY-MM-DD`。
- purchase_date <= listed_at <= sold_at。
- 日付のタイムゾーン境界を明示する。
- 不正日付、存在しない日付、時刻付き値の扱いをテストする。

### 13.7 CSVページング

- 50件単位でページを表示する。
- 1、2、3…のページ番号を表示する。
- ページ移動でプレビュー内容、エラー行、確定対象が壊れない。
- 商品一覧へ戻るボタンを表示する。
- 全件を1画面に詰め込まない。

### 13.8 外部CSV

- Yahoo!オークション、メルカリShops等は、実サンプルに基づく固定マッピングを使用する。
- 「売上確定」と「出品中」「キャンセル」「返品」を区別する。
- 外部取引ID、URL、日時、売価、手数料、送料、商品名、管理IDを可能な範囲で保持する。
- 既存取込済みIDの再取込は二重売上を作らない。
- 列名変更、空欄、カンマ付き金額、全角数字、BOM、改行入り商品名をテストする。

## 14. 分析仕様

### 14.1 共通条件

- 期間フィルタを全画面で統一する。
- 通常集計は有効Saleのみを対象にする。
- cancelled、returnedを通常売上に含めない。
- 他ユーザーのデータを含めない。
- 売上、手数料、送料、原価、実利益の合計を同じSale集合から計算する。
- データなしは0または「データなし」を使い分け、推測値を表示しない。
- データ不足の場合は、何が不足しているか説明する。

### 14.2 必須指標

- 売上合計
- SOLD件数
- 原価合計
- 販売手数料合計
- 送料合計
- その他費用合計
- 実利益合計
- 実利益率
- 月別売上・利益
- 出品先別売上・件数・利益
- 大・中・小ジャンル別売上・件数・利益
- ランキング上位10件。ただし実データが10件未満なら存在する全件を表示する。

### 14.3 高度分析

- 販売日数: `sold_at - listed_at`。片方がない場合は算出対象外。
- 滞留在庫: 在庫あり商品の仕入日または正式に定義された登録日を基準に30/60/90日で分類。
- 在庫仕入額: `purchase_unit_cost × quantity_available`。
- 利益速度: `net_profit ÷ max(1, 販売日数)`。
- 価格帯、ブランド、仕入先、曜日、季節性、カテゴリ×出品先を集計。
- 母数不足時は「データ不足」とし、最低件数と不足項目を説明する。
- 分析のすべての金額は商品一覧、売上一覧、CSV、会計共通データと照合できること。

### 14.4 販売改善

- 30/60/90日滞留を候補化する。
- 価格見直し候補は比較可能な過去実績5件以上を基本とする。
- 出品先変更候補は比較先実績3件以上、滞留60日以上、速度差20%以上を基本とする。
- 再仕入れ候補は実績3件以上を基本とする。
- 自動値下げ・自動再出品はしない。
- すべての候補に理由、対象件数、基準値、比較値を表示する。

## 15. 会計・確定申告準備

### 15.1 FurimaDeckの責任範囲

行う:

- 売上・費用・原価の整理
- 期間指定
- 不足データ確認
- 会計CSV出力
- freee、Money ForwardのOAuth・マスタ取得・送信
- 同期状態、外部ID、エラー表示

行わない:

- 税額の最終確定
- 申告書の作成・提出
- 税務判断の断定

### 15.2 準備状況チェック

各Saleについて次を確認する:

- 売上日時
- 売価
- 原価
- 販売手数料
- 送料
- その他費用
- 返品・キャンセル状態
- 商品の仕入日
- 仕入先
- 管理ID
- 会計同期状態

0円と未入力は区別する。確認対象件数と理由を表示する。

### 15.3 共通AccountingEntry

画面、CSV、freee、Money Forwardは同じ共通仕訳データを入力とする。

最低項目:

- 取引日
- 摘要
- 借方勘定
- 貸方勘定
- 金額
- 税区分
- 商品・Sale参照
- 同期状態
- 外部ID
- エラー

同じ期間・同じフィルタで、UI合計 = CSV合計 = API送信額となること。差額は0円であること。

### 15.4 ワークフロー

```text
未確認 -> 確認対象抽出 -> Review -> Confirm -> Send -> 同期済み
```

- 確認前に送信しない。
- 送信済みの二重送信を防止する。
- 外部送信失敗時はエラーを保持し、再送可能にする。
- トークンは暗号化保存する。
- OAuth解除時にローカル接続状態を安全に更新する。

### 15.5 freee

- Client ID、Client Secret、Redirect URI、Scopeを環境変数で管理する。
- company_id、tax_code、勘定科目ID等が必要な場合は明示的な設定画面または環境設定で入力する。
- 未設定時は外部APIを推測して呼ばず、設定不足を表示する。
- OAuthエラー、期限切れ、権限不足、会社未選択、税区分未設定を個別表示する。

### 15.6 Money Forward

- OAuth Scopeとアプリポータル権限が一致していること。
- tenant、office、account、tax masterを取得する。
- API Base URLは空欄にしない。schemeとhostを含む絶対URLにする。
- 409は会計期間・利用可能な事業者状態を確認する。
- 403 `INSUFFICIENT_SCOPES`は権限または再認可が必要な状態として表示する。
- 税区分マスタが空の場合は、会計期間・契約プラン・API権限・対象事業者を確認対象として表示する。
- CSV出力はAPI連携不可でも利用できる設計にする。

## 16. Stripe・課金仕様

### 16.1 Checkout

- Premium Price IDとSecret Keyは同じTest/Liveモードであること。
- 本番でPrice ID不備の場合、動的な代替Priceを作らず失敗させる。
- 7日間トライアルをCheckout設定へ渡す。
- Checkout成功ページ表示だけで権限を付与しない。

### 16.2 Webhook

- 署名検証を必須とする。
- Event IDの重複処理を防ぐ。
- イベント到着順が前後しても古いイベントで新しい状態を上書きしない。
- 支払成功、支払失敗、契約更新、契約取消、トライアル終了をテストする。

### 16.3 Customer Portal・解約

- Portalへ遷移できる。
- 契約中、トライアル中、解約予約済み、終了済みで表示を変える。
- 解約理由は任意または仕様に従う。解約ボタンを理由欄の不備で消さない。
- 無料期間中の解約可否はStripe側のPortal設定と契約状態を確認する。

### 16.4 3-Dセキュア

- 3DS要求設定を維持する。
- 3DS成功、失敗、キャンセル、認証要求、タイムアウトをテストする。
- 決済画面エラーを利用者へ安全に表示し、秘密情報を表示しない。

## 17. 認証・メール

### 17.1 メール認証

- 登録後は未認証状態にする。
- 認証完了まで保護ページへ入れない。
- 再送信を可能にする。
- 同じメールへの連続送信を抑制する。
- 認証トークンは期限と一回性を持つ。

### 17.2 パスワードリセット

- 登録済みかどうかを画面で漏らさない。
- 有効なトークンのみ使用できる。
- 使用済み、期限切れ、改ざんトークンは拒否する。
- メール内のURLは環境ごとのAPP_URLを使う。

### 17.3 メール配送

- Resend SMTPまたはAPI設定を環境変数から読む。
- 送信元ドメインはResendで認証済みであること。
- 問い合わせ本文、認証本文、リセット本文の改行を保持する。
- SMTP/API失敗時は利用者に一般的なエラーを表示し、スタックトレースやAPIキーを表示しない。
- DB登録とメール送信の成功・失敗の扱いを画面文言と統一する。
- ローカルURLを本番メールへ混入させない。

## 18. セキュリティ

- 全POST、PUT、PATCH、DELETEでCSRFを検証する。
- Blade出力は原則エスケープする。
- 商品、Sale、Listing、CSVバッチ、AccountingEntryの所有者をサーバー側で検証する。
- IDORを防ぐ。URLのID変更だけで他ユーザーの情報を取得できない。
- SQLはバインドパラメータまたはEloquentを使う。
- ファイルアップロードのMIME、サイズ、拡張子、実体を検証する。
- CSVインポートで式を実行させない。
- PII、メール、IP、トークン、Stripe IDを不用意にログへ出さない。
- エラーページは本番でスタックトレースを出さない。
- レート制限、認証試行制限、メール再送制限を確認する。
- 外部OAuthのstate、redirect URI、token期限を検証する。

## 19. 削除・データ保護

### 19.1 アカウントデータ全削除

- 確認画面を表示する。
- 明示的な確認操作なしで削除しない。
- デモユーザーも仕様で許可される範囲のデータ削除をできる。
- ユーザー、商品、Sale、画像、Listing、ImportBatch、AccountingEntry等の依存関係を考慮する。
- Stripe契約確認に失敗した場合、アカウント削除を中止する。
- 実行結果と削除対象を表示する。

### 19.2 月別削除

- 年月を選択する。
- 対象件数と対象金額を確認画面で表示する。
- 確認後のみ削除する。
- 他期間、他ユーザー、契約情報を削除しない。
- 削除後の分析合計が再計算される。

### 19.3 既存データ保護

- 既存ユーザー・商品・売上をテスト目的で削除しない。
- DB migrationは追加方式にする。
- 既存CSVの後方互換を維持する。

## 20. エラー処理

エラーの種類:

1. 入力エラー: フォームへ戻し、項目別に表示。
2. 権限エラー: 403または安全な拒否画面。
3. 認証エラー: ログインまたは認証案内。
4. 外部サービスエラー: サービス名、HTTP状態、再試行可否を安全に表示。
5. DB・システムエラー: 本番では一般的なエラー画面、内部ログには相関IDのみ。
6. 不整合エラー: 対象データと修正方法を表示し、勝手に補正しない。

全エラーで確認する項目:

- HTTPステータスが適切か
- 500画面にSQL、ファイルパス、秘密情報、スタックトレースが出ないか
- DBトランザクションが中途半端に残らないか
- 入力値が不用意に失われないか
- 再送や再読み込みで二重作成されないか

## 21. テストデータ基準

最低限、次のデータセットを作成する。

### 21.1 ユーザー

- 未認証ユーザー
- メール認証済みFree
- 上限50件直前のFree
- 上限到達Free
- Premium契約中
- Trialing
- 契約終了済み
- デモ
- 管理者
- 他ユーザー

### 21.2 商品

- 通常在庫1件
- 在庫0だがSaleなし
- 部分販売で在庫あり
- 完売で有効Saleあり
- cancelled Saleだけ
- returned Saleだけ、再入庫あり
- returned Saleだけ、再入庫なし
- カテゴリ未設定
- 大中小カテゴリ設定
- 画像0枚、1枚、10枚、11枚
- 2MB直前、2MB超
- 仕入日なし、仕入先なし、売却日なし

### 21.3 金額境界

- 0円
- 1円
- 999円
- 1,000円
- 9,999円
- 10,000円
- 手数料計算が整数になる値
- 小数結果になる値
- 赤字利益
- 最大許容値、最大値超過

### 21.4 日付境界

- 同日仕入・出品・売却
- 仕入日より前の出品
- 出品日より前の売却
- 29日、30日、31日、59日、60日、89日、90日
- 月末、年末、うるう年、タイムゾーン境界

## 22. ChatGPTへテスト仕様書を作らせるための出力要件

本書を元に、テスト仕様書は次の列を持つこと。

| 列 | 内容 |
|---|---|
| Test ID | 例: AUTH-001、SALE-015 |
| 優先度 | P0/P1/P2/P3 |
| 対象機能 | 画面・API・サービス |
| 目的 | 何を保証するテストか |
| 前提 | ユーザー、権限、DB、外部サービス状態 |
| テストデータ | 具体的な値、金額、日時、ID |
| 操作 | 手順を番号付きで記載 |
| 期待結果 | 画面、DB、メール、外部API、ログの結果 |
| 不変条件 | 失敗時にも守るべき状態 |
| 後処理 | 作成データの扱い。既存データを削除しない |
| 証跡 | スクリーンショット、レスポンス、DB確認、メールID |

最低限、以下の順でテストを生成する。

1. 認証・権限・所有者分離
2. 商品CRUD・画像・カテゴリ・仕入先
3. Listing・出品先・出品価格
4. Sale状態遷移・SOLD・在庫
5. 金額計算・手数料・赤字・境界値
6. CSVプレビュー・ページング・確定・重複・後方互換
7. 分析全画面と集計突合
8. 販売改善とデータ不足表示
9. 会計共通データ・CSV・freee・Money Forward
10. Stripe・Webhook・Trial・Portal・3DS
11. メール・問い合わせ・認証・パスワードリセット
12. 管理者・削除・メンテナンス
13. セキュリティ・CSRF・XSS・IDOR・ファイル検証
14. 性能、同時実行、再送、障害復旧
15. 回帰試験とリリース判定

## 23. P0/P1のリリースブロッカー

### P0

- 他ユーザーのデータが見える、変更できる、削除できる
- 売上、利益、手数料、在庫が二重計上される
- Stripe契約状態と権限が逆転する
- Webhook署名を検証しない
- 秘密鍵、パスワード、トークンが画面・ログ・メールへ漏れる
- 本番DBを破壊する操作が確認なしで実行される
- SQLインジェクション、認証バイパス、重大なIDOR

### P1

- SOLD判定が在庫なしだけで表示される
- キャンセル・返品が売上・利益に残る
- CSV取込後に一覧、売上、分析でデータ源が分裂する
- 1円以上の計算差が出る
- 認証メール・リセットメールのURLが誤る
- 会計CSVと画面の合計が一致しない
- 画像上限、ファイルサイズ上限が機能しない
- Free制限を直接URLやAPIで回避できる

## 24. 受入判定

次をすべて満たすまで「実装完了」「本番利用可能」と断定しない。

- P0が0件
- P1が0件、または明示的な延期承認がある
- 対象ユニット・Feature・結合テストが成功
- 主要画面をブラウザで確認
- CSVを実データに近い件数で確認
- 商品、Sale、分析、AccountingEntry、CSVの金額突合が一致
- StripeはTest Modeで成功・失敗・Webhook・Portalを確認
- freee/Money Forwardはモックまたは検証環境でOAuthとエラーを確認
- メールはResend検証済み送信元からテストし、実受信を確認
- 本番シークレットとローカルシークレットを混同していない
- DBバックアップの存在と復元手順を確認
- 未確認事項、既知制限、残リスクを記録

## 25. 既知の確認ポイント

以下はテスト仕様書作成時に、現行コードとDBで再確認する。

- `Listing`の全状態値と画面表示の一致
- 商品登録時のカテゴリ属性の扱い。不要なAmazon向け項目が表示されていないか
- 商品一覧、出品管理、販売管理のメニュー表示方針
- 既存FURUPRO互換ルートとFurimaDeckルートの二重処理有無
- ラクマの段階手数料を固定10%で扱う範囲
- 返品損失の実利益への反映方法
- 月別削除対象の厳密な範囲
- freeeとMoney ForwardのAPI送信可能なプラン・権限
- Money Forward税区分マスタが空の場合の利用者向け案内
- `APP_URL`、本番ドメイン、OAuth Redirect URIの環境別値
- 現行のローカルDB migration適用状況

これらを未確認のままテスト期待結果として固定してはいけない。

## 26. 参照資料

- 対象コード: `C:\MyDeveloper\FurimaDeck\app`
- ルート: `C:\MyDeveloper\FurimaDeck\routes\web.php`
- 設定: `C:\MyDeveloper\FurimaDeck\config\furimadeck.php`
- 既存正式仕様: `C:\Users\Administrator\Downloads\FurimaDeck_1.0_FINAL_SPEC_2026-09-19.md`
- CSV正式仕様: `C:\Users\Administrator\Downloads\FurimaDeck_1.0_CSV_FINAL_SPEC_2026-09-19.md`
- Money Forward公式API: https://developers.api-accounting.moneyforward.com/
- Money Forward OAuth公式: https://developers.biz.moneyforward.com/docs/common/oauth/overview/
- Money Forward API利用案内: https://biz.moneyforward.com/support/account/guide/others/ot09.html
- freee API公式: https://developer.freee.co.jp/
- Stripe Checkout公式: https://docs.stripe.com/payments/checkout
- Stripe Webhook署名公式: https://docs.stripe.com/webhooks/signature

## 27. 回答・報告時の原則

- 確認できた事実と推測を分ける。
- 実行していないテストを成功と書かない。
- 「設定した」と「実際に接続・受信・集計できた」を分ける。
- テスト失敗時は、再現条件、実際結果、期待結果、影響範囲、修正候補を記録する。
- 既存ユーザー、既存商品、既存売上を削除していないことを明記する。
- DB変更、Stripe変更、認証変更、セキュリティ変更、未確認事項を分けて報告する。
- 回答日時を`YYYY/MM/DD`で記載する。
