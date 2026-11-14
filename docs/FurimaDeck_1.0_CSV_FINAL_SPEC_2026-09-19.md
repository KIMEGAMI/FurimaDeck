# FurimaDeck 1.0 CSV機能 最終仕様書

作成日: 2026-09-19
対象: FurimaDeck 1.0
目的: 旧FURUPROで実運用していたCSV入出力機能を、FurimaDeckの現行データモデルへ正式移植する。

---

# 1. 方針

FurimaDeck 1.0のCSV機能は、現在の簡易な商品CSV・出品CSV・売上CSVだけでは不足している。

旧FURUPROで実際に利用していた以下のCSV機能を、FurimaDeck 1.0へ正式復元する。

1. 売上CSV出力
2. 全商品バックアップCSV
3. 出品中のみCSV
4. 復元用CSV
5. FurimaDeck形式CSV一括登録
6. Yahoo!オークション売上CSV変換・一括登録
7. メルカリShops CSV変換・一括登録

CSV管理は、会計サービス向けCSVとは完全に分離する。

- CSV管理 = 日常運用、商品移行、バックアップ、復元、外部販売履歴取込
- 確定申告・会計連携 = freee / Money Forward向け会計CSV出力とOAuth読取連携
- APIによる会計取引・仕訳の作成、更新、削除、送信、同期は1.1以降

両者を同じCSVとして扱わない。

---

# 2. CSV管理画面

画面名:

`CSV管理`

説明文:

> CSV取り込み、CSV出力、バックアップ、復元用CSVの管理を行います。

画面構成は旧FURUPROのCSV管理画面を基本とし、FurimaDeckデザインへ合わせる。

## 2.1 EXPORT & BACKUP

表示:

- 売上CSV出力
- 全商品バックアップCSV
- 出品中のみCSV
- 復元用CSV
- CSV出力項目を確認

説明:

> 売上分析用CSV、全商品バックアップCSV、CSV取り込みへ戻しやすい復元用CSVをここから出力できます。定期的にバックアップしておくと、端末変更や誤操作時の復旧に使えます。

## 2.2 復元しやすいバックアップ

説明:

> 「復元用CSV」は、商品を全削除する前、端末変更の前、誤ってデータを消す可能性がある作業の前に保存しておくCSVです。復元したい時は、この画面の「FurimaDeck形式CSVを一括登録」で復元用CSVを選び、「CSVを取り込む」を押してください。すでに同じ管理IDの商品がある場合はスキップされ、未登録の商品だけ戻ります。

補足表示:

- いつ保存する？
  商品全削除、CSV一括取り込み、端末変更、大きな整理作業の前。

- どれを保存する？
  戻す目的なら「復元用CSV」、確認用なら「全商品バックアップCSV」。

- どう戻す？
  「FurimaDeck形式CSVを一括登録」から復元用CSVを取り込む。

---

# 3. CSV共通仕様

## 3.1 文字コード

出力CSV:

- UTF-8 BOM付き

取込CSV:

- UTF-8
- UTF-8 BOM
- CP932
- Shift_JIS
- SJIS-win
- EUC-JP

UTF-8以外はFurimaDeck内部でUTF-8へ変換して処理する。

Yahoo!オークション実CSVとメルカリShops実CSVはいずれもCP932であることを確認済み。

## 3.2 CSV形式

- 1行目はヘッダー
- RFC 4180互換を基本とする
- カンマを含む文字列はダブルクォート
- ダブルクォートはエスケープ
- 空行は無視
- 行末の余分な空列は許容する
- 改行コード CRLF / LF の両方を許容する

## 3.3 セキュリティ

必須:

- CSV Formula Injection対策
- ファイルサイズ制限
- 行数制限
- MIME / 拡張子検証
- 文字コード検証
- malformed CSV拒否
- ログインユーザー単位のデータ分離
- 他ユーザーのデータ参照・更新禁止
- ファイル名のパストラバーサル対策
- 二重取込防止
- Preview → Commit方式を基本とする
- Commit二重実行防止
- 同時Commit対策

## 3.4 制限

基準値:

- 最大ファイルサイズ: 10MB
- 最大データ行数: 5,000行

値はconfigへ外出ししてよい。

---

# 4. 売上CSV出力

対象:

- ログインユーザー本人のみ
- 売却済み / 取引完了データのみ
- キャンセル・返品済みは原則除外
- SOLD日降順

旧FURUPRO互換の列:

```csv
管理ID,タイトル,大ジャンル,小ジャンル,出品先,仕入れ値,売値,販売手数料率,販売手数料,送料,実利益,ステータス,SOLD日
```

FurimaDeck現行モデルへの対応:

| CSV列 | FurimaDeck |
|---|---|
| 管理ID | `products.internal_sku` または `sales.internal_sku_snapshot` |
| タイトル | `sales.product_name_snapshot` |
| 大ジャンル | Product Category親階層 |
| 小ジャンル | Product Category |
| 出品先 | Marketplace名 |
| 仕入れ値 | `sales.cost_basis` |
| 売値 | `sales.sold_price` |
| 販売手数料率 | Listing/Marketplaceの実績率または算出値 |
| 販売手数料 | `sales.sales_fee` |
| 送料 | `sales.shipping_fee` |
| 実利益 | `sales.net_profit` |
| ステータス | SOLD |
| SOLD日 | `sales.sold_at` |

実利益はCSV専用計算を作らず、FurimaDeck共通利益計算を利用する。

---

# 5. 全商品バックアップCSV

目的:

- 人間が確認できる全商品の控え
- 障害調査
- 移行確認
- 大規模変更前のバックアップ

旧FURUPRO互換列:

```csv
管理ID,商品タイトル,大ジャンル,小ジャンル,出品先,ステータス,仕入れ値,販売価格,販売手数料率,販売手数料,送料,実利益,SOLD日,商品画像URL,SOLD画像URL,コメント,作成日,更新日
```

対象:

- ログインユーザー本人の全商品
- 出品中
- 売却済み
- 下書き
- その他FurimaDeck内部状態

注意:

- 画像URLは確認用
- CSVだけで画像バイナリの完全復元は保証しない
- 「復元用CSV」と役割を分ける

---

# 6. 出品中のみCSV

対象:

- ログインユーザー本人
- 現在出品中の商品のみ

旧FURUPRO互換列:

```csv
management_id,title,comment,platform,parent_category,category,purchase_price,sold_price,sales_fee_rate,shipping_fee,status,sold_at
```

固定値:

```text
status = selling
sold_at = 空
```

FurimaDeckでは、

```text
management_id = internal_sku
```

として扱う。

---

# 7. 復元用CSV

## 7.1 正式フォーマット

実際の旧FURUPRO「復元用.csv」で確認したヘッダーを正式互換形式とする。

```csv
management_id,title,comment,platform,parent_category,category,purchase_price,sold_price,sales_fee_rate,shipping_fee,status,sold_at
```

実ファイルでは以下のデータが存在することを確認済み。

```text
status:
- sold
- selling

platform:
- ヤフオク
- メルカリ
- ラクマ
- PayPayフリマ
```

## 7.2 項目定義

| 列 | 意味 | FurimaDeck |
|---|---|---|
| management_id | 管理ID | `internal_sku` |
| title | 商品タイトル | `product_name` |
| comment | メモ | `memo` 等 |
| platform | 出品先 | Marketplace |
| parent_category | 大ジャンル | Category親 |
| category | 小ジャンル | Category |
| purchase_price | 仕入れ値 | `purchase_unit_cost` / Sale cost basis |
| sold_price | 販売価格 | Listing/Sale |
| sales_fee_rate | 販売手数料率 | Listing / Marketplace |
| shipping_fee | 送料 | Listing/Sale |
| status | selling / sold 等 | Product/Listing/Saleへ変換 |
| sold_at | SOLD日 | Sale `sold_at` |

## 7.3 復元ルール

同一CSV内で同じ管理IDを複数行に記載する場合は、商品情報とステータスを一致させ、出品先ごとに1行とする。複数出品先の出品中商品を同一商品として復元できる。出品先の重複、および同一管理IDの複数SOLD行はPreviewでエラーにする。

同じユーザー内に同じ管理IDが存在する場合:

- 上書きしない
- スキップする
- スキップ件数を表示

異なるユーザー間では同じ管理IDを使用可能。

復元時に勝手に別ユーザーのデータと照合しない。

## 7.4 状態変換

```text
selling
→ Productを作成
→ Listingを出品中として作成

sold
→ Productを作成
→ Listingを売却済み相当へ
→ Saleを作成
```

`status = sold` の場合、`sold_at` を必須相当として扱う。

SOLDなのにSOLD日がない場合は自動的に現在日にせず、Previewで警告またはエラーとする。

確定申告年度を勝手に変える挙動を禁止する。

## 7.5 利益

CSV内の利益値は復元用CSVには存在しない。

利益は、

- 仕入れ値
- 売値
- 販売手数料
- 送料
- その他FurimaDeck上の経費

からFurimaDeck共通計算で再計算する。

---

# 8. FurimaDeck形式CSV一括登録

画面名:

`FurimaDeck形式CSVを一括登録`

説明:

> FurimaDeck用に整えたCSVをそのまま取り込みます。1行目はヘッダー行として扱います。

## 8.1 必須列

最低限:

```text
management_id
title
```

現行FurimaDeck形式として以下も受け付ける。

```text
internal_sku
product_name
```

内部で共通項目へ正規化する。

## 8.2 旧FURUPRO互換ヘッダー

以下を受け付ける。

```text
管理ID                -> management_id
商品ID                -> management_id
商品タイトル           -> title
タイトル               -> title
大ジャンル             -> parent_category
小ジャンル             -> category
出品先                 -> platform
ステータス             -> status
仕入れ値 / 仕入値      -> purchase_price
販売価格 / 売値        -> sold_price
販売手数料率           -> sales_fee_rate
販売手数料             -> sales_fee
送料                   -> shipping_fee
実利益                 -> profit
SOLD日                 -> sold_at
コメント               -> comment
商品画像URL             -> image_url
SOLD画像URL             -> sold_image_url
作成日                 -> created_at
更新日                 -> updated_at
```

`profit` が存在しても、FurimaDeck内部では再計算した値を正とする。

## 8.3 Preview

取り込み前にPreviewを必須とする。

Preview表示:

- 総行数
- 登録予定件数
- スキップ予定件数
- エラー件数
- 行番号
- 管理ID
- 商品名
- 状態
- エラー理由

エラーが存在する場合はCommit不可を基本とする。

---

# 9. Yahoo!オークション売上CSV

## 9.1 実CSVヘッダー

実際にアップロードされたYahoo!オークションCSVで以下を確認済み。

```csv
取扱内容,商品ID,取扱日,状態,売上,決済金額,落札システム利用料,販売手数料,送料,受取金額
```

文字コード:

```text
CP932
```

## 9.2 実CSVで確認できた状態

```text
売上金
チャージ完了
キャンセル
振込手続き完了(...)
```

**取込対象は `状態 = 売上金` のみとする。**

旧画面に存在していた「売上確定」という説明は使用しない。

実CSVと旧FURUPRO実装の双方が `売上金` を示しているため、FurimaDeck 1.0では `売上金` を正式条件とする。

## 9.3 取込対象条件

すべて満たす場合のみ対象。

```text
状態 = 売上金
商品IDが空でない
商品ID != "-"
取扱内容が空でない
決済金額 > 0
```

以下は除外:

- 売上金チャージ
- チャージ完了
- 振込手続き
- キャンセル
- 商品IDが `-`
- 管理用の非商品行

## 9.4 マッピング

| Yahoo!オークションCSV | FurimaDeck |
|---|---|
| 商品ID | 外部商品ID / 管理ID候補 |
| 取扱内容 | 商品名 |
| 取扱日 | `sold_at` |
| 決済金額 | `sold_price` |
| 落札システム利用料 | `sales_fee`へ加算 |
| 販売手数料 | `sales_fee`へ加算 |
| 送料 | `shipping_fee` |
| 状態 | 取込判定 |
| 売上 | 元データ監査用 |
| 受取金額 | 元データ監査用 |

販売手数料:

```text
sales_fee
=
落札システム利用料
+
販売手数料
```

`-` は0円として扱う。

## 9.5 既存商品との照合

Yahoo CSVから直接「仕入価格0円の商品」を完成データとして作る方式は禁止する。

処理:

```text
商品ID
↓
既存Listing.external_listing_id等で照合
↓
一致
  → 既存Product / ListingへSaleを紐付け
  → 既存仕入れ値・カテゴリ・仕入先を使用

不一致
  → 未照合売上としてPreview
  → 商品との紐付けを要求
  → または暫定Product作成
```

未照合で登録する場合:

```text
仕入れ値未入力
利益暫定
要確認
```

を明示する。

未入力仕入れ値を0円として「正確な利益」に見せない。

## 9.6 重複

同一ユーザー内で同じYahoo商品IDの販売実績を二重登録しない。

再取込時は:

- スキップ
- または既存Saleとして認識

勝手な二重Sale作成は禁止。

---

# 10. メルカリShops CSV

## 10.1 実CSVヘッダー

実際にアップロードされたメルカリShops CSVで以下を確認済み。

```csv
注文番号,明細番号,明細種別,購入日,支払日,発送日,売上移転日,キャンセル日,商品名,数量,通貨,販売利益,売上（税込）,メルカリ便送料（税込）,送料（税込）,販売手数料（税込）,販売手数料率（%）,クーポン割引金額,クーポンID,ショップ名,インボイス対象金額合計（販売手数料 + メルカリ便送料）,インボイス対象税率,伝票番号,請求書発行事業者
```

文字コード:

```text
CP932
```

今回のサンプルにはヘッダーのみで、実売上行は含まれていない。

したがって基本マッピングは確定するが、特殊な明細種別・返金等は実データ入手後に追加確認する。

## 10.2 取込対象条件

```text
注文番号が存在
明細番号が存在
商品名が存在
数量が1
キャンセル日が空
売上（税込） > 0
```

数量が1以外の明細は1商品ID=現物1点の仕様と一致しないため、取込を拒否して要確認として表示する。商品IDの自動分割・自動採番や、複数商品IDへの推測割当はしない。商品ごとに分かれた売上明細へ整理してから取り込む。

## 10.3 管理識別子

```text
注文番号-明細番号
```

を外部取引識別子として利用する。

例:

```text
ORDER12345-001
```

## 10.4 マッピング

| メルカリShops CSV | FurimaDeck |
|---|---|
| 注文番号 + 明細番号 | 外部取引ID |
| 商品名 | 商品名 |
| 購入日 | `sold_at` |
| 数量 | 1点のみ許可。Sale数量は1 |
| 売上（税込） | `sold_price` |
| 販売手数料（税込） | `sales_fee` |
| 販売手数料率（%） | 手数料率 |
| メルカリ便送料（税込） | `shipping_fee`へ加算 |
| 送料（税込） | `shipping_fee`へ加算 |
| ショップ名 | 取込元情報 |
| キャンセル日 | 取込除外判定 |
| 販売利益 | 元データ照合用 |
| クーポン関連 | 監査用元データ |

送料:

```text
shipping_fee
=
メルカリ便送料（税込）
+
送料（税込）
```

## 10.5 キャンセル

`キャンセル日` に値がある行は通常売上として取り込まない。

将来、既存Saleの取消処理へ対応する場合は別仕様として実装する。

## 10.6 既存商品との照合

Yahooと同様、既存Product / Listingへ紐付けることを優先する。

一致する商品がある場合:

- 仕入れ値
- カテゴリ
- 仕入先
- 商品情報

を既存Productから引き継ぐ。

一致しない場合:

```text
未照合売上
仕入れ値未入力
利益暫定
要確認
```

として扱う。

---

# 11. CSV管理とFurimaDeck新データモデル

旧FURUPROの `auction_items` 1テーブル前提をそのまま新FurimaDeckへコピーしない。

新FurimaDeckでは、

```text
Product
  ↓
Listing
  ↓
Sale
```

へ変換する。

## 11.1 selling行

復元CSV:

```text
status = selling
```

処理:

```text
Product作成
↓
Marketplace解決
↓
Listing作成
↓
Listing.status = active
```

## 11.2 sold行

復元CSV:

```text
status = sold
```

処理:

```text
Product作成
↓
Listing作成
↓
Sale作成
↓
Product在庫数を整合
↓
Listingをsold
```

## 11.3 Marketplace名互換

旧名称を正規化する。

最低限:

```text
ヤフオク
Yahoo!オークション

メルカリ

ラクマ

PayPayフリマ
Yahoo!フリマ
```

旧CSVの `PayPayフリマ` はFurimaDeck内部の現行名称へ正規化する。

---

# 12. CSVと利益計算

CSVごとに異なる利益計算式を実装してはならない。

共通概念:

```text
実利益
=
販売価格
- 原価
- 販売手数料
- 販売送料
- 仕入送料
- 梱包費
- 修理費
- クリーニング費
- その他経費
```

旧FURUPRO CSVに存在しない新FurimaDeck経費は0円として復元してよい。

ただし、CSVにない値を勝手に推測しない。

外部CSVから仕入れ値を取得できない場合は、利益未確定扱いにする。

---

# 13. CSVと確定申告

会計CSV 1.0の対象には、販売手数料、仕入、支払元を確認できた販売送料・その他販売費用を含める。支払元が確認できない費用は推測で計上せず、要確認として残す。画面・会計共通データ・出力CSVの金額を一致させ、同一費用を二重計上しない。

CSV取込後のデータは、

- 高度分析
- 販売促進
- 年次集計
- freee
- Money Forward

へ流れる。

そのため、以下はリリース不可レベルの問題として扱う。

- 売上金額の誤変換
- SOLD日の誤変換
- 手数料の誤変換
- 送料の誤変換
- 他ユーザー混入
- 二重売上
- キャンセル行を通常売上として登録
- 仕入れ値未入力なのに確定利益として扱う

---

# 14. 取込履歴

CSV取込ごとに履歴を保持する。

最低限:

```text
user_id
import_uuid
format
original_filename
started_at
completed_at
total_rows
success_rows
skipped_rows
failed_rows
status
```

format例:

```text
furimadeck_restore
furimadeck_generic
yahoo_auctions_sales
mercari_shops_sales
```

各行のPreview結果も保持する。

再送信・再取込・障害調査に利用する。

---

# 15. エラー表示

「取込に失敗しました」だけは禁止。

例:

```text
12行目: management_id が空です
18行目: SOLDですがSOLD日がありません
25行目: 既に登録済みの管理IDです
31行目: 金額形式が不正です
42行目: Yahoo!オークションの売上対象行ではありません
```

正常スキップとエラーを分ける。

例:

```text
登録 120件
スキップ 15件
エラー 2件
```

---

# 16. Release Gate / 自動テスト

CSVは「ファイルを出せた」「HTTP 200」だけでPASSにしない。

## 16.1 復元Round Trip

テストデータ100件を用意。

```text
出品中
SOLD
複数Marketplace
同一管理IDを複数行で表す（商品情報は共通、出品先ごとの行は個別）
カテゴリあり/なし
日本語
全角文字
カンマ
ダブルクォート
改行を含むメモ
```

手順:

```text
100件作成
↓
復元用CSV出力
↓
対象ユーザーデータ削除
↓
同CSVをPreview
↓
Commit
↓
復元後データ照合
```

期待:

- 復元可能項目100%一致
- 金額差0円
- SOLD日一致
- 管理ID一致
- 二重登録0
- 他ユーザー混入0

## 16.2 同一CSV二重取込

同じCSVを2回取り込む。

期待:

- 2回目は既存データをスキップ
- 二重Product 0
- 二重Listing 0
- 二重Sale 0

## 16.3 Yahoo実CSV Fixture

今回提供されたYahoo!オークション実CSVを元に匿名化Fixtureを作る。

最低限テスト:

- `売上金` → 取込
- `チャージ完了` → 除外
- `キャンセル` → 除外
- `振込手続き完了(...)` → 除外
- 商品ID `-` → 除外
- 落札システム利用料のみ存在
- 販売手数料のみ存在
- 送料 `-`
- 日本語日時
- CP932
- 重複商品ID

## 16.4 メルカリShops Fixture

今回提供された実ヘッダーをそのままFixture基準とする。

テスト用の人工データ行を作成し、

- 通常売上
- キャンセル日あり
- 送料2列
- 手数料
- 数量
- CP932
- 重複注文番号+明細番号

を検証する。

実売上行を入手できたら人工Fixtureとの差異を確認する。

## 16.5 セキュリティ

必須:

- User A CSVをUser Bへ取込不可
- User Bの管理IDとの衝突なし
- Formula Injection無効化
- 10MB超拒否
- 5,000行超拒否
- 不正文字コード
- malformed CSV
- 列不足
- 巨大セル
- 二重Commit
- 同時Commit

---

# 17. 1.0で実装するCSV機能

正式採用:

```text
CSV管理
├─ 売上CSV出力
├─ 全商品バックアップCSV
├─ 出品中のみCSV
├─ 復元用CSV
├─ FurimaDeck形式CSV一括登録
├─ Yahoo!オークション売上CSV変換・取込
└─ メルカリShops CSV変換・取込
```

会計CSV:

```text
確定申告・会計連携
├─ freee CSV
└─ Money Forward CSV
```

CSV管理とは別画面・別責務とする。

---

# 18. 実装禁止事項

以下は禁止する。

- 旧 `auction_items` をFurimaDeck新本体の正データとして復活させる
- CSV専用の利益計算式
- 外部CSVから仕入れ値を推測
- SOLD日が空なのに現在日を勝手に設定
- 同一管理IDを無条件上書き
- 他ユーザーデータとの照合
- Yahooの「売上確定」を取込条件としてハードコード
- `チャージ完了` を売上として取込
- `キャンセル` を売上として取込
- メルカリのキャンセル済み行を通常売上として取込
- HTTP 200だけでCSVテストをPASS
- MockだけでCSV Release GateをPASS
- 会計CSVとバックアップCSVを同一仕様にする

---

# 19. Codex実装指示

Codexは実装開始前に以下を確認すること。

1. 現行 `Product`
2. 現行 `Listing`
3. 現行 `Sale`
4. `Marketplace`
5. `ProductCsvImportController`
6. `FurimaDeckExportController`
7. 旧 `AuctionItemController`
8. 旧 `SalesController`
9. CSV管理Blade
10. 関連migration / unique index

旧FURUPROのCSV機能をコピーするのではなく、

```text
旧CSV互換層
        ↓
共通CSV DTO
        ↓
Product / Listing / Sale
```

の構造で実装する。

推奨構成例:

```text
app/Services/Csv/
├─ CsvEncodingService.php
├─ CsvSanitizer.php
├─ FurimaDeckCsvImporter.php
├─ FurimaDeckRestoreExporter.php
├─ FurimaDeckBackupExporter.php
├─ SalesCsvExporter.php
├─ YahooAuctionsCsvImporter.php
├─ MercariShopsCsvImporter.php
└─ CsvImportPreviewService.php
```

既存の安全なPreview → Commit設計は活かす。

旧FURUPRO互換性を壊さず、新FurimaDeckの新データモデルへ移植すること。

---

# 20. 完了条件

以下すべてを満たした時だけCSV機能完成とする。

```text
[ ] 旧FURUPRO復元用CSVを取込できる
[ ] 売上CSVを出力できる
[ ] 全商品バックアップCSVを出力できる
[ ] 出品中のみCSVを出力できる
[ ] 復元用CSVを出力できる
[ ] 出力→削除→復元Round Trip成功
[ ] Yahoo実CSVを取込できる
[ ] Yahooの非売上行を除外できる
[ ] メルカリShops CSVを取込できる
[ ] キャンセル行を除外できる
[ ] CP932を処理できる
[ ] UTF-8 BOMを処理できる
[ ] 重複取込で二重登録されない
[ ] User A/B完全分離
[ ] 金額差0円
[ ] SOLD日一致
[ ] CSV Formula Injection対策済み
[ ] Preview → Commit成立
[ ] 自動テストPASS
[ ] MariaDB統合テストPASS
[ ] ブラウザE2E PASS
```

1つでもP0/P1の未解決がある場合、CSV機能はRelease GOとしない。
