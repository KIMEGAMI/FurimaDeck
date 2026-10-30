# FurimaDeck 商品画像ストレージ運用

更新日: 2026-10-25

## 現行設計

- 画像はアプリケーションで検証し、WebPに変換・縮小してからLaravel Filesystemへ保存する。
- メイン画像は長辺 `FURIMADECK_PRODUCT_IMAGE_MAX_DIMENSION` 以下、品質 `FURIMADECK_PRODUCT_IMAGE_WEBP_QUALITY`、サムネイルは長辺 `FURIMADECK_PRODUCT_IMAGE_THUMBNAIL_DIMENSION` 以下で生成する。
- 商品画像レコードには相対オブジェクトキーと論理ストレージディスク名を保存する。R2のURLは保存しない。
- 商品画像は非公開ストレージに置き、Web/APIは認証・所有者確認を行ってからアプリケーション経由で配信する。
- R2の署名付きURLは発行しない。画像URLへの各リクエストで所有者を再確認し、配信レスポンスには `private` と `no-store` を付けるため、署名URLの期限切れ更新処理は対象外。
- 新規のローカル開発環境は `FURIMADECK_PRODUCT_IMAGE_DISK=local` から開始する。開発バケットの接続確認と画像コピー・SHA-256照合を完了し、承認を得てから `furimadeck_r2_dev` へ切り替える。本番R2への切替は本番サーバーでの検証・承認後に `furimadeck_r2` を選ぶ。
- 開発用R2ディスク `furimadeck_r2_dev` は `PRODUCT_IMAGES_ACCESS_KEY_ID`、`PRODUCT_IMAGES_SECRET_ACCESS_KEY`、`PRODUCT_IMAGES_BUCKET`、`PRODUCT_IMAGES_ENDPOINT` と `PRODUCT_IMAGES_REGION` を使う。本番用R2ディスク `furimadeck_r2` は `FURIMADECK_R2_ACCESS_KEY_ID`、`FURIMADECK_R2_SECRET_ACCESS_KEY`、`FURIMADECK_R2_BUCKET`、`FURIMADECK_R2_ENDPOINT` と `FURIMADECK_R2_REGION` を使う。各環境の `.env` に対応する組だけを設定する。リージョンは通常 `auto`。
- R2バケットとトークンはFurimaDeck専用とし、開発トークンは開発バケットだけに対する `Object Read & Write`、本番トークンは本番バケットだけに対する `Object Read & Write` とする。公開バケットや公開URLは使用しない。403だけを根拠にENVの値や権限を原因と決めつけず、まず実際の要求形式、対象disk、バケット、endpointを確認する。
- アプリ環境によるディスク分離をサーバー側で強制する。非本番環境の新規保存・既存画像読取・移行元/先では本番R2を拒否し、本番環境の新規保存先は本番R2に限定、開発R2への読取・移行を拒否する。既存のローカル/公開画像の読取は互換性のため許可し、テスト環境だけはStorage fake等の検証用に両R2ディスクを選択可能とする。
- R2用ディスクはLaravel標準S3 driverでpath-style endpointを使い、公開URLを設定しない。R2 APIで未対応のACLを送らないよう、対応バージョンのFlysystem S3 adapterからAWS SDKの `before_upload` コールバックを呼び、`ACL` を除く。画像は2MB上限のため、マルチパートアップロードを無効化する。Cloudflare側でバケットを非公開に保ち、アプリケーションの認可付き配信を使う。AWS S3用ディスクの動作は変更しない。

## R2へ切り替える手順

1. 本番と開発の環境を分け、FurimaDeck専用の非公開R2バケットとオブジェクト読み書き用トークンを準備する。認証情報をGit、チャット、ログへ記録しない。
2. 開発環境では `PRODUCT_IMAGES_*` と開発バケットだけを使用する。本番環境では本番サーバー上の `FURIMADECK_R2_*` と本番バケットだけを使用する。本番用認証情報をローカル開発環境へコピーしない。
3. 開発環境で `php artisan furimadeck:verify-r2-dev` を実行する。このコマンドは開発用ディスクだけを使い、一時オブジェクトのPut/Get/Deleteと削除後の残存確認を行う。本番環境での実行は拒否する。成功するまで画像移行や保存先切替を行わない。
4. WindowsのローカルPHPでAWS SDKが `manifest.json` を読めない場合は、PHPが返す例外文だけではR2接続結果を判断しない。現在の開発環境では `is_readable()` が `false`、`fopen()` が成功する差が確認されたため、読み取り専用マウントのPHP 8.4 Dockerで検証する。

   ```powershell
   $projectRoot = (git rev-parse --show-toplevel).Trim()
   docker.exe run --rm --network bridge --mount "type=bind,source=$projectRoot,target=/app,readonly" --tmpfs /app/storage:rw,nosuid,nodev,size=64m --tmpfs /app/bootstrap/cache:rw,nosuid,nodev,size=16m --workdir /app php:8.4-cli-bookworm php artisan furimadeck:verify-r2-dev
   ```

   このコンテナは `.env` を含むプロジェクトを読み取り専用で参照する。環境変数や秘密値は表示しない。`AccessDenied (HTTP 403)` は現在の要求に対するR2応答として扱い、[Cloudflare R2のエラーコード](https://developers.cloudflare.com/r2/api/error-codes/)で意味を確認する。開発バケット・対象操作・トークンの許可範囲を照合し、値をチャットやログへ出さない。
5. 開発環境で `php artisan furimadeck:secure-product-images --source=local --target=furimadeck_r2_dev` を実行し、対象件数・コピー元合計容量・失敗件数を確認する。Dry-runはDBに記録された各画像ファイルの実在とサイズも検査する。失敗件数が0でない場合は適用しない。旧公開ディスク上の未分類画像は `--source=public --target=furimadeck_r2_dev` で別途確認する。
6. 開発環境のデータを移行するときだけ、バックアップを確認したうえで `php artisan furimadeck:secure-product-images --source=local --target=furimadeck_r2_dev --apply` を実行する。画像ごとにファイルサイズとSHA-256を比較し、成功した画像だけDBのディスク名を更新する。コピー元ファイルは削除しない。本番移行は別途承認後、本番サーバー上で本番バケットを対象に同じ手順を実施する。開発環境から本番ディスクを指定しない。
7. 失敗件数が0であることを確認し、開発環境で商品画面・サムネイル・拡張機能API・画像削除・商品削除を実画像で確認する。本番でも切替前に同じ機能を確認する。
8. 検証完了後に、開発環境は `FURIMADECK_PRODUCT_IMAGE_DISK=furimadeck_r2_dev`、本番サーバーは `FURIMADECK_PRODUCT_IMAGE_DISK=furimadeck_r2` へ切り替える。新しい画像は圧縮後に各環境のR2へ保存される。既存画像はレコードのディスク名に従って元の保存先から配信される。
9. R2移行が安定したことを確認するまで、コピー元のローカル画像を削除しない。削除は別途承認後に行う。

2026-10-24: 開発トークン `furimadeck-dev-storage` の権限を `Object Read & Write`、対象を `furimadeck-dev-images` のみに修正後、`furimadeck:verify-r2-dev` の実接続検証（Put/Get/Deleteと削除後の残存確認）が成功した。一時検証オブジェクトは残っていない。画像アクセス・所有者認可とR2画像フローの対象Featureテストは8件・85 assertionsで成功した。

同日、移行Dry-runは `local` が16件・2,923,868 bytes・失敗0件、`public` が0件・0 bytes・失敗0件。その後、利用者承認のもと `local` の16画像を `furimadeck_r2_dev` へコピーし、各画像のサイズとSHA-256を照合して、DBの保存先マーカーを16件更新した。元のローカル画像は削除していない。移行後にR2をコピー元としたDry-runも16件・2,923,868 bytes・失敗0件で完了した。開発 `.env` の保存先セレクターは `FURIMADECK_PRODUCT_IMAGE_DISK=furimadeck_r2_dev`。本番の設定・データ・バケットは変更していない。

移行後のテスト: `scripts/run-phpunit.ps1` の全体実行は933件中928件成功、5件スキップ、失敗・エラー0件（6,250 assertions）。開発 `.env` のR2選択がPHPUnitへ漏れないよう、テスト環境では画像ディスクを `local` に固定する。

開発R2実画像フロー: Windows PHPではAWS SDKの生成済みマニフェストに対し `is_readable()` が `false`、`fopen()` が成功するため、実画像の統合テストは一時PHP 8.4コンテナ（リポジトリ読み取り専用、テスト領域はtmpfs）で実行した。現在の開発 `.env` の保存先選択は上書きせず、開発バケット限定でWebP変換・保存・読戻し・所有者認可付きHTTP配信・他ユーザー拒否・画像削除を確認し、1テスト・19 assertionsが成功。テスト用画像は削除済み。vendorと本番環境は変更していない。

コピーコマンドは既定ではDry-runであり、`--apply` を付けない限りデータ・保存先マーカーを変更しない。Dry-runではDB参照の所有者・パス・ファイル実在・ファイルサイズを確認し、対象画像レコード数と合計バイト数を表示する。既存のコピー元ファイルはコマンドで削除しない。コピー先に同一キーの異なる内容があれば上書きせず失敗として扱う。

## PHPアップロード上限

1枚2MB・最大10枚を受け付けるには、実際にWebリクエストを処理するPHP環境で `upload_max_filesize` が2MB以上、`max_file_uploads` が10以上、`post_max_size` が画像合計20MBとmultipart分の余裕を超えていることを確認する。CLIの `php -i` の値だけではWebサーバー側の設定確認にならない。ローカルと本番それぞれのPHP実行環境で確認し、足りない場合はその環境のPHP設定を変更してからWeb経由で10枚アップロードを試験する。

## 切り戻し

切り戻し先ディスクが利用可能であることを確認し、対象の画像レコードを保持したまま、コピー元にR2、保存先に戻したいディスクを設定してDry-run後に `--apply` する。サイズとSHA-256の一致および画像表示を確認してから新規アップロードの設定を切り替える。R2上のファイルは別途確認・承認されるまで削除しない。

## 将来のIDrive e2移行候補

IDrive e2は将来候補であり、本書の作成時点では接続・契約・移行・二重保存を行わない。移行時はS3互換の独立したLaravel diskと環境変数を追加し、許可するストレージディスク一覧に登録したうえで、既存のコピーコマンドをR2からe2へ使う。Dry-run、件数・サイズ・SHA-256・画像表示・認可確認を行い、DBマーカーと新規保存先を段階的に切り替える。R2の削除は切り戻し期間終了後に別途承認を得て行う。

二重書き込み、自動マルチクラウド切替、IDrive e2専用の自動移行プログラムは実装しない。

## 2026-10-25 追加検証

- R2連携に関係する隔離テストを再実行し、商品画像の最大10枚保存・11枚目拒否・2MB超拒否・環境間R2ディスクの誤選択拒否・拡張機能API経由の非公開画像配信と所有者制限・商品削除時のR2画像削除・DB失敗時の孤立画像除去が成功した（10テスト・60 assertions）。これらは隔離SQLiteと `Storage::fake()` によるテストで、R2への実書込みではない。
- SDK復旧後の回帰確認は、画像/R2/アクセス/APIテスト56件中55件成功（660 assertions）、R2移行コマンドとAWSアップロードオプション11件成功（59 assertions）。合計67件中66件成功、1件は実R2接続テストの明示的スキップ。その後、同テストはテスト設定を直して別途成功している。PHPUnit設定のbootstrapはこの環境で `is_readable()` が誤ってfalseを返すため、vendorや設定ファイルを変えず `--no-configuration` と明示したtesting環境で実行した。
- PHP 8.5.12のローカル `php artisan serve` を使ったHTTP E2Eで、商品登録時の10画像選択・プレビュー・保存後の商品編集画面で10画像表示を確認した（1テスト成功）。専用SQLiteとローカル画像保存を使い、R2への書込みはしていない。CLIの値は `upload_max_filesize=64M`、`post_max_size=64M`、`max_file_uploads=20`。
- 実R2接続の証拠は開発バケットの1画像フロー（1テスト）に限る。10枚同時の実R2アップロードや本番アプリケーション経由の登録・表示は確認済みと扱わない。
- ローカルのPHP組込みサーバーは10画像のHTTP E2Eに成功したが、Apache等の別Web SAPIの設定が同一とは限らない。本番サーバーの `upload_max_filesize`、`post_max_size`、`max_file_uploads` と、本番R2を使った登録・表示は未確認。

2026-10-25 追加実接続確認:

- SDK 3.402.0復旧後、開発バケット限定の実R2テストを再実行し、1テスト・20 assertionsが成功した。WebP原本のPut/Get・寸法、サムネイル保存、所有者への非公開HTTP配信、別ユーザーの拒否、削除後のDB行・原本・サムネイル不在を確認した。テスト終了時の後始末も実行されている。本番バケット・本番DBは使用していない。
- 初回実行時はテストの画像処理先がローカルのままなのにDB行を開発R2としていたため `NoSuchKey` になった。テストが画像処理前に開発R2ディスクを選択するよう修正して再実行した。アプリ本体の保存処理の変更ではない。
- MariaDB CSVゲートは専用の一時コンテナと専用DBで4テスト・42 assertions成功。全体テスト933件は928成功・5スキップで、その後この実R2テストを個別に成功させた。スキップのうちMariaDBゲート4件も別途実行済み。本番への接続・変更はしていない。
