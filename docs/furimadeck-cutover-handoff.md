# FurimaDeck 公開切替ハンドオフ

## 状態

| 項目 | 状態 | 根拠 |
| --- | --- | --- |
| FurimaDeck 用の新規 DB | 実装済み・接続先の本番確認は未実施 | `config/furimadeck.php` と `docs/furimadeck-new-database.md` |
| FurimaDeck 用 Stripe Webhook | 実装済み・Stripe 側 endpoint の登録は未確認 | `POST /furimadeck/stripe/webhook` |
| FurimaDeck の本番公開 URL | DNS設定済み・HTTP/HTTPS到達不可 | `https://furimadeck.kimegami.jp` は `162.43.19.118` へ解決するが、2026-09-11時点で80/443番へ接続不可 |
| 旧 FURUPRO の廃止または併存 | 未確定 | 既存 `DEPLOY.md` は `furupro.shinji.work` を前提 |

本番URLは `https://furimadeck.kimegami.jp` として扱う。DNSは `162.43.19.118` へ解決するが、2026-09-11時点でHTTP/HTTPSに接続できない。VPSのWebサーバー設定、ファイアウォール、TLS証明書を確認するまで公開しない。

## 公開前の固定前提

- 旧 FURUPRO の DB・Stripe endpoint・OAuth redirect URI は、FurimaDeck の設定に流用しない。
- FurimaDeck は専用の空 DB を使い、旧 FURUPRO の DB バックアップを復元・移行しない。旧バックアップは保全と旧環境の復旧専用として保持する。
- 本番 `.env` のシークレットを開発環境やリポジトリへコピーしない。
- 本番 migration、既存画像の移設、監査ログの過去テキスト削除は、DB バックアップを人間が確認した後だけ実施する。

## 切替手順

### 1. 本番 VPS: バックアップと配置の確認

**作業場所:** 本番 VPS
**対象:** FurimaDeck の本番配置先と FurimaDeck 専用 DB
**操作:** 配置先、Git のレビュー済み commit、DB バックアップの作成日時・復元手順を人間が確認する。
**確認:** FurimaDeck の DB 接続先が旧 FURUPRO DB ではないこと。確認できなければ停止する。

### 2. 本番 VPS: 環境変数を設定

**作業場所:** 本番 VPS
**対象:** FurimaDeck の本番 `.env`（値は表示・共有しない）
**操作:** 次を実際の値で設定する。

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://furimadeck.kimegami.jp
APP_FORCE_HTTPS=true
SESSION_SECURE_COOKIE=true

DB_CONNECTION=furimadeck
FURIMADECK_CUTOVER_ENABLED=true
FURIMADECK_PRODUCT_MANAGEMENT_ENABLED=true

FURIMADECK_STRIPE_SECRET=<Stripe live secret>
FURIMADECK_STRIPE_WEBHOOK_SECRET=<FurimaDeck endpoint signing secret>
FURIMADECK_STRIPE_PRICE_ID=<active JPY monthly Price ID>
```

**確認:** `APP_URL` は `https://` の FurimaDeck 固有 URL、DB は FurimaDeck 専用、Stripe 値は同一 Live Mode の組合せであること。いずれか不明なら停止する。

### 3. 本番 VPS: DB schema とアプリ設定を確認

**作業場所:** 本番 VPS
**対象:** FurimaDeck 配置先
**操作:** DB バックアップ確認後、レビュー済みコードに対して migration を実施し、次を実行する。

```powershell
php artisan furimadeck:check-cutover
```

**確認:** すべて `OK` で終了すること。`NG` が一つでもあれば公開・DNS 切替を行わない。このコマンドはシークレット値を表示せず、ローカル設定がすべて有効な場合だけ Stripe API から Price を読み取り照合する。Price・契約・データは変更しない。

### 4. Stripe Dashboard: Webhook と Price を設定

**作業場所:** Stripe Dashboard
**対象:** `https://furimadeck.kimegami.jp/furimadeck/stripe/webhook`
**操作:** FurimaDeck 専用 endpoint を Live Mode で登録し、その endpoint 固有の署名シークレットを本番 VPS だけへ設定する。指定した Price が active、JPY、月額であることを確認する。
**確認:** `furimadeck:check-cutover` が Price を `OK` と判定し、Stripe のテストイベントが 2xx で受信・重複処理されないこと。旧 `/stripe/webhook` の設定を変更・削除しない。

### 5. Google Cloud Console: OAuth redirect URI を設定

**作業場所:** Google Cloud Console
**対象:** `https://furimadeck.kimegami.jp/auth/google/callback`
**操作:** FurimaDeck 用 OAuth クライアント、または併存可能な redirect URI として追加する。
**確認:** FurimaDeck の Google ログインと callback が成功すること。旧 FURUPRO の redirect URI は削除しない。

### 6. DNS / Apache: HTTPS を検証してから切替

**作業場所:** DNS プロバイダおよび本番 VPS
**対象:** FurimaDeck の確定ドメインと Apache VirtualHost
**操作:** DNS を新しい origin へ向け、証明書の対象名と HTTP→HTTPS リダイレクトを確認する。
**確認:** 証明書名、HTTPS、ログイン・ログアウト、メール認証、パスワードリセット、商品画像の所有者制限、Stripe Test Mode での決済・Webhook を検証する。問題があれば DNS を直前の健全な origin へ戻す。

## 既存データの保護作業（公開とは別工程）

以下は不可逆または既存データに影響するため、公開判定と分離する。実行前に DB バックアップと対象件数を人間が確認する。

```powershell
# いずれも最初は dry-run
php artisan furimadeck:secure-product-images
php artisan furimadeck:scrub-audit-log-text
```

dry-run の結果を確認後にだけ `--apply` を検討する。公開 URL で旧 public 画像が取得できなくなったことを確認するまでは、旧 public 画像ファイルを削除しない。

## 公開後の監視

- FurimaDeck Webhook の署名失敗、重複 event、5xx を監視する。
- 認証失敗、画像アクセス拒否、DB 接続失敗を PII を含めずに監視する。
- 旧 FURUPRO を廃止する決定が出るまで、旧 URL・DB・Webhook・OAuth callback を削除しない。
