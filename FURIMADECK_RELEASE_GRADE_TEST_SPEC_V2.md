# FurimaDeck 1.0 Release Grade Test Specification

最終仕様書 `FurimaDeck_1.0_FINAL_SPEC_2026-09-19.md` に基づくリリース判定基準です。

## 1. 実行コマンド

```powershell
php artisan test
php artisan view:cache
php artisan route:list --path=furimadeck
composer validate --no-check-publish
composer audit --locked --no-interaction
npm audit --audit-level=high
```

CIでは、PHP構文、フロントエンドビルド、Pint、PHPUnit、PHP/Node依存関係監査を必須とします。

## 2. P0 Release Gate

1件でも残っている場合はNO-GOです。

- ログイン、ログアウト、商品登録、Sale登録が成功する
- Stripe Checkout、Customer Portal、Webhookの状態同期が成功する
- ユーザーA/B間で商品、Listing、Sale、CSV、会計データが混入しない
- IDOR、主要画面500、データ消失がない
- 商品バックアップとCSV復元が成功する
- 旧FURUPRO経路がFurimaDeckのデータを破壊しない

## 3. P1 Release Gate

原則1件でも残っている場合はNO-GOです。

- Googleログイン、メール認証、パスワード再設定が成功する
- Resendの本番送信と失敗時の画面表示が確認できる
- 売上、原価、手数料、送料、その他費用、実利益の金額差が0円になる
- 年次・任意期間の境界が日本時間で正しい
- 高度分析と販売改善がログインユーザー自身のデータだけを参照する
- freee CSV、Money Forward CSVの借方・貸方金額が一致する
- freee OAuth、Refresh、解除、Review、Confirm、Send、二重送信防止が成功する
- Money Forward OAuth、Tenant確認、解除、CSV出力が成功する

## 4. 会計の境界値

次のデータで2026年分が90,000円になることを確認します。

| 日付 | 金額 | 2026年分 |
| --- | ---: | --- |
| 2025/12/31 | 10,000円 | 対象外 |
| 2026/01/01 | 20,000円 | 対象 |
| 2026/06/15 | 30,000円 | 対象 |
| 2026/12/31 | 40,000円 | 対象 |
| 2027/01/01 | 50,000円 | 対象外 |

## 5. 外部環境での確認が必要な項目

以下はコードテストだけでは完了判定できません。実際の環境で確認して記録します。

- Google OAuthの承認済みURI
- Resendの認証済みドメインと本番送信
- Stripe本番キー、Price、Customer Portal、Webhook
- HTTPS、Apache、DNS、証明書
- FurimaDeck本番DBのバックアップと復元
- 本番VPSのPHP、MariaDB、権限、書込先
- ブラウザでの主要画面とモバイル表示

## 6. 判定ルール

- 必須テストが1件でも未実行ならGOにしない
- 「設定済み」と「接続・送信確認済み」を分けて記録する
- 外部サービスの実接続未確認は未完了として扱う
- 本番デプロイは、CI成功・レビュー済み・バックアップ確認後に手動実行する
- 旧サーバー向けCDは使用しない
