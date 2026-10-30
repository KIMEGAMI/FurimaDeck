<?php

namespace Tests\Feature;

use App\Models\AccountingEntry;
use App\Models\ImportBatch;
use App\Models\Marketplace;
use App\Models\User;
use App\Services\SaleProfitCalculator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    public function test_product_csv_legacy_accounting_fields_direct_users_to_accounting(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('products.imports.create'))
            ->assertOk()
            ->assertSee('旧CSVとの互換性について')
            ->assertSee('既存の商品CSVに含まれる仕入・売上経費の列は、これまでのCSVを読み込めるよう引き続き受け付けます。')
            ->assertSee(route('furimadeck-accounting.index', ['section' => 'purchase-info']), false)
            ->assertSee(route('furimadeck-accounting.index', ['section' => 'review']), false)
            ->assertSee('商品登録・編集画面では会計情報を入力しません。')
            ->assertSee('取込後の仕入情報は')
            ->assertSee('販売経費は');
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
        $this->assertTrue($user->products()->where('internal_sku', 'CSV-001')->firstOrFail()->purchase_info_confirmed);
        $this->assertSame('completed', $batch->fresh()->status);
    }

    public function test_minimal_product_csv_does_not_confirm_default_zero_purchase_cost(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $file = UploadedFile::fake()->createWithContent('minimal-products.csv', implode("\n", [
            'internal_sku,product_name',
            'CSV-NO-COST,原価未入力商品',
        ]));

        $this->actingAs($user)->post(route('products.imports.preview'), ['csv_file' => $file])->assertRedirect();
        $batch = ImportBatch::query()->firstOrFail();
        $this->assertSame(0, $batch->failed_rows);

        $this->actingAs($user)->post(route('products.imports.commit', $batch))->assertRedirect(route('products.index'));
        $product = $user->products()->where('internal_sku', 'CSV-NO-COST')->firstOrFail();

        $this->assertSame(0, $product->purchase_unit_cost);
        $this->assertFalse($product->purchase_info_confirmed);
    }

    public function test_sold_csv_requires_an_explicit_purchase_unit_cost(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $file = UploadedFile::fake()->createWithContent('sold-without-cost.csv', implode("\n", [
            'internal_sku,product_name,condition,purchase_quantity,quantity_available,inventory_status,marketplace_code,listed_at,listing_price,sale_state,sale_quantity,sold_price,sold_at',
            'CSV-SOLD-NO-COST,原価不明売却商品,used,1,0,out_of_stock,mercari,2026-09-10,4000,sold,1,3500,2026-09-20',
        ]));

        $this->actingAs($user)->post(route('products.imports.preview'), ['csv_file' => $file])->assertRedirect();
        $batch = ImportBatch::query()->firstOrFail();

        $this->assertSame(1, $batch->failed_rows);
        $this->assertSame(0, $batch->success_rows);
        $this->assertDatabaseMissing('products', ['user_id' => $user->id, 'internal_sku' => 'CSV-SOLD-NO-COST'], 'furimadeck');
        $this->assertStringContainsString(
            '原価不明の売却履歴は利益を正しく計算できないため登録できません。',
            implode(' ', $batch->rowResults()->firstOrFail()->errors_json),
        );
    }

    public function test_legacy_sold_csv_preview_without_explicit_cost_cannot_be_committed(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $batch = new ImportBatch([
            'batch_uuid' => (string) Str::uuid(),
            'filename' => 'old-sold-preview.csv',
            'format' => 'furimadeck_product_csv',
            'total_rows' => 1,
            'success_rows' => 1,
            'failed_rows' => 0,
            'status' => 'preview',
            'started_at' => now(),
        ]);
        $batch->user_id = $user->id;
        $batch->save();
        $batch->rowResults()->create([
            'row_number' => 2,
            'is_valid' => true,
            'row_json' => [
                'internal_sku' => 'CSV-LEGACY-SOLD-NO-COST',
                'product_name' => '原価未確認の旧プレビュー',
                'condition' => 'used',
                'purchase_unit_cost' => '0',
                'purchase_quantity' => '1',
                'quantity_available' => '0',
                'inventory_status' => 'out_of_stock',
                'marketplace_code' => 'mercari',
                'listed_at' => '2026-09-10',
                'listing_price' => '4000',
                'sale_state' => 'sold',
                'sale_quantity' => '1',
                'sold_price' => '3500',
                'sold_at' => '2026-09-20',
            ],
        ]);

        $this->actingAs($user)
            ->from(route('products.imports.show', $batch))
            ->post(route('products.imports.commit', $batch))
            ->assertRedirect(route('products.imports.show', $batch))
            ->assertSessionHasErrors('csv_file');

        $this->assertDatabaseMissing('products', ['user_id' => $user->id, 'internal_sku' => 'CSV-LEGACY-SOLD-NO-COST'], 'furimadeck');
        $this->assertSame(0, $user->sales()->count());
    }

    public function test_restore_csv_preview_and_commit_restore_sold_product_and_sale(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        Marketplace::query()->create([
            'code' => 'mercari', 'name' => 'メルカリ', 'base_url' => 'https://example.test',
            'fee_type' => 'percentage', 'default_fee_rate' => 10, 'is_active' => true,
        ]);
        $file = UploadedFile::fake()->createWithContent('furimadeck-restore.csv', implode("\n", [
            'management_id,title,comment,platform,parent_category,category,purchase_price,sold_price,sales_fee_rate,shipping_fee,status,sold_at',
            'RESTORE-SOLD-001,復元売却商品,復元コメント,メルカリ,,,1000,5000,10,750,sold,2026-09-20',
        ]));

        $this->actingAs($user)->post(route('products.imports.restore-preview'), ['csv_file' => $file])->assertRedirect();
        $batch = ImportBatch::query()->firstOrFail();
        $this->assertSame(0, $batch->failed_rows);

        $this->actingAs($user)->post(route('products.imports.commit', $batch))
            ->assertRedirect(route('products.index'));

        $product = $user->products()->where('internal_sku', 'RESTORE-SOLD-001')->firstOrFail();
        $this->assertSame(0, $product->quantity_available);
        $this->assertDatabaseHas('listings', [
            'product_id' => $product->id,
            'marketplace_id' => Marketplace::query()->where('code', 'mercari')->value('id'),
            'status' => 'sold',
            'listing_price' => 5000,
        ], 'furimadeck');
        $this->assertDatabaseHas('sales', [
            'user_id' => $user->id,
            'product_id' => $product->id,
            'sold_price' => 5000,
            'sales_fee' => 500,
            'shipping_fee' => 750,
            'net_profit' => 2750,
            'status' => 'completed',
        ], 'furimadeck');
        $sale = $user->sales()->where('product_id', $product->id)->firstOrFail();
        $entry = $user->accountingEntries()->where('sale_id', $sale->id)->firstOrFail();
        $this->assertSame(AccountingEntry::TYPE_SALE, $entry->entry_type);
        $this->assertSame('ready', $entry->source_status);
        $this->assertSame('not_synced', $entry->sync_status);
        $this->assertSame(5000, $entry->sale_amount);
        $this->assertSame(1000, $entry->purchase_cost);
        $this->assertSame('completed', $batch->fresh()->status);
    }

    public function test_restore_csv_rejects_sales_fee_rate_above_one_hundred_percent(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        Marketplace::query()->create([
            'code' => 'mercari', 'name' => 'メルカリ', 'base_url' => 'https://example.test',
            'fee_type' => 'percentage', 'default_fee_rate' => 10, 'is_active' => true,
        ]);
        $file = UploadedFile::fake()->createWithContent('furimadeck-restore-invalid.csv', implode("\n", [
            'management_id,title,comment,platform,parent_category,category,purchase_price,sold_price,sales_fee_rate,shipping_fee,status,sold_at',
            'RESTORE-BAD-RATE,不正料率商品,テスト,メルカリ,,,1000,5000,150,0,sold,2026-09-20',
        ]));

        $this->actingAs($user)->post(route('products.imports.restore-preview'), ['csv_file' => $file])->assertRedirect();
        $batch = ImportBatch::query()->firstOrFail();

        $this->assertSame(1, $batch->failed_rows);
        $this->assertDatabaseMissing('products', ['user_id' => $user->id, 'internal_sku' => 'RESTORE-BAD-RATE'], 'furimadeck');
    }

    public function test_restore_csv_rejects_truncated_fields_database_overflow_and_profit_overflow(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        Marketplace::query()->create([
            'code' => 'mercari', 'name' => 'メルカリ', 'base_url' => 'https://example.test',
            'fee_type' => 'percentage', 'default_fee_rate' => 10, 'is_active' => true,
        ]);
        $headers = 'management_id,title,comment,platform,parent_category,category,purchase_price,sold_price,sales_fee_rate,shipping_fee,status,sold_at';
        $rows = [
            'RESTORE-LONG-CELL,長文コメント商品,'.str_repeat('C', 1001).',メルカリ,,,1000,5000,10,0,sold,2026-09-20',
            str_repeat('S', 81).','.str_repeat('T', 256).',長い識別情報,メルカリ,,,1000,5000,10,0,sold,2026-09-20',
            'RESTORE-BIG-MONEY,金額上限商品,テスト,メルカリ,,,4294967296,5000,10,0,sold,2026-09-20',
            'RESTORE-BIG-PROFIT,利益上限商品,テスト,メルカリ,,,4000000000,4000000000,100,0,sold,2026-09-20',
        ];
        $file = UploadedFile::fake()->createWithContent('furimadeck-restore-boundaries.csv', implode("\n", [$headers, ...$rows]));

        $this->actingAs($user)->post(route('products.imports.restore-preview'), ['csv_file' => $file])->assertRedirect();
        $batch = ImportBatch::query()->firstOrFail();

        $this->assertSame(4, $batch->total_rows);
        $this->assertSame(4, $batch->failed_rows);
        $this->assertSame(0, $batch->success_rows);
        $this->assertSame(0, $user->products()->count());
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
        $entry = $user->accountingEntries()->where('sale_id', $sale->id)->firstOrFail();
        $this->assertSame('ready', $entry->source_status);
        $this->assertSame('not_synced', $entry->sync_status);
        $this->assertSame(3500, $entry->sale_amount);
        $this->assertSame(1000, $entry->purchase_cost);

        $this->actingAs($user)->get(route('products.index'))->assertOk()->assertSee('SOLD');
    }

    public function test_sold_csv_accepts_sales_fee_rate_when_sales_fee_amount_is_blank(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        Marketplace::query()->create([
            'code' => 'mercari', 'name' => 'メルカリ', 'base_url' => 'https://example.test',
            'fee_type' => 'percentage', 'default_fee_rate' => 95, 'is_active' => true,
        ]);
        $file = UploadedFile::fake()->createWithContent('sold-fee-rate.csv', implode("\n", [
            'internal_sku,product_name,condition,purchase_unit_cost,purchase_quantity,quantity_available,inventory_status,marketplace_code,listed_at,listing_price,sale_state,sale_quantity,sold_price,sold_at,sales_fee,sales_fee_rate,shipping_fee,sale_purchase_shipping_cost,packing_cost,repair_cost,cleaning_cost,sale_other_expense',
            'SOLD-RATE-001,料率指定商品,used,1000,1,0,out_of_stock,mercari,2026-09-10,4000,sold,1,3505,2026-09-20,,10.1,0,0,0,0,0,0',
        ]));

        $this->actingAs($user)->post(route('products.imports.preview'), ['csv_file' => $file]);
        $batch = ImportBatch::query()->firstOrFail();
        $this->assertSame(0, $batch->failed_rows);

        $this->actingAs($user)->post(route('products.imports.commit', $batch))->assertRedirect(route('products.index'));
        $sale = $user->sales()->whereHas('product', fn ($query) => $query->where('internal_sku', 'SOLD-RATE-001'))->firstOrFail();

        $this->assertSame(354, $sale->sales_fee);
        $this->assertSame(2151, $sale->net_profit);
    }

    public function test_sold_csv_rejects_sales_fee_rate_with_more_precision_than_saved_rate(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        Marketplace::query()->create([
            'code' => 'mercari', 'name' => 'メルカリ', 'base_url' => 'https://example.test',
            'fee_type' => 'percentage', 'default_fee_rate' => 0, 'is_active' => true,
        ]);
        $headers = 'internal_sku,product_name,condition,purchase_unit_cost,purchase_quantity,quantity_available,inventory_status,marketplace_code,listed_at,listing_price,sale_state,sale_quantity,sold_price,sold_at,sales_fee,sales_fee_rate,shipping_fee,sale_purchase_shipping_cost,packing_cost,repair_cost,cleaning_cost,sale_other_expense';
        $file = UploadedFile::fake()->createWithContent('sold-fee-rate-precision.csv', implode("\n", [
            $headers,
            'SOLD-RATE-PRECISION,料率精度確認商品,used,1000,1,0,out_of_stock,mercari,2026-09-10,110000,sold,1,100000,2026-09-20,,10.105,0,0,0,0,0,0',
        ]));

        $this->actingAs($user)->post(route('products.imports.preview'), ['csv_file' => $file]);
        $batch = ImportBatch::query()->firstOrFail();

        $this->assertSame(1, $batch->failed_rows);
        $this->assertContains('sales_fee_rateは0以上100以下、小数点以下2桁までの数値にしてください。', $batch->rowResults()->firstOrFail()->errors_json);
        $this->actingAs($user)->post(route('products.imports.commit', $batch))->assertStatus(422);
        $this->assertSame(0, $user->products()->count());
        $this->assertSame(0, $user->sales()->count());
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

    public function test_unified_csv_rejects_database_amount_overflow_and_unstorable_profit_in_preview(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        Marketplace::query()->create([
            'code' => 'mercari', 'name' => 'メルカリ', 'base_url' => 'https://example.test',
            'fee_type' => 'percentage', 'default_fee_rate' => 0, 'is_active' => true,
        ]);
        $headers = 'internal_sku,product_name,condition,purchase_unit_cost,purchase_quantity,quantity_available,inventory_status,marketplace_code,listed_at,listing_price,sale_state,sale_quantity,sold_price,sold_at,sales_fee,shipping_fee,sale_purchase_shipping_cost,packing_cost,repair_cost,cleaning_cost,sale_other_expense';
        $file = UploadedFile::fake()->createWithContent('amount-boundaries.csv', implode("\n", [
            $headers,
            'CSV-OVERFLOW,仕入単価超過,used,'.(SaleProfitCalculator::MAX_UNSIGNED_AMOUNT + 1).',1,1,in_stock,,,,stock,,,,,,,,,,',
            'CSV-COST-BASIS,原価積超過,used,3000000000,2,0,out_of_stock,mercari,2026-09-10,1000,sold,2,1000,2026-09-20,0,0,0,0,0,0,0',
            'CSV-PROFIT-OVERFLOW,利益超過,used,0,1,0,out_of_stock,mercari,2026-09-10,1000,sold,1,'.SaleProfitCalculator::MAX_UNSIGNED_AMOUNT.',2026-09-20,0,0,0,0,0,0,0',
        ]));

        $this->actingAs($user)->post(route('products.imports.preview'), ['csv_file' => $file])->assertRedirect();
        $batch = ImportBatch::query()->firstOrFail();

        $this->assertSame(3, $batch->total_rows);
        $this->assertSame(3, $batch->failed_rows);
        $this->assertSame(0, $user->products()->count());
        $messages = collect($batch->rowResults()->pluck('errors_json')->all())->flatten()->implode(' ');
        $this->assertStringContainsString('保存可能範囲', $messages);
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
            ->assertDownload('products.csv')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

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
            ->assertDownload('furimadeck-products-backup.csv')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

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
