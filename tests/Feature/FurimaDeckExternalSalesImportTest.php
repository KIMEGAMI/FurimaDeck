<?php

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\Marketplace;
use App\Models\Sale;
use App\Models\User;
use App\Services\FurimaDeckExternalSalesImportService;
use App\Services\SaleLifecycleService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FurimaDeckExternalSalesImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $connection = config('database.connections.sqlite');
        $connection['database'] = ':memory:';
        config()->set('database.connections.furimadeck', $connection);
        config()->set('furimadeck.cutover_enabled', true);
        config()->set('furimadeck.product_management_enabled', true);
        DB::purge('furimadeck');
        DB::setDefaultConnection('furimadeck');
        $this->artisan('migrate', ['--database' => 'furimadeck', '--path' => 'database/migrations/furimadeck'])->assertSuccessful();
        Marketplace::query()->create(['code' => 'yahoo_auctions', 'name' => 'Yahoo!オークション', 'base_url' => 'https://auctions.yahoo.co.jp/', 'fee_type' => 'percentage', 'default_fee_rate' => 10, 'is_active' => true]);
        Marketplace::query()->create(['code' => 'mercari', 'name' => 'メルカリ', 'base_url' => 'https://jp.mercari.com/', 'fee_type' => 'percentage', 'default_fee_rate' => 10, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');
        parent::tearDown();
    }

    public function test_yahoo_auction_sales_csv_creates_completed_sale_and_is_idempotent(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $csv = "取扱内容,商品ID,取扱日,状態,決済金額,送料,落札システム利用料,販売手数料\nヤフオク商品,YAHOO-001,2026-09-01,売上金,2500,210,250,0\n";
        $this->actingAs($user)->post(route('furimadeck-sales.import.yahoo-auctions'), ['yahoo_csv_file' => UploadedFile::fake()->createWithContent('yahoo.csv', $csv)])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('products', ['user_id' => $user->id, 'internal_sku' => 'YAHOO-001', 'quantity_available' => 0], 'furimadeck');
        $this->assertDatabaseHas('sales', ['user_id' => $user->id, 'sold_price' => 2500, 'sales_fee' => 250, 'shipping_fee' => 210, 'net_profit' => 0, 'status' => 'pending'], 'furimadeck');
        $this->actingAs($user)->post(route('furimadeck-sales.import.yahoo-auctions'), ['yahoo_csv_file' => UploadedFile::fake()->createWithContent('yahoo.csv', $csv)]);
        $this->assertSame(1, $user->sales()->count());
    }

    public function test_yahoo_auctions_cp932_report_shape_parses_sales_and_skips_non_sales_rows(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $header = '取扱内容,商品ID,取扱日,状態,売上,決済金額,落札システム利用料,販売手数料,送料,受取金額';
        $rows = [$header];

        for ($index = 1; $index <= 54; $index++) {
            $salePrice = 1000 + ($index * 10);
            $systemFee = 100 + ($index % 3);
            $marketplaceFee = 50 + ($index % 5);
            $shippingFee = 200 + ($index % 7);
            $netAmount = $salePrice - $systemFee - $marketplaceFee - $shippingFee;
            $rows[] = implode(',', [
                '匿名テスト商品'.$index,
                'YAHOO-SYNTH-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                '2026-10-'.str_pad((string) (($index % 28) + 1), 2, '0', STR_PAD_LEFT),
                '売上金',
                $netAmount,
                $salePrice,
                $systemFee,
                $marketplaceFee,
                $shippingFee,
                '-',
                '',
            ]);
        }

        for ($index = 1; $index <= 34; $index++) {
            $rows[] = implode(',', ['チャージ', '-', '2026-10-01', 'チャージ完了', '-', '-', '-', '-', '-', '-', '']);
        }

        for ($index = 1; $index <= 7; $index++) {
            $rows[] = implode(',', ['振込', '-', '2026-10-01', '振込手続き完了(入金予定日：10/1)', '-', '-', '-', '-', '-', '-', '']);
        }

        for ($index = 1; $index <= 3; $index++) {
            $rows[] = implode(',', ['匿名テスト商品', 'YAHOO-CANCEL-'.$index, '2026-10-01', 'キャンセル', '-', '-', '-', '-', '-', '-', '']);
        }

        $csv = mb_convert_encoding(implode("\r\n", $rows)."\r\n", 'CP932', 'UTF-8');
        $this->actingAs($user)
            ->post(route('furimadeck-sales.import.yahoo-auctions-preview'), [
                'yahoo_csv_file' => UploadedFile::fake()->createWithContent('yahoo-auctions-cp932-anonymized.csv', $csv),
            ])
            ->assertRedirect();

        $batch = ImportBatch::query()->firstOrFail();
        $this->assertSame(98, $batch->total_rows);
        $this->assertSame(54, $batch->success_rows);
        $this->assertSame(44, $batch->skipped_rows);
        $this->assertSame(0, $batch->failed_rows);
        $firstPreview = $batch->rowResults()->orderBy('row_number')->firstOrFail()->row_json;
        $this->assertSame(1010, $firstPreview['sold_price']);
        $this->assertSame(152, $firstPreview['sales_fee']);
        $this->assertSame(201, $firstPreview['shipping_fee']);
        $this->assertSame(657, $firstPreview['sold_price'] - $firstPreview['sales_fee'] - $firstPreview['shipping_fee']);

        $this->actingAs($user)
            ->post(route('products.imports.commit', $batch))
            ->assertRedirect(route('products.imports.create'))
            ->assertSessionHas('success', '外部売上CSVを確定しました。登録 0件 / 要確認 54件 / スキップ 44件');

        $this->assertSame(54, $user->sales()->count());
        $this->assertSame(54, $user->products()->count());
        $this->assertDatabaseHas('sales', [
            'user_id' => $user->id,
            'internal_sku_snapshot' => 'YAHOO-SYNTH-001',
            'sold_price' => 1010,
            'sales_fee' => 152,
            'shipping_fee' => 201,
            'net_profit' => 0,
            'status' => 'pending',
            'quantity' => 1,
        ], 'furimadeck');
        $this->assertSame('completed', $batch->fresh()->status);
    }

    public function test_sales_page_exposes_external_csv_import_forms(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);

        $this->actingAs($user)
            ->get(route('furimadeck-sales.index'))
            ->assertOk()
            ->assertSee(route('furimadeck-sales.import.yahoo-auctions'))
            ->assertSee(route('furimadeck-sales.import.mercari-shops'))
            ->assertSee('multipart/form-data', false);
    }

    public function test_mercari_shops_cancelled_rows_are_skipped_and_valid_rows_are_imported(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $header = '注文番号,明細番号,明細種別,購入日,支払日,発送日,売上移転日,キャンセル日,商品名,数量,通貨,販売利益,売上（税込）,メルカリ便送料（税込）,送料（税込）,販売手数料（税込）,販売手数料率（%）,クーポン割引金額,クーポンID,ショップ名,インボイス対象金額合計（販売手数料 + メルカリ便送料）,インボイス対象税率,伝票番号,請求書発行事業者';
        $csv = implode("\n", [$header, 'ORDER-001,DETAIL-001,売上,2026-09-02,,,,,メルカリ商品,1,JPY,2250,3000,750,0,300,10,,,ショップ', 'ORDER-002,DETAIL-001,売上,2026-09-03,,,,2026-09-04,キャンセル商品,1,JPY,0,3000,0,0,300,10,,,ショップ']);
        $this->actingAs($user)->post(route('furimadeck-sales.import.mercari-shops'), ['mercari_shops_csv_file' => UploadedFile::fake()->createWithContent('mercari.csv', $csv)])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sales', ['user_id' => $user->id, 'sold_price' => 3000, 'sales_fee' => 300, 'shipping_fee' => 750, 'net_profit' => 0, 'quantity' => 1, 'status' => 'pending'], 'furimadeck');
        $this->assertSame(1, Sale::query()->where('user_id', $user->id)->count());
    }

    public function test_mercari_shops_multi_item_row_is_reported_without_creating_a_product_or_sale(): void
    {
        $user = User::factory()->create();
        $header = '注文番号,明細番号,明細種別,購入日,支払日,発送日,売上移転日,キャンセル日,商品名,数量,通貨,販売利益,売上（税込）,メルカリ便送料（税込）,送料（税込）,販売手数料（税込）,販売手数料率（%）,クーポン割引金額,クーポンID,ショップ名,インボイス対象金額合計（販売手数料 + メルカリ便送料）,インボイス対象税率,伝票番号,請求書発行事業者';
        $csv = implode("\n", [
            $header,
            'ORDER-MULTI,DETAIL-MULTI,売上,2026-09-02,,,,,同型商品の注文,2,JPY,2250,3000,750,0,300,10,,,ショップ',
        ]);

        $result = app(FurimaDeckExternalSalesImportService::class)->import(
            $user,
            UploadedFile::fake()->createWithContent('mercari.csv', $csv),
            'mercari_shops',
        );

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame(0, $user->products()->count());
        $this->assertSame(0, $user->sales()->count());
    }

    public function test_mercari_shops_invalid_quantity_is_reported_instead_of_being_assumed_as_one(): void
    {
        $user = User::factory()->create();
        $header = '注文番号,明細番号,明細種別,購入日,支払日,発送日,売上移転日,キャンセル日,商品名,数量,通貨,販売利益,売上（税込）,メルカリ便送料（税込）,送料（税込）,販売手数料（税込）,販売手数料率（%）,クーポン割引金額,クーポンID,ショップ名,インボイス対象金額合計（販売手数料 + メルカリ便送料）,インボイス対象税率,伝票番号,請求書発行事業者';
        $rows = [$header];

        foreach (['', '-1', 'abc', '1.5', '0', '01'] as $index => $quantity) {
            $rows[] = implode(',', [
                'ORDER-INVALID-'.$index,
                'DETAIL-INVALID-'.$index,
                '売上',
                '2026-09-02',
                '',
                '',
                '',
                '',
                '数量検証商品'.$index,
                $quantity,
                'JPY',
                '2250',
                '3000',
                '750',
                '0',
                '300',
                '10',
                '',
                '',
                'ショップ',
                '',
                '',
                'TRACK-INVALID-'.$index,
                '',
            ]);
        }

        $result = app(FurimaDeckExternalSalesImportService::class)->import(
            $user,
            UploadedFile::fake()->createWithContent('mercari-invalid-quantity.csv', implode("\n", $rows)),
            'mercari_shops',
        );

        $this->assertSame(0, $result['imported']);
        $this->assertSame(6, count($result['errors']));
        $this->assertSame(0, $user->products()->count());
        $this->assertSame(0, $user->sales()->count());
        $this->assertTrue(collect($result['errors'])->every(
            fn (string $error): bool => str_contains($error, '数量が1点ではありません'),
        ));
    }

    public function test_mercari_shops_preview_marks_invalid_quantity_as_failed_before_commit(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $header = '注文番号,明細番号,明細種別,購入日,支払日,発送日,売上移転日,キャンセル日,商品名,数量,通貨,販売利益,売上（税込）,メルカリ便送料（税込）,送料（税込）,販売手数料（税込）,販売手数料率（%）,クーポン割引金額,クーポンID,ショップ名,インボイス対象金額合計（販売手数料 + メルカリ便送料）,インボイス対象税率,伝票番号,請求書発行事業者';
        $csv = implode("\n", [
            $header,
            'ORDER-PREVIEW-INVALID,DETAIL-001,売上,2026-09-02,,,,,数量なし商品,,JPY,2250,3000,750,0,300,10,,,ショップ',
        ]);

        $this->actingAs($user)
            ->post(route('furimadeck-sales.import.mercari-shops-preview'), [
                'mercari_shops_csv_file' => UploadedFile::fake()->createWithContent('mercari-invalid-quantity-preview.csv', $csv),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $batch = ImportBatch::query()->firstOrFail();
        $row = $batch->rowResults()->firstOrFail();
        $this->assertSame(0, $batch->success_rows);
        $this->assertSame(1, $batch->failed_rows);
        $this->assertFalse($row->is_valid);
        $this->assertStringContainsString('数量が1点ではありません', implode(' ', $row->errors_json));
        $this->assertSame(0, $user->products()->count());
        $this->assertSame(0, $user->sales()->count());
    }

    public function test_mercari_shops_preview_rejects_duplicate_transaction_ids_with_conflicting_amounts(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $header = '注文番号,明細番号,明細種別,購入日,支払日,発送日,売上移転日,キャンセル日,商品名,数量,通貨,販売利益,売上（税込）,メルカリ便送料（税込）,送料（税込）,販売手数料（税込）,販売手数料率（%）,クーポン割引金額,クーポンID,ショップ名,インボイス対象金額合計（販売手数料 + メルカリ便送料）,インボイス対象税率,伝票番号,請求書発行事業者';
        $rows = [
            $header,
            'ORDER-CONFLICT,DETAIL-001,売上,2026-09-02,,,,,重複内容確認商品,1,JPY,2250,3000,750,0,300,10,,,ショップ,,,,TRACK-CONFLICT,',
            'ORDER-CONFLICT,DETAIL-001,売上,2026-09-02,,,,,重複内容確認商品,1,JPY,2249,2999,750,0,300,10,,,ショップ,,,,TRACK-CONFLICT,',
        ];

        $this->actingAs($user)
            ->post(route('furimadeck-sales.import.mercari-shops-preview'), [
                'mercari_shops_csv_file' => UploadedFile::fake()->createWithContent('mercari-conflicting-duplicate.csv', implode("\n", $rows)),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $batch = ImportBatch::query()->firstOrFail();
        $this->assertSame(1, $batch->success_rows);
        $this->assertSame(1, $batch->failed_rows);
        $conflictingRow = $batch->rowResults()->where('row_number', 3)->firstOrFail();
        $this->assertFalse($conflictingRow->is_valid);
        $this->assertStringContainsString('内容が一致しません', implode(' ', $conflictingRow->errors_json));
        $this->assertSame(0, $user->products()->count());
        $this->assertSame(0, $user->sales()->count());
    }

    public function test_mercari_shops_preview_skips_an_identical_duplicate_row(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $header = '注文番号,明細番号,明細種別,購入日,支払日,発送日,売上移転日,キャンセル日,商品名,数量,通貨,販売利益,売上（税込）,メルカリ便送料（税込）,送料（税込）,販売手数料（税込）,販売手数料率（%）,クーポン割引金額,クーポンID,ショップ名,インボイス対象金額合計（販売手数料 + メルカリ便送料）,インボイス対象税率,伝票番号,請求書発行事業者';
        $row = 'ORDER-EXACT-DUPLICATE,DETAIL-001,売上,2026-09-02,,,,,同一重複商品,1,JPY,2250,3000,750,0,300,10,,,ショップ,,,,TRACK-EXACT-DUPLICATE,';
        $csv = implode("\n", [$header, $row, $row]);

        $this->actingAs($user)
            ->post(route('furimadeck-sales.import.mercari-shops-preview'), [
                'mercari_shops_csv_file' => UploadedFile::fake()->createWithContent('mercari-identical-duplicate.csv', $csv),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $batch = ImportBatch::query()->firstOrFail();
        $this->assertSame(2, $batch->total_rows);
        $this->assertSame(1, $batch->success_rows);
        $this->assertSame(1, $batch->skipped_rows);
        $this->assertSame(0, $batch->failed_rows);

        $this->actingAs($user)->post(route('products.imports.commit', $batch))
            ->assertRedirect(route('products.imports.create'))
            ->assertSessionHas('success', '外部売上CSVを確定しました。登録 0件 / 要確認 1件 / スキップ 1件');

        $this->assertSame(1, $user->products()->count());
        $this->assertSame(1, $user->sales()->count());
    }

    public function test_mercari_shops_direct_import_reports_conflicting_duplicate_rows(): void
    {
        $user = User::factory()->create();
        $header = '注文番号,明細番号,明細種別,購入日,支払日,発送日,売上移転日,キャンセル日,商品名,数量,通貨,販売利益,売上（税込）,メルカリ便送料（税込）,送料（税込）,販売手数料（税込）,販売手数料率（%）,クーポン割引金額,クーポンID,ショップ名,インボイス対象金額合計（販売手数料 + メルカリ便送料）,インボイス対象税率,伝票番号,請求書発行事業者';
        $rows = [
            $header,
            'ORDER-DIRECT-CONFLICT,DETAIL-001,売上,2026-09-02,,,,,重複直接取込商品,1,JPY,2250,3000,750,0,300,10,,,ショップ,,,,TRACK-DIRECT-CONFLICT,',
            'ORDER-DIRECT-CONFLICT,DETAIL-001,売上,2026-09-02,,,,,重複直接取込商品,1,JPY,2249,2999,750,0,300,10,,,ショップ,,,,TRACK-DIRECT-CONFLICT,',
        ];

        $result = app(FurimaDeckExternalSalesImportService::class)->import(
            $user,
            UploadedFile::fake()->createWithContent('mercari-direct-conflicting-duplicate.csv', implode("\n", $rows)),
            'mercari_shops',
        );

        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, $result['skipped']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('内容が一致しません', $result['errors'][0]);
        $this->assertSame(1, $user->products()->count());
        $this->assertSame(1, $user->sales()->count());
    }

    public function test_mercari_shops_rejects_malformed_money_and_dates_without_zeroing_them(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $header = '注文番号,明細番号,明細種別,購入日,支払日,発送日,売上移転日,キャンセル日,商品名,数量,通貨,販売利益,売上（税込）,メルカリ便送料（税込）,送料（税込）,販売手数料（税込）,販売手数料率（%）,クーポン割引金額,クーポンID,ショップ名,インボイス対象金額合計（販売手数料 + メルカリ便送料）,インボイス対象税率,伝票番号,請求書発行事業者';
        $rows = [
            $header,
            'ORDER-AMOUNT-VALID,DETAIL-001,売上,2026年9月2日 17時06分,,,,,桁区切り確認商品,１,JPY,2250,"￥3,000",750円,0,300円,10,,,ショップ,,,,TRACK-AMOUNT-VALID,',
            'ORDER-AMOUNT-BAD-FEE,DETAIL-001,売上,2026-09-02,,,,,不正手数料商品,1,JPY,2250,3000,750,0,abc,10,,,ショップ,,,,TRACK-AMOUNT-BAD-FEE,',
            'ORDER-AMOUNT-BAD-PRICE,DETAIL-001,売上,2026-09-02,,,,,不正売価商品,1,JPY,2250,"1,2",750,0,300,10,,,ショップ,,,,TRACK-AMOUNT-BAD-PRICE,',
            'ORDER-AMOUNT-BAD-DATE,DETAIL-001,売上,31/31/2026,,,,,不正日付商品,1,JPY,2250,3000,750,0,300,10,,,ショップ,,,,TRACK-AMOUNT-BAD-DATE,',
            'ORDER-AMOUNT-OVERFLOW-DATE,DETAIL-001,売上,2026-02-31,,,,,存在しない日付商品,1,JPY,2250,3000,750,0,300,10,,,ショップ,,,,TRACK-AMOUNT-OVERFLOW-DATE,',
            'ORDER-AMOUNT-NEGATIVE,DETAIL-001,売上,2026-09-02,,,,,負数手数料商品,1,JPY,2250,3000,750,0,-50,10,,,ショップ,,,,TRACK-AMOUNT-NEGATIVE,',
            'ORDER-AMOUNT-OVERFLOW,DETAIL-001,売上,2026-09-02,,,,,保存上限超過商品,1,JPY,2250,3000,750,0,"4,294,967,296",10,,,ショップ,,,,TRACK-AMOUNT-OVERFLOW,',
        ];
        $file = UploadedFile::fake()->createWithContent('mercari-strict-money.csv', implode("\n", $rows));

        $this->actingAs($user)
            ->post(route('furimadeck-sales.import.mercari-shops-preview'), ['mercari_shops_csv_file' => $file])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $batch = ImportBatch::query()->firstOrFail();
        $this->assertSame(1, $batch->success_rows);
        $this->assertSame(6, $batch->failed_rows);
        $this->assertSame(0, $user->products()->count());
        $this->assertSame(0, $user->sales()->count());

        $result = app(FurimaDeckExternalSalesImportService::class)->import($user, $file, 'mercari_shops');

        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, $result['skipped']);
        $this->assertCount(6, $result['errors']);
        $this->assertSame(1, $user->products()->count());
        $sale = $user->sales()->firstOrFail();
        $this->assertSame(3000, $sale->sold_price);
        $this->assertSame(300, $sale->sales_fee);
        $this->assertSame(750, $sale->shipping_fee);
        $this->assertSame('2026-09-02', $sale->sold_at->toDateString());
    }

    public function test_mercari_shops_rejects_ambiguous_amounts_and_truncated_product_fields(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $headers = str_getcsv('注文番号,明細番号,明細種別,購入日,支払日,発送日,売上移転日,キャンセル日,商品名,数量,通貨,販売利益,売上（税込）,メルカリ便送料（税込）,送料（税込）,販売手数料（税込）,販売手数料率（%）,クーポン割引金額,クーポンID,ショップ名,インボイス対象金額合計（販売手数料 + メルカリ便送料）,インボイス対象税率,伝票番号,請求書発行事業者');
        $baseRow = array_fill_keys($headers, '');
        $baseRow['明細種別'] = '売上';
        $baseRow['購入日'] = '2026-09-02';
        $baseRow['数量'] = '1';
        $baseRow['売上（税込）'] = '￥3,000';
        $baseRow['メルカリ便送料（税込）'] = '750円';
        $baseRow['送料（税込）'] = '0';
        $baseRow['販売手数料（税込）'] = '300円';

        $makeRow = static function (string $orderId, string $detailId, string $title, array $overrides = []) use ($baseRow): array {
            return array_replace($baseRow, [
                '注文番号' => $orderId,
                '明細番号' => $detailId,
                '商品名' => $title,
            ], $overrides);
        };
        $rows = [
            $makeRow('ORDER-LENGTH-VALID', '01', '正常な取込商品'),
            $makeRow('ORDER-MONEY-SPACE', '01', '空白入り金額商品', ['販売手数料（税込）' => '1 2']),
            $makeRow('ORDER-MISSING-DETAIL', '', '明細番号なし商品'),
            $makeRow(str_repeat('A', 254), '01', '長すぎる管理ID商品'),
            $makeRow('ORDER-LONG-TITLE', '01', str_repeat('あ', 256)),
            $makeRow('ORDER-LONG-CELL', '01', 'セル長超過商品', ['請求書発行事業者' => str_repeat('あ', 1001)]),
        ];
        $stream = fopen('php://temp', 'r+b');
        fputcsv($stream, $headers);
        foreach ($rows as $row) {
            fputcsv($stream, array_map(static fn (string $header): string => $row[$header], $headers));
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        $file = UploadedFile::fake()->createWithContent('mercari-field-boundaries.csv', $csv);

        $this->actingAs($user)
            ->post(route('furimadeck-sales.import.mercari-shops-preview'), ['mercari_shops_csv_file' => $file])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $batch = ImportBatch::query()->firstOrFail();
        $this->assertSame(1, $batch->success_rows);
        $this->assertSame(5, $batch->failed_rows);
        $this->assertSame(0, $user->products()->count());
        $this->assertSame(0, $user->sales()->count());

        $result = app(FurimaDeckExternalSalesImportService::class)->import($user, $file, 'mercari_shops');

        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, $result['skipped']);
        $this->assertCount(5, $result['errors']);
        $this->assertSame(1, $user->products()->count());
        $this->assertSame('ORDER-LENGTH-VALID-01', $user->sales()->firstOrFail()->internal_sku_snapshot);
    }

    public function test_mercari_shops_full_width_one_quantity_is_normalized_to_one(): void
    {
        $user = User::factory()->create();
        $header = '注文番号,明細番号,明細種別,購入日,支払日,発送日,売上移転日,キャンセル日,商品名,数量,通貨,販売利益,売上（税込）,メルカリ便送料（税込）,送料（税込）,販売手数料（税込）,販売手数料率（%）,クーポン割引金額,クーポンID,ショップ名,インボイス対象金額合計（販売手数料 + メルカリ便送料）,インボイス対象税率,伝票番号,請求書発行事業者';
        $csv = implode("\n", [
            $header,
            'ORDER-FULLWIDTH,DETAIL-001,売上,2026-09-02,,,,,全角数量商品,１,JPY,2250,3000,750,0,300,10,,,ショップ,,,TRACK-FULLWIDTH,',
        ]);

        $result = app(FurimaDeckExternalSalesImportService::class)->import(
            $user,
            UploadedFile::fake()->createWithContent('mercari-fullwidth-quantity.csv', $csv),
            'mercari_shops',
        );

        $this->assertSame(1, $result['imported']);
        $this->assertSame([], $result['errors']);
        $this->assertDatabaseHas('sales', [
            'user_id' => $user->id,
            'quantity' => 1,
            'sold_price' => 3000,
        ], 'furimadeck');
    }

    public function test_mercari_shops_preview_commit_is_idempotent_for_the_same_order_detail(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $header = '注文番号,明細番号,明細種別,購入日,支払日,発送日,売上移転日,キャンセル日,商品名,数量,通貨,販売利益,売上（税込）,メルカリ便送料（税込）,送料（税込）,販売手数料（税込）,販売手数料率（%）,クーポン割引金額,クーポンID,ショップ名,インボイス対象金額合計（販売手数料 + メルカリ便送料）,インボイス対象税率,伝票番号,請求書発行事業者';
        $csv = implode("\n", [
            $header,
            'ORDER-IDEMPOTENT,DETAIL-001,売上,2026-09-02,,,,,同一取引の検証商品,1,JPY,2250,3000,750,0,300,10,,,ショップ,,,,TRACK-IDEMPOTENT,',
        ]);

        foreach ([1, 2] as $attempt) {
            $this->actingAs($user)
                ->post(route('furimadeck-sales.import.mercari-shops-preview'), [
                    'mercari_shops_csv_file' => UploadedFile::fake()->createWithContent('mercari-repeat-'.$attempt.'.csv', $csv),
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();

            $batch = ImportBatch::query()->latest('id')->firstOrFail();
            $this->assertSame(1, $batch->success_rows);
            $this->assertSame(0, $batch->failed_rows);

            $response = $this->actingAs($user)->post(route('products.imports.commit', $batch));
            $response->assertRedirect(route('products.imports.create'));
            if ($attempt === 1) {
                $response->assertSessionHas('success', '外部売上CSVを確定しました。登録 0件 / 要確認 1件 / スキップ 0件');
            } else {
                $response->assertSessionHas('success', '外部売上CSVを確定しました。登録 0件 / 要確認 0件 / スキップ 1件');
            }
        }

        $this->assertSame(1, $user->products()->count());
        $this->assertSame(1, $user->sales()->count());
        $this->assertDatabaseHas('sales', [
            'user_id' => $user->id,
            'internal_sku_snapshot' => 'ORDER-IDEMPOTENT-DETAIL-001',
            'sold_price' => 3000,
            'status' => 'pending',
        ], 'furimadeck');
    }

    public function test_yahoo_sales_csv_can_be_previewed_then_committed(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $product = $user->products()->create(['internal_sku' => 'PREVIEW-001', 'product_name' => 'ヤフオク商品', 'condition' => 'used', 'purchase_unit_cost' => 1000, 'purchase_quantity' => 1, 'quantity_available' => 1, 'inventory_status' => 'listed']);
        $user->listings()->create(['product_id' => $product->id, 'marketplace_id' => Marketplace::query()->where('code', 'yahoo_auctions')->value('id'), 'listing_title' => 'ヤフオク商品', 'external_listing_id' => 'PREVIEW-001', 'status' => 'active']);
        $header = '取扱内容,商品ID,取扱日,状態,売上,決済金額,落札システム利用料,販売手数料,送料,受取金額';
        $csv = $header."\nヤフオク商品,PREVIEW-001,2026-09-01,売上金,2500,2500,250,0,210,2040\n";

        $this->actingAs($user)->post(route('furimadeck-sales.import.yahoo-auctions-preview'), ['yahoo_csv_file' => UploadedFile::fake()->createWithContent('yahoo-preview.csv', $csv)])->assertRedirect();
        $batch = ImportBatch::query()->firstOrFail();
        $this->assertSame('furimadeck_external_yahoo_auctions', $batch->format);
        $this->assertSame(1, $batch->success_rows);
        $this->assertSame(0, $batch->failed_rows);

        $this->actingAs($user)->post(route('products.imports.commit', $batch))
            ->assertRedirect(route('products.imports.create'))
            ->assertSessionHas('success', '外部売上CSVを確定しました。登録 1件 / 要確認 0件 / スキップ 0件');
        $this->actingAs($user)->get(route('products.imports.create'))
            ->assertOk()
            ->assertSee('外部売上CSVを確定しました。登録 1件 / 要確認 0件 / スキップ 0件');
        $this->assertDatabaseHas('sales', ['user_id' => $user->id, 'internal_sku_snapshot' => 'PREVIEW-001', 'net_profit' => 1040], 'furimadeck');
        $sale = $user->sales()->where('internal_sku_snapshot', 'PREVIEW-001')->firstOrFail();
        $entry = $user->accountingEntries()->where('sale_id', $sale->id)->firstOrFail();
        $this->assertSame('ready', $entry->source_status);
        $this->assertSame('not_synced', $entry->sync_status);
        $this->assertSame(2500, $entry->sale_amount);
        $this->assertSame(1000, $entry->purchase_cost);
        $this->assertSame(0, $product->fresh()->quantity_available);
        $this->assertSame('sold', $user->listings()->firstOrFail()->fresh()->status);
        $this->assertSame('completed', $batch->fresh()->status);
    }

    public function test_unmatched_yahoo_sales_are_visible_in_product_list_without_fabricating_profit(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $header = '取扱内容,商品ID,取扱日,状態,売上,決済金額,落札システム利用料,販売手数料,送料,受取金額';
        $csv = $header."\n未照合商品,YAHOO-PENDING-001,2026-09-01,売上金,1000,1250,100,0,200,950\n";

        $this->actingAs($user)
            ->post(route('furimadeck-sales.import.yahoo-auctions-preview'), ['yahoo_csv_file' => UploadedFile::fake()->createWithContent('yahoo-pending.csv', $csv)])
            ->assertRedirect();
        $batch = ImportBatch::query()->firstOrFail();
        $this->actingAs($user)->post(route('products.imports.commit', $batch))->assertRedirect(route('products.imports.create'));

        $this->assertDatabaseHas('sales', [
            'user_id' => $user->id,
            'internal_sku_snapshot' => 'YAHOO-PENDING-001',
            'sold_price' => 1250,
            'status' => 'pending',
            'net_profit' => 0,
        ], 'furimadeck');
        $this->actingAs($user)->get(route('products.index'))
            ->assertOk()
            ->assertSee('¥1,250')
            ->assertDontSee('未確定の売上が1件あります。商品情報と仕入原価を確認するまで利益には含めません。')
            ->assertDontSee('会計確認待ちの販売記録');

        $this->actingAs($user)->get(route('furimadeck-accounting.index'))
            ->assertOk()
            ->assertSee('会計確認待ちの販売記録: 1件')
            ->assertSee('商品との照合または仕入情報が未確認の販売記録です。');

        $product = $user->products()->where('internal_sku', 'YAHOO-PENDING-001')->firstOrFail();
        $this->assertFalse($product->purchase_info_confirmed);
        $this->actingAs($user)->put(route('products.update', $product), [
            'internal_sku' => $product->internal_sku,
            'product_name' => $product->product_name,
            'condition' => $product->condition,
            'purchase_date' => '2026-08-20',
            'purchase_unit_cost' => 300,
            'purchase_shipping_cost' => 0,
            'other_purchase_expense' => 0,
        ])->assertRedirect(route('products.index'));

        $this->assertTrue($product->fresh()->purchase_info_confirmed);
        $this->assertDatabaseHas('sales', [
            'user_id' => $user->id,
            'product_id' => $product->id,
            'cost_basis' => 300,
            'net_profit' => 650,
            'status' => 'pending',
        ], 'furimadeck');
        $pendingSale = $user->sales()->where('product_id', $product->id)->firstOrFail();
        $this->assertDatabaseMissing('accounting_entries', [
            'user_id' => $user->id,
            'sale_id' => $pendingSale->id,
        ], 'furimadeck');
    }

    public function test_yahoo_ledger_import_uses_transaction_amount_and_reconciles_net_sales_amount(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $product = $user->products()->create([
            'internal_sku' => 'YAHOO-RECONCILE-001',
            'product_name' => 'Yahoo明細整合テスト',
            'condition' => 'used',
            'purchase_unit_cost' => 0,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $this->assertSame(1, (int) $product->fresh()->purchase_quantity);
        $this->assertSame(1, (int) $product->fresh()->quantity_available);
        $header = '取扱内容,商品ID,取扱日,状態,売上,決済金額,落札システム利用料,販売手数料,送料,受取金額';
        $csv = $header."\nYahoo明細整合テスト,YAHOO-RECONCILE-001,2026-09-01,売上金,689,999,100,-,210,-\n";

        $this->actingAs($user)->post(route('furimadeck-sales.import.yahoo-auctions-preview'), ['yahoo_csv_file' => UploadedFile::fake()->createWithContent('yahoo-ledger.csv', $csv)])->assertRedirect();
        $this->assertSame(1, (int) $product->fresh()->purchase_quantity);
        $this->assertSame(1, (int) $product->fresh()->quantity_available);
        $batch = ImportBatch::query()->firstOrFail();
        $this->actingAs($user)->post(route('products.imports.commit', $batch))->assertRedirect(route('products.imports.create'));

        $this->assertDatabaseHas('sales', [
            'user_id' => $user->id,
            'product_id' => $product->id,
            'sold_price' => 999,
            'sales_fee' => 100,
            'shipping_fee' => 210,
            'net_profit' => 689,
            'status' => 'completed',
        ], 'furimadeck');
        $this->assertSame(0, $product->fresh()->quantity_available);
        $this->assertTrue($product->fresh()->isSoldOutByValidSale());

        $this->actingAs($user)->post(route('furimadeck-sales.import.yahoo-auctions-preview'), ['yahoo_csv_file' => UploadedFile::fake()->createWithContent('yahoo-ledger-repeat.csv', $csv)])->assertRedirect();
        $repeat = ImportBatch::query()->latest('id')->firstOrFail();
        $this->actingAs($user)->post(route('products.imports.commit', $repeat))->assertRedirect(route('products.imports.create'));
        $this->assertSame(1, $user->sales()->count());
        $this->assertSame(0, $product->fresh()->quantity_available);
        $this->assertSame(1, $repeat->fresh()->skipped_rows);
    }

    public function test_multi_item_sale_csv_row_is_rejected_without_changing_product_or_sales(): void
    {
        $user = User::factory()->create();
        $product = $user->products()->create([
            'internal_sku' => 'ORDER-DETAIL', 'product_name' => '複数個の商品',
            'condition' => 'used', 'purchase_unit_cost' => 333,
            'purchase_quantity' => 1, 'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);

        $exception = null;
        try {
            app(FurimaDeckExternalSalesImportService::class)->commitParsed($user, [
                'management_id' => 'ORDER-DETAIL', 'title' => '複数個の商品',
                'quantity' => 2, 'sold_price' => 999, 'sales_fee' => 100,
                'shipping_fee' => 210, 'sold_at' => '2026-09-01 00:00:00',
            ], 'mercari_shops');
        } catch (\RuntimeException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertSame('売上CSVの数量が1点ではありません。商品IDごとの販売明細に分けてから取り込んでください。', $exception->getMessage());
        $this->assertSame(0, $user->sales()->count());
        $this->assertSame(1, $product->fresh()->quantity_available);
    }

    public function test_imported_sale_cancellation_restores_stock_and_moves_it_out_of_normal_accounting_csv(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $product = $user->products()->create([
            'internal_sku' => 'IMPORT-CANCEL', 'product_name' => '取消テスト',
            'condition' => 'used', 'purchase_unit_cost' => 200,
            'purchase_quantity' => 1, 'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $parsed = [
            'management_id' => 'IMPORT-CANCEL', 'title' => '取消テスト',
            'quantity' => 1, 'sold_price' => 1000, 'sales_fee' => 100,
            'shipping_fee' => 50, 'sold_at' => '2026-09-01 00:00:00',
        ];
        app(FurimaDeckExternalSalesImportService::class)->commitParsed($user, $parsed, 'yahoo_auctions');
        $sale = $user->sales()->firstOrFail();
        $this->assertSame(0, $product->fresh()->quantity_available);

        app(SaleLifecycleService::class)->cancel($user, $sale, 'テスト取消');

        $this->assertSame(1, $product->fresh()->quantity_available);
        $this->assertFalse($product->fresh()->isSoldOutByValidSale());
        $normalResponse = $this->actingAs($user)->get(route('furimadeck-accounting.csv', ['year' => 2026]))
            ->assertOk()
            ->assertDownload()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $normal = $normalResponse->streamedContent();
        $adjustmentsResponse = $this->actingAs($user)->get(route('furimadeck-accounting.adjustments.csv', ['year' => 2026]))
            ->assertOk()
            ->assertDownload()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $adjustments = $adjustmentsResponse->streamedContent();
        $this->assertStringNotContainsString('IMPORT-CANCEL', $normal);
        $this->assertStringContainsString('IMPORT-CANCEL', $adjustments);
    }

    public function test_unmatched_pending_import_cannot_create_phantom_stock_or_be_returned(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        app(FurimaDeckExternalSalesImportService::class)->commitParsed($user, [
            'management_id' => 'PENDING-CANCEL', 'title' => '未照合テスト',
            'quantity' => 1, 'sold_price' => 1000, 'sales_fee' => 100,
            'shipping_fee' => 50, 'sold_at' => '2026-09-01 00:00:00',
        ], 'yahoo_auctions');
        $sale = $user->sales()->firstOrFail();
        $product = $sale->product;
        $this->actingAs($user)->get(route('furimadeck-sales.index'))
            ->assertOk()->assertDontSee('返品・返金を記録');

        try {
            app(SaleLifecycleService::class)->returnSale($user, $sale, '未照合', 1000, 0, true);
            $this->fail('An unconfirmed import must not be returned or restocked.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('返品処理できません', $exception->getMessage());
        }
        $this->assertSame(0, $product->fresh()->quantity_available);

        app(SaleLifecycleService::class)->cancel($user, $sale, '未照合の取込を取消');
        $this->assertSame('cancelled', $sale->fresh()->status);
        $this->assertTrue($sale->fresh()->cancelled_from_pending);
        $this->assertSame(0, $product->fresh()->quantity_available);
        $this->actingAs($user)->get(route('furimadeck-sales.index'))
            ->assertOk()->assertSee('未照合取込の取消');
        $normal = $this->actingAs($user)->get(route('furimadeck-accounting.csv', ['year' => 2026]))->assertOk()->streamedContent();
        $adjustments = $this->actingAs($user)->get(route('furimadeck-accounting.adjustments.csv', ['year' => 2026]))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('PENDING-CANCEL', $normal);
        $this->assertStringNotContainsString('PENDING-CANCEL', $adjustments);
        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['section' => 'review', 'year' => 2026]))
            ->assertOk()->assertSee('未照合取込の取消: 1件')
            ->assertDontSee('取消・返品の照合: 1件');
    }

    public function test_mercari_header_only_report_is_rejected_with_actionable_message(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $header = '注文番号,明細番号,明細種別,購入日,支払日,発送日,売上移転日,キャンセル日,商品名,数量,通貨,販売利益,売上（税込）,メルカリ便送料（税込）,送料（税込）,販売手数料（税込）,販売手数料率（%）,クーポン割引金額,クーポンID,ショップ名,インボイス対象金額合計（販売手数料 + メルカリ便送料）,インボイス対象税率,伝票番号,請求書発行事業者';

        $this->actingAs($user)
            ->from(route('products.imports.create'))
            ->post(route('furimadeck-sales.import.mercari-shops-preview'), ['mercari_shops_csv_file' => UploadedFile::fake()->createWithContent('mercari-empty.csv', $header)])
            ->assertRedirect(route('products.imports.create'))
            ->assertSessionHasErrors(['mercari_shops_csv_file' => 'CSVに売上明細がありません。明細行を含むレポートを再ダウンロードしてください。']);

        $this->assertDatabaseCount('import_batches', 0, 'furimadeck');
    }

    public function test_unmatched_external_sale_is_pending_with_zero_profit_for_review(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $header = '取扱内容,商品ID,取扱日,状態,売上,決済金額,落札システム利用料,販売手数料,送料,受取金額';
        $csv = $header."\n未照合商品,UNMATCHED-001,2026-09-01,売上金,2500,2500,250,0,210,2040\n";

        $this->actingAs($user)->post(route('furimadeck-sales.import.yahoo-auctions-preview'), ['yahoo_csv_file' => UploadedFile::fake()->createWithContent('unmatched.csv', $csv)]);
        $batch = ImportBatch::query()->firstOrFail();
        $this->actingAs($user)->post(route('products.imports.commit', $batch))->assertRedirect(route('products.imports.create'));

        $this->assertDatabaseHas('sales', ['user_id' => $user->id, 'internal_sku_snapshot' => 'UNMATCHED-001', 'status' => 'pending', 'net_profit' => 0, 'cost_basis' => 0], 'furimadeck');
        $pendingSale = $user->sales()->where('internal_sku_snapshot', 'UNMATCHED-001')->firstOrFail();
        $this->assertDatabaseMissing('accounting_entries', [
            'user_id' => $user->id,
            'sale_id' => $pendingSale->id,
        ], 'furimadeck');
    }

    public function test_external_preview_rejects_rows_over_configured_limit(): void
    {
        config()->set('furimadeck.csv_import.max_rows', 1);
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $header = '取扱内容,商品ID,取扱日,状態,売上,決済金額,落札システム利用料,販売手数料,送料,受取金額';
        $csv = implode("\n", [$header, '商品1,ROW-001,2026-09-01,売上金,1000,1000,100,0,0,900', '商品2,ROW-002,2026-09-02,売上金,1000,1000,100,0,0,900']);

        $this->actingAs($user)
            ->post(route('furimadeck-sales.import.yahoo-auctions-preview'), ['yahoo_csv_file' => UploadedFile::fake()->createWithContent('too-many.csv', $csv)])
            ->assertRedirect()
            ->assertSessionHasErrors(['yahoo_csv_file']);

        $this->assertDatabaseCount('import_batches', 0, 'furimadeck');
    }

    public function test_yahoo_preview_accepts_the_legacy_six_column_export_and_common_date_format(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $csv = "取扱内容,商品ID,取扱日,状態,決済金額,送料\n旧形式ヤフオク商品,YAHOO-LEGACY-001,2026/09/01,売上金,2500,210,旧エクスポート余分列\n";

        $this->actingAs($user)
            ->post(route('furimadeck-sales.import.yahoo-auctions-preview'), ['yahoo_csv_file' => UploadedFile::fake()->createWithContent('yahoo-legacy.csv', $csv)])
            ->assertRedirect();

        $batch = ImportBatch::query()->firstOrFail();
        $this->assertSame(1, $batch->success_rows);
        $this->assertSame(0, $batch->failed_rows);
    }
}
