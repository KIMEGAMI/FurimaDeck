<?php

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\Listing;
use App\Models\Marketplace;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\MarketplaceFeeService;
use App\Services\SaleProfitCalculator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FurimaDeckRestoreCsvTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (env('FURIMADECK_MARIADB_GATE') !== '1') {
            $connection = config('database.connections.sqlite');
            $connection['database'] = ':memory:';
            config()->set('database.connections.furimadeck', $connection);
        }
        config()->set('furimadeck.cutover_enabled', true);
        config()->set('furimadeck.product_management_enabled', true);
        DB::purge('furimadeck');
        DB::setDefaultConnection('furimadeck');
        $this->artisan(env('FURIMADECK_MARIADB_GATE') === '1' ? 'migrate:fresh' : 'migrate', ['--database' => 'furimadeck', '--path' => 'database/migrations/furimadeck', '--force' => true])->assertSuccessful();
        Marketplace::query()->create(['code' => 'yahoo_auctions', 'name' => 'Yahoo!オークション', 'base_url' => 'https://auctions.yahoo.co.jp/', 'fee_type' => 'percentage', 'default_fee_rate' => 10, 'is_active' => true]);
        Marketplace::query()->create(['code' => 'mercari', 'name' => 'メルカリ', 'base_url' => 'https://jp.mercari.com/', 'fee_type' => 'percentage', 'default_fee_rate' => 10, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');
        parent::tearDown();
    }

    public function test_restore_preview_and_commit_create_listing_and_sale(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $csv = implode("\n", ['management_id,title,comment,platform,parent_category,category,purchase_price,sold_price,sales_fee_rate,shipping_fee,status,sold_at', 'RESTORE-SELL,出品中商品,説明,ヤフオク,,,1000,2500,10,210,selling,', 'RESTORE-SOLD,売却済み商品,説明,ヤフオク,,,1200,3000,10,210,sold,2026-09-10']);
        $this->actingAs($user)->post(route('products.imports.restore-preview'), ['csv_file' => UploadedFile::fake()->createWithContent('restore.csv', $csv)])->assertRedirect();
        $batch = ImportBatch::query()->firstOrFail();
        $this->assertSame(2, $batch->success_rows);
        $this->assertSame(0, $batch->failed_rows);
        $this->actingAs($user)->post(route('products.imports.commit', $batch))->assertRedirect(route('products.index'));
        $this->assertSame(2, Product::query()->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('listings', ['user_id' => $user->id, 'status' => 'active', 'listing_price' => 2500], 'furimadeck');
        $this->assertDatabaseHas('sales', ['user_id' => $user->id, 'sold_price' => 3000, 'cost_basis' => 1200, 'sales_fee' => 300, 'shipping_fee' => 210, 'net_profit' => 1290], 'furimadeck');
    }

    public function test_restore_preview_rejects_inconsistent_product_data_for_repeated_management_id(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $csv = implode("\n", [
            'management_id,title,comment,platform,parent_category,category,purchase_price,sold_price,sales_fee_rate,shipping_fee,status,sold_at',
            'MULTI-001,商品,説明,ヤフオク,,,1000,2500,10,210,selling,',
            'MULTI-001,別商品,説明,メルカリ,,,1000,2600,10,750,selling,',
        ]);

        $this->actingAs($user)->post(route('products.imports.restore-preview'), [
            'csv_file' => UploadedFile::fake()->createWithContent('inconsistent-restore.csv', $csv),
        ])->assertRedirect();

        $batch = ImportBatch::query()->latest('id')->firstOrFail();
        $this->assertSame(2, $batch->failed_rows);
        $this->actingAs($user)->post(route('products.imports.commit', $batch))->assertStatus(422);
        $this->assertDatabaseCount('products', 0, 'furimadeck');
        $this->assertDatabaseCount('listings', 0, 'furimadeck');
    }

    public function test_restore_csv_uses_shared_marketplace_fee_rounding(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $csv = implode("\n", [
            'management_id,title,comment,platform,parent_category,category,purchase_price,sold_price,sales_fee_rate,shipping_fee,status,sold_at',
            'RESTORE-FEE,端数手数料,,ヤフオク,,,0,105,10,0,sold,2026-10-24',
        ]);

        $this->actingAs($user)->post(route('products.imports.restore-preview'), [
            'csv_file' => UploadedFile::fake()->createWithContent('restore-fee.csv', $csv),
        ])->assertRedirect();
        $batch = ImportBatch::query()->firstOrFail();
        $this->assertSame(1, $batch->success_rows);
        $this->actingAs($user)->post(route('products.imports.commit', $batch))->assertRedirect(route('products.index'));

        $sale = Sale::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(10, app(MarketplaceFeeService::class)->amountAtRate(105, 10));
        $this->assertSame(10, $sale->sales_fee);
        $this->assertSame('10.00', (string) $sale->sales_fee_rate);
        $this->assertSame(95, $sale->net_profit);
    }

    public function test_restore_export_uses_the_historical_sale_fee_rate(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $marketplace = Marketplace::query()->where('code', 'yahoo_auctions')->firstOrFail();
        $product = $user->products()->create([
            'internal_sku' => 'RESTORE-RATE',
            'product_name' => '手数料率履歴',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 0,
            'inventory_status' => 'out_of_stock',
        ]);
        $listing = $user->listings()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'listing_title' => $product->product_name,
            'listing_price' => 2000,
            'expected_fee_rate' => 10,
            'shipping_fee' => 210,
            'status' => 'sold',
        ]);
        $user->sales()->create([
            'product_id' => $product->id,
            'listing_id' => $listing->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => 1,
            'sold_price' => 2000,
            'sold_at' => '2026-10-24 10:00:00',
            'status' => 'completed',
            'cost_basis' => 1000,
            'sales_fee' => 170,
            'sales_fee_rate' => 8.5,
            'shipping_fee' => 210,
            'net_profit' => 620,
        ]);

        $csv = $this->actingAs($user)->get(route('furimadeck-export.restore-spec'))->streamedContent();
        $row = str_getcsv(explode("\n", trim($csv))[1]);

        $this->assertSame('8.5', $row[8]);
    }

    public function test_restore_export_delete_and_import_round_trip_with_100_products(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        for ($index = 1; $index <= 100; $index++) {
            $sku = sprintf('ROUND-%03d', $index);
            $name = $index % 2 === 0 ? '日本語,商品 "'.$index.'"' : '通常商品 '.$index;
            $product = $user->products()->create([
                'internal_sku' => $sku,
                'product_name' => $name,
                'condition' => 'used',
                'purchase_unit_cost' => 1000 + $index,
                'purchase_quantity' => 1,
                'quantity_available' => $index % 2 === 0 ? 0 : 1,
                'inventory_status' => $index % 2 === 0 ? 'out_of_stock' : 'listed',
                'memo' => $index % 2 === 0 ? "改行を含むメモ\n商品番号 {$index}" : '通常メモ',
            ]);
            $listing = $user->listings()->create([
                'product_id' => $product->id,
                'marketplace_id' => Marketplace::query()->where('code', 'yahoo_auctions')->value('id'),
                'listing_title' => $name,
                'listing_description' => $product->memo,
                'listing_price' => 3000 + $index,
                'expected_fee_rate' => 10,
                'shipping_fee' => 210,
                'status' => $index % 2 === 0 ? 'sold' : 'active',
            ]);
            if ($index % 10 === 1) {
                $user->listings()->create([
                    'product_id' => $product->id,
                    'marketplace_id' => Marketplace::query()->where('code', 'mercari')->value('id'),
                    'listing_title' => $name,
                    'listing_description' => $product->memo,
                    'listing_price' => 4000 + $index,
                    'expected_fee_rate' => 10,
                    'shipping_fee' => 750,
                    'status' => 'active',
                ]);
            }
            if ($index % 2 === 0) {
                $user->sales()->create([
                    'product_id' => $product->id,
                    'listing_id' => $listing->id,
                    'marketplace_id' => $listing->marketplace_id,
                    'internal_sku_snapshot' => $sku,
                    'product_name_snapshot' => $name,
                    'quantity' => 1,
                    'sold_price' => 3000 + $index,
                    'sold_at' => '2026-09-'.sprintf('%02d', ($index % 28) + 1).' 10:00:00',
                    'status' => 'completed',
                    'cost_basis' => 1000 + $index,
                    'sales_fee' => (int) round((3000 + $index) * 0.1),
                    'shipping_fee' => 210,
                ]);
            }
        }

        $csv = $this->actingAs($user)->get(route('furimadeck-export.restore-spec'))->streamedContent();
        $this->assertSame(110, Listing::query()->where('user_id', $user->id)->count());
        $this->assertSame(110, preg_match_all('/^ROUND-\d{3},/m', $csv));

        Sale::query()->where('user_id', $user->id)->delete();
        Listing::query()->where('user_id', $user->id)->delete();
        Product::query()->where('user_id', $user->id)->delete();

        $this->actingAs($user)->post(route('products.imports.restore-preview'), [
            'csv_file' => UploadedFile::fake()->createWithContent('round-trip.csv', $csv),
        ])->assertRedirect();
        $batch = ImportBatch::query()->latest('id')->firstOrFail();
        $this->assertSame(110, $batch->success_rows);
        $this->assertSame(0, $batch->failed_rows);
        $this->actingAs($user)->post(route('products.imports.commit', $batch))->assertRedirect(route('products.index'));

        $this->assertSame(100, Product::query()->where('user_id', $user->id)->count());
        $this->assertSame(110, Listing::query()->where('user_id', $user->id)->count());
        $this->assertSame(50, Sale::query()->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('products', ['user_id' => $user->id, 'internal_sku' => 'ROUND-002', 'product_name' => '日本語,商品 "2"', 'purchase_unit_cost' => 1002], 'furimadeck');
        $this->assertDatabaseHas('sales', ['user_id' => $user->id, 'internal_sku_snapshot' => 'ROUND-002', 'sold_price' => 3002, 'shipping_fee' => 210], 'furimadeck');
        $this->assertDatabaseHas('products', ['user_id' => $user->id, 'internal_sku' => 'ROUND-001', 'product_name' => '通常商品 1', 'purchase_unit_cost' => 1001], 'furimadeck');
    }

    public function test_restore_rejects_sold_row_without_sold_date(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $csv = "management_id,title,comment,platform,parent_category,category,purchase_price,sold_price,sales_fee_rate,shipping_fee,status,sold_at\nBAD-001,商品,,ヤフオク,,,1000,2000,10,0,sold,\n";
        $this->actingAs($user)->post(route('products.imports.restore-preview'), ['csv_file' => UploadedFile::fake()->createWithContent('invalid-restore.csv', $csv)])->assertRedirect();
        $batch = ImportBatch::query()->firstOrFail();
        $this->assertSame(1, $batch->failed_rows);
        $this->actingAs($user)->post(route('products.imports.commit', $batch))->assertStatus(422);
        $this->assertSame(0, Product::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, Sale::query()->where('user_id', $user->id)->count());
    }

    public function test_restore_rejects_profit_above_shared_calculator_limit_before_commit(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $csv = implode("\n", [
            'management_id,title,comment,platform,parent_category,category,purchase_price,sold_price,sales_fee_rate,shipping_fee,status,sold_at',
            'BAD-PROFIT,利益上限超過,,ヤフオク,,,0,'.SaleProfitCalculator::MAX_UNSIGNED_AMOUNT.',0,0,sold,2026-10-24',
        ]);

        $this->actingAs($user)->post(route('products.imports.restore-preview'), [
            'csv_file' => UploadedFile::fake()->createWithContent('profit-overflow.csv', $csv),
        ])->assertRedirect();

        $batch = ImportBatch::query()->firstOrFail();
        $this->assertSame(1, $batch->failed_rows);
        $this->actingAs($user)->post(route('products.imports.commit', $batch))->assertStatus(422);
        $this->assertSame(0, Product::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, Sale::query()->where('user_id', $user->id)->count());
    }

    public function test_restore_skips_existing_management_id_without_overwriting_it(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $user->products()->create(['internal_sku' => 'DUP-001', 'product_name' => '既存商品', 'condition' => 'used', 'purchase_unit_cost' => 500, 'purchase_quantity' => 1, 'quantity_available' => 1, 'inventory_status' => 'in_stock']);
        $csv = "management_id,title,comment,platform,parent_category,category,purchase_price,sold_price,sales_fee_rate,shipping_fee,status,sold_at\nDUP-001,上書き不可,,ヤフオク,,,9999,20000,10,0,selling,\n";
        $this->actingAs($user)->post(route('products.imports.restore-preview'), ['csv_file' => UploadedFile::fake()->createWithContent('duplicate.csv', $csv)]);
        $batch = ImportBatch::query()->firstOrFail();
        $this->assertSame(0, $batch->failed_rows);
        $this->actingAs($user)->post(route('products.imports.commit', $batch))->assertRedirect(route('products.index'));
        $this->assertSame(1, Product::query()->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('products', ['user_id' => $user->id, 'internal_sku' => 'DUP-001', 'product_name' => '既存商品'], 'furimadeck');
        $this->assertSame(1, $batch->fresh()->skipped_rows);
    }
}
