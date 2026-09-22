<?php

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\Marketplace;
use App\Models\Sale;
use App\Models\User;
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
        $header = '注文番号,明細番号,明細種別,購入日,支払日,発送日,売上移転日,キャンセル日,商品名,数量,通貨,販売利益,売上（税込）,メルカリ便送料（税込）,送料（税込）,販売手数料（税込）,販売手数料率（%）,クーポン割引金額,クーポンID,ショップ名';
        $csv = implode("\n", [$header, 'ORDER-001,DETAIL-001,売上,2026-09-02,,,,,メルカリ商品,2,JPY,2250,3000,750,0,300,10,,,ショップ', 'ORDER-002,DETAIL-001,売上,2026-09-03,,,,2026-09-04,キャンセル商品,1,JPY,0,3000,0,0,300,10,,,ショップ']);
        $this->actingAs($user)->post(route('furimadeck-sales.import.mercari-shops'), ['mercari_shops_csv_file' => UploadedFile::fake()->createWithContent('mercari.csv', $csv)])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sales', ['user_id' => $user->id, 'sold_price' => 3000, 'sales_fee' => 300, 'shipping_fee' => 750, 'net_profit' => 0, 'quantity' => 2, 'status' => 'pending'], 'furimadeck');
        $this->assertSame(1, Sale::query()->where('user_id', $user->id)->count());
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

        $this->actingAs($user)->post(route('products.imports.commit', $batch))->assertRedirect(route('furimadeck-sales.index'));
        $this->assertDatabaseHas('sales', ['user_id' => $user->id, 'internal_sku_snapshot' => 'PREVIEW-001', 'net_profit' => 1040], 'furimadeck');
        $this->assertSame('completed', $batch->fresh()->status);
    }

    public function test_unmatched_external_sale_is_pending_with_zero_profit_for_review(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $header = '取扱内容,商品ID,取扱日,状態,売上,決済金額,落札システム利用料,販売手数料,送料,受取金額';
        $csv = $header."\n未照合商品,UNMATCHED-001,2026-09-01,売上金,2500,2500,250,0,210,2040\n";

        $this->actingAs($user)->post(route('furimadeck-sales.import.yahoo-auctions-preview'), ['yahoo_csv_file' => UploadedFile::fake()->createWithContent('unmatched.csv', $csv)]);
        $batch = ImportBatch::query()->firstOrFail();
        $this->actingAs($user)->post(route('products.imports.commit', $batch))->assertRedirect(route('furimadeck-sales.index'));

        $this->assertDatabaseHas('sales', ['user_id' => $user->id, 'internal_sku_snapshot' => 'UNMATCHED-001', 'status' => 'pending', 'net_profit' => 0, 'cost_basis' => 0], 'furimadeck');
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
