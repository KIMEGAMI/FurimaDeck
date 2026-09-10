# FurimaDeck コミット分離マニフェスト

## 目的

この文書は、現在の作業ツリーから FurimaDeck の変更を安全にレビュー・
コミットするための分類である。stage、commit、push は実行しない。

## FurimaDeck 専用の追加ファイル

次の新規ファイル／ディレクトリは FurimaDeck 実装に属する。内容をレビュー
したうえで、同一の FurimaDeck コミットに含める候補とする。

- `app/Console/Commands/CheckFurimaDeckCutover.php`
- `app/Console/Commands/ScrubFurimaDeckAuditLogText.php`
- `app/Console/Commands/SecureFurimaDeckProductImages.php`
- `app/Http/Controllers/FurimaDeck*.php`
- `app/Http/Controllers/{ListingController,ProductController,ProductCsvImportController,SupplierController}.php`
- `app/Http/Middleware/EnsureFurimaDeck*.php`
- `app/Http/Middleware/{EnsureFuruproLegacyDisabled,RedirectLegacyDashboardDuringFurimaDeckCutover}.php`
- `app/Models/{AuditLog,ImportBatch,ImportRowResult,Listing,Marketplace,Product,ProductAttributeValue,ProductCategory,ProductImage,Sale,Supplier}.php`
- `app/Services/{AuditLogger,FurimaDeck*,Listing*,Marketplace*,ProductCsvPreviewService,ProductImageProcessor,Sale*,TodayWorkService}.php`
- `config/furimadeck.php`
- `database/migrations/furimadeck/` と `2026_09_10_000001_add_storage_disk_to_product_images.php`
- `database/seeders/FurimaDeck*.php`
- `resources/views/furimadeck_*/`, `resources/views/products/`, `resources/views/listings/`, `resources/views/product_imports/`, `resources/views/suppliers/`
- `tests/Feature/FurimaDeck*.php`、FurimaDeck 関連の Product／Listing／Sale テスト、`tests/Unit/FurimaDeck*.php`
- `docs/furimadeck-*.md` と `pint.json`

## 既存変更と混在するファイル

以下は旧 FURUPRO の既存実装または既存ユーザー変更を含むため、内容確認なしに
FurimaDeck コミットへ stage しない。

- `.env.example`、`routes/web.php`、`bootstrap/app.php`、`config/database.php`
- `app/Models/User.php`、`app/Providers/AppServiceProvider.php`
- 既存認証・管理・オークション・プロフィール・SEO controller
- 既存 Blade レイアウト、ナビゲーション、公開ページ、法的ページ
- 既存オークション／認証のテスト
- `.github/workflows/ci.yml`

これらは `git diff -- <path>` で FurimaDeck に必要な hunk だけを人間が確認し、
必要なら機能単位に分割してから stage する。

### UI・公開導線・CIの追加確認対象

今回のUI復旧と切替後の公開導線に関するhunkは、上記の混在ファイルから次の単位で
確認する。

- `routes/web.php` のFurimaDeck公開お問い合わせルート
- `app/Http/Controllers/PwaController.php`、`resources/views/pwa/service-worker.blade.php`、`config/pwa.php` のPWA再接続導線とキャッシュ世代
- `config/legal.php`、`resources/views/components/legal-layout.blade.php`、`config/seo.php` の公開文書・SEO更新日
- `README.md`、`DEPLOY.md`、`package.json`、`package-lock.json` の名称・ローカル起動・旧デプロイ手順の注意
- `tests/Feature/{ContactFormTest,ExampleTest,FurimaDeckPublicRouteTest}.php` と `tests/Unit/AuditLoggerTest.php` の回帰テスト

## 明示的に除外するもの

- `.env`、本番シークレット、DB ダンプ
- `dummy_auction_items_*.csv`（ダミーデータの取扱いを別途確認するまで）
- 旧 FURUPRO の廃止・移行だけを目的とする変更

## コミット前の必須確認

```powershell
npm run build
vendor/bin/pint --test
php artisan test
git diff --check
php artisan furimadeck:check-cutover
```

最後のコマンドは本番環境の設定を変更しないが、Stripe Price を読み取り照合する。
本番で実行する前に、DB バックアップを人間が確認する。
