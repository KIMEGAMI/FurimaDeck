<?php

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\Marketplace;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FurimaDeckBackupIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $connection = config('database.connections.sqlite');
        $connection['database'] = ':memory:';
        config()->set('database.connections.furimadeck', $connection);
        DB::purge('furimadeck');
        DB::setDefaultConnection('furimadeck');
        $this->artisan('migrate', ['--database' => 'furimadeck', '--path' => 'database/migrations/furimadeck'])->assertSuccessful();
        config()->set('furimadeck.cutover_enabled', true);
        config()->set('furimadeck.product_management_enabled', true);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');
        parent::tearDown();
    }

    public function test_product_backup_contains_only_the_authenticated_users_products(): void
    {
        $owner = $this->premiumUser();
        $otherUser = $this->premiumUser();
        $owner->products()->create([...$this->productValues('OWNER-BACKUP-001'), 'product_name' => '所有者の商品']);
        $otherUser->products()->create([...$this->productValues('OTHER-BACKUP-001'), 'product_name' => '別ユーザーの商品']);

        $csv = $this->actingAs($owner)->get(route('furimadeck-export.products-backup'))->streamedContent();

        $this->assertStringContainsString('OWNER-BACKUP-001,所有者の商品', $csv);
        $this->assertStringNotContainsString('OTHER-BACKUP-001', $csv);
        $this->assertStringNotContainsString('別ユーザーの商品', $csv);
    }

    public function test_restore_export_uses_the_formal_restore_headers_and_values(): void
    {
        $owner = $this->premiumUser();
        $parent = ProductCategory::query()->create(['name' => '家電', 'slug' => 'home-appliances']);
        $category = ProductCategory::query()->create(['parent_id' => $parent->id, 'name' => 'カメラ', 'slug' => 'cameras']);
        $marketplace = Marketplace::query()->create([
            'code' => 'mercari',
            'name' => 'メルカリ',
            'base_url' => 'https://jp.mercari.com',
            'default_fee_rate' => 10,
        ]);
        $product = $owner->products()->create([
            ...$this->productValues('RESTORE-EXPORT-001'),
            'product_name' => '復元出力商品',
            'category_id' => $category->id,
            'purchase_unit_cost' => 3000,
            'memo' => '復元コメント',
        ]);
        $listing = $owner->listings()->create([
            'user_id' => $owner->id,
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'listing_title' => '復元出力商品',
            'listing_price' => 5000,
            'expected_fee_rate' => 10,
            'shipping_fee' => 750,
            'status' => 'active',
        ]);
        $owner->sales()->create([
            'user_id' => $owner->id,
            'product_id' => $product->id,
            'listing_id' => $listing->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => 1,
            'sold_price' => 5000,
            'sold_at' => '2026-09-20 10:00:00',
            'status' => 'completed',
            'cost_basis' => 3000,
            'sales_fee' => 500,
            'shipping_fee' => 750,
        ]);

        $csv = $this->actingAs($owner)->get(route('furimadeck-export.restore-spec'))->streamedContent();

        $this->assertStringContainsString('management_id,title,comment,platform,parent_category,category,purchase_price,sold_price,sales_fee_rate,shipping_fee,status,sold_at', $csv);
        $this->assertStringContainsString('RESTORE-EXPORT-001,復元出力商品,復元コメント,メルカリ,家電,カメラ,3000,5000,10,750,sold,2026-09-20', $csv);
    }

    public function test_other_user_cannot_view_or_commit_an_import_batch(): void
    {
        $owner = $this->premiumUser();
        $otherUser = $this->premiumUser();
        $csv = implode("\n", [
            'internal_sku,product_name,condition,purchase_unit_cost,purchase_quantity,quantity_available,inventory_status',
            'PRIVATE-IMPORT-001,所有者限定商品,used,1000,1,1,in_stock',
        ]);

        $this->actingAs($owner)->post(route('products.imports.preview'), [
            'csv_file' => UploadedFile::fake()->createWithContent('private.csv', $csv),
        ])->assertRedirect();
        $batch = ImportBatch::query()->firstOrFail();

        $this->actingAs($otherUser)->get(route('products.imports.show', $batch))->assertNotFound();
        $this->actingAs($otherUser)->post(route('products.imports.commit', $batch))->assertNotFound();
        $this->assertDatabaseMissing('products', ['user_id' => $otherUser->id, 'internal_sku' => 'PRIVATE-IMPORT-001'], 'furimadeck');
    }

    private function premiumUser(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
    }

    /** @return array<string, mixed> */
    private function productValues(string $sku): array
    {
        return [
            'internal_sku' => $sku,
            'product_name' => 'バックアップ商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ];
    }
}
