<?php

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\Marketplace;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductCsvImportCommitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.connections.sqlite');
        $connection['database'] = ':memory:';
        config()->set('database.connections.furimadeck', $connection);
        DB::purge('furimadeck');
        DB::setDefaultConnection('furimadeck');
        $this->artisan('migrate', [
            '--database' => 'furimadeck',
            '--path' => 'database/migrations/furimadeck',
        ])->assertSuccessful();
        config()->set('furimadeck.cutover_enabled', true);
        config()->set('furimadeck.product_management_enabled', true);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');

        parent::tearDown();
    }

    public function test_valid_preview_can_be_explicitly_committed_as_products(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $file = UploadedFile::fake()->createWithContent('products.csv', implode("\n", [
            'product_id,product_name,condition,purchase_unit_cost,purchase_quantity,quantity_available,inventory_status',
            'CSV-001,CSV商品,used,1000,2,2,in_stock',
        ]));

        $this->actingAs($user)
            ->post(route('products.imports.preview'), ['csv_file' => $file])
            ->assertRedirect();

        $batch = ImportBatch::query()->firstOrFail();
        $this->actingAs($user)
            ->post(route('products.imports.commit', $batch))
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', [
            'user_id' => $user->id,
            'internal_sku' => 'CSV-001',
            'product_name' => 'CSV商品',
            'quantity_available' => 2,
        ], 'furimadeck');
        $this->assertSame('completed', $batch->fresh()->status);
    }

    public function test_sold_csv_creates_sale_and_shows_sold_only_for_valid_completed_sale(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        Marketplace::query()->create(['code' => 'mercari', 'name' => 'メルカリ', 'base_url' => 'https://example.test', 'fee_type' => 'percentage', 'default_fee_rate' => 0, 'is_active' => true]);
        $file = UploadedFile::fake()->createWithContent('sold.csv', implode("\n", [
            'internal_sku,product_name,condition,purchase_unit_cost,purchase_quantity,quantity_available,inventory_status,marketplace_code,listed_at,listing_price,sale_state,sale_quantity,sold_price,sold_at,sales_fee,shipping_fee,sale_purchase_shipping_cost,packing_cost,repair_cost,cleaning_cost,sale_other_expense',
            'SOLD-001,売却済み,used,1000,1,0,out_of_stock,mercari,2026-09-10,4000,sold,1,3500,2026-09-20,350,750,0,0,0,0,0',
        ]));

        $this->actingAs($user)->post(route('products.imports.preview'), ['csv_file' => $file]);
        $batch = ImportBatch::query()->firstOrFail();
        $this->assertSame(0, $batch->failed_rows);

        $this->actingAs($user)->post(route('products.imports.commit', $batch))->assertRedirect(route('products.index'));
        $product = $user->products()->where('internal_sku', 'SOLD-001')->firstOrFail();
        $this->assertSame(0, $product->quantity_available);
        $sale = $user->sales()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('completed', $sale->status);
        $this->assertNotNull($sale->listing_id);
        $this->assertSame(1400, $sale->net_profit);

        $this->actingAs($user)->get(route('products.index'))->assertOk()->assertSee('SOLD');
    }

    public function test_stock_csv_with_listing_creates_an_active_listing_without_a_sale(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $file = UploadedFile::fake()->createWithContent('stock-listing.csv', implode("\n", [
            'internal_sku,product_name,condition,purchase_unit_cost,purchase_quantity,quantity_available,inventory_status,marketplace_code,listed_at,listing_price,sale_state',
            'LIST-001,出品中,used_good,1200,1,1,in_stock,mercari,2026-09-10,4500,stock',
        ]));

        $this->actingAs($user)->post(route('products.imports.preview'), ['csv_file' => $file]);
        $batch = ImportBatch::query()->firstOrFail();
        $this->assertSame(0, $batch->failed_rows);
        $this->actingAs($user)->post(route('products.imports.commit', $batch))->assertRedirect(route('products.index'));

        $product = $user->products()->where('internal_sku', 'LIST-001')->firstOrFail();
        $this->assertDatabaseHas('listings', ['product_id' => $product->id, 'status' => 'active', 'listing_price' => 4500], 'furimadeck');
        $this->assertSame(0, $user->sales()->where('product_id', $product->id)->count());
    }

    public function test_unified_csv_rejects_invalid_date_order_and_stock_sale_values(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $file = UploadedFile::fake()->createWithContent('invalid-unified.csv', implode("\n", [
            'internal_sku,product_name,condition,purchase_unit_cost,purchase_quantity,quantity_available,inventory_status,marketplace_code,listed_at,listing_price,sale_state,sale_quantity,sold_price,sold_at',
            'BAD-001,不正データ,used,1000,1,1,in_stock,mercari,2026-09-20,3000,stock,,1000,2026-09-21',
            'BAD-002,日付逆転,used,1000,1,0,out_of_stock,mercari,2026-09-20,3000,sold,1,2000,2026-09-19',
        ]));

        $this->actingAs($user)->post(route('products.imports.preview'), ['csv_file' => $file]);
        $batch = ImportBatch::query()->firstOrFail();
        $this->assertSame(2, $batch->failed_rows);
        $this->assertSame(0, $user->products()->count());
    }

    public function test_out_of_stock_without_valid_sale_does_not_show_sold(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $user->products()->create([
            'internal_sku' => 'NO-SALE-001', 'product_name' => '在庫なし', 'condition' => 'used',
            'purchase_unit_cost' => 1000, 'purchase_quantity' => 1, 'quantity_available' => 0,
            'inventory_status' => 'out_of_stock',
        ]);

        $this->actingAs($user)->get(route('products.index'))->assertOk()->assertDontSee('SOLD');
    }

    public function test_other_user_cannot_view_or_commit_a_csv_import_batch(): void
    {
        $owner = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $other = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $file = UploadedFile::fake()->createWithContent('private.csv', implode("\n", [
            'internal_sku,product_name,condition,purchase_unit_cost,purchase_quantity,quantity_available,inventory_status',
            'PRIVATE-001,非公開商品,used,1000,1,1,in_stock',
        ]));

        $this->actingAs($owner)
            ->post(route('products.imports.preview'), ['csv_file' => $file])
            ->assertRedirect();
        $batch = ImportBatch::query()->firstOrFail();

        $this->actingAs($other)
            ->get(route('products.imports.show', $batch))
            ->assertNotFound();
        $this->actingAs($other)
            ->post(route('products.imports.commit', $batch))
            ->assertNotFound();

        $this->assertSame(0, $other->products()->count());
        $this->assertSame('preview', $batch->fresh()->status);
    }

    public function test_existing_sku_prevents_the_entire_import_from_being_registered(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $user->products()->create([
            'internal_sku' => 'DUPLICATE-001',
            'product_name' => '既存商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $file = UploadedFile::fake()->createWithContent('products.csv', implode("\n", [
            'internal_sku,product_name,condition,purchase_unit_cost,purchase_quantity,quantity_available,inventory_status',
            'DUPLICATE-001,重複商品,used,1000,1,1,in_stock',
        ]));

        $this->actingAs($user)->post(route('products.imports.preview'), ['csv_file' => $file]);
        $batch = ImportBatch::query()->firstOrFail();

        $this->actingAs($user)
            ->from(route('products.imports.show', $batch))
            ->post(route('products.imports.commit', $batch))
            ->assertRedirect(route('products.imports.show', $batch))
            ->assertSessionHasErrors('csv_file');

        $this->assertSame(1, $user->products()->count());
        $this->assertSame('preview', $batch->fresh()->status);
    }

    public function test_minimum_furimadeck_headers_are_previewed_without_a_server_error(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $file = UploadedFile::fake()->createWithContent('minimum.csv', implode("\n", [
            'management_id,title',
            'MIN-001,最小形式の商品',
        ]));

        $response = $this->actingAs($user)
            ->post(route('products.imports.preview'), ['csv_file' => $file]);

        $batch = ImportBatch::query()->firstOrFail();
        $response->assertRedirect(route('products.imports.show', $batch));
        $this->assertSame(1, $batch->success_rows);
        $this->assertSame(0, $batch->failed_rows);
    }

    public function test_active_user_can_download_their_product_csv(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $user->products()->create([
            'internal_sku' => 'EXPORT-001',
            'product_name' => '出力商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);

        $response = $this->actingAs($user)
            ->get(route('furimadeck-export.products'))
            ->assertOk()
            ->assertDownload('products.csv');

        $this->assertStringContainsString('product_id,product_name', $response->streamedContent());
    }

    public function test_active_user_can_download_a_complete_product_backup_csv(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $user->products()->create([
            'internal_sku' => 'BACKUP-001',
            'product_name' => 'バックアップ商品',
            'condition' => 'used',
            'description_base' => '説明',
            'purchase_date' => '2026-01-02',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 2,
            'quantity_available' => 1,
            'purchase_shipping_cost' => 300,
            'other_purchase_expense' => 40,
            'inventory_status' => 'in_stock',
            'memo' => 'メモ',
        ]);

        $response = $this->actingAs($user)
            ->get(route('furimadeck-export.products-backup'))
            ->assertOk()
            ->assertDownload('furimadeck-products-backup.csv');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('internal_sku,product_name,category_id,condition', $csv);
        $this->assertStringContainsString('BACKUP-001,バックアップ商品,,used,説明,2026-01-02,1000,2,1,300,40', $csv);
    }

    public function test_product_csv_rejects_files_over_the_10_megabyte_limit(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $file = UploadedFile::fake()->createWithContent('oversize.csv', str_repeat('x', 10 * 1024 * 1024 + 1));

        $this->actingAs($user)
            ->post(route('products.imports.preview'), ['csv_file' => $file])
            ->assertRedirect()
            ->assertSessionHasErrors('csv_file');

        $this->assertSame(0, ImportBatch::query()->count());
    }

    public function test_product_csv_rejects_more_than_5000_rows(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $rows = ['internal_sku,product_name'];
        for ($index = 1; $index <= 5001; $index++) {
            $rows[] = 'LIMIT-'.$index.',制限確認商品'.$index;
        }
        $file = UploadedFile::fake()->createWithContent('too-many-rows.csv', implode("\n", $rows));

        $this->actingAs($user)
            ->post(route('products.imports.preview'), ['csv_file' => $file])
            ->assertRedirect()
            ->assertSessionHasErrors('csv_file');

        $this->assertSame(0, ImportBatch::query()->count());
    }

    public function test_product_csv_escapes_formula_like_cells(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $user->products()->create([
            'internal_sku' => '=IMPORTXML("https://example.test","//title")',
            'product_name' => "\t+SUM(1,1)",
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $user->products()->create([
            'internal_sku' => 'EXPORT-002',
            'product_name' => '＝SUM(1,1)',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);

        $response = $this->actingAs($user)->get(route('furimadeck-export.products'));

        $response->assertOk()->assertDownload('products.csv');

        $csv = $response->streamedContent();

        $this->assertStringContainsString("'=IMPORTXML(\"\"https://example.test\"\",\"\"//title\"\")", $csv);
        $this->assertStringContainsString("'\t+SUM(1,1)", $csv);
        $this->assertStringContainsString("'＝SUM(1,1)", $csv);
    }
}
