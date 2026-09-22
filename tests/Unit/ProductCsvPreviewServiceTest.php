<?php

namespace Tests\Unit;

use App\Services\ProductCsvPreviewService;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ProductCsvPreviewServiceTest extends TestCase
{
    #[Test]
    public function it_separates_valid_and_invalid_rows(): void
    {
        $file = UploadedFile::fake()->createWithContent('products.csv', implode("\n", [
            'internal_sku,product_name,condition,purchase_unit_cost,purchase_quantity,quantity_available,inventory_status',
            'SKU-1,正常商品,used,1000,1,1,in_stock',
            'SKU-2,不正商品,unknown,1000,0,1,in_stock',
        ]));

        $preview = app(ProductCsvPreviewService::class)->preview($file);

        $this->assertCount(2, $preview['rows']);
        $this->assertSame([], $preview['rows'][0]['errors']);
        $this->assertNotEmpty($preview['rows'][1]['errors']);
    }

    #[Test]
    public function it_rejects_a_file_without_required_headers(): void
    {
        $file = UploadedFile::fake()->createWithContent('products.csv', "product_name\n商品");

        $this->expectException(RuntimeException::class);
        app(ProductCsvPreviewService::class)->preview($file);
    }

    #[Test]
    public function it_accepts_a_utf8_bom_and_marks_duplicate_skus_invalid(): void
    {
        $file = UploadedFile::fake()->createWithContent('products.csv', implode("\n", [
            "\xEF\xBB\xBFinternal_sku,product_name,condition,purchase_unit_cost,purchase_quantity,quantity_available,inventory_status",
            'SKU-1,商品A,used,1000,1,1,in_stock',
            'SKU-1,商品B,used,1000,1,1,in_stock',
        ]));

        $preview = app(ProductCsvPreviewService::class)->preview($file);

        $this->assertSame('internal_sku', $preview['headers'][0]);
        $this->assertContains('CSV内で商品IDが重複しています。', $preview['rows'][0]['errors']);
        $this->assertContains('CSV内で商品IDが重複しています。', $preview['rows'][1]['errors']);
    }

    #[Test]
    public function it_accepts_product_id_as_the_current_csv_header(): void
    {
        $file = UploadedFile::fake()->createWithContent('products.csv', implode("\n", [
            'product_id,product_name,condition,purchase_unit_cost,purchase_quantity,quantity_available,inventory_status',
            'PRODUCT-001,商品A,used,1000,1,1,in_stock',
        ]));

        $preview = app(ProductCsvPreviewService::class)->preview($file);

        $this->assertSame('internal_sku', $preview['headers'][0]);
        $this->assertSame('PRODUCT-001', $preview['rows'][0]['values']['internal_sku']);
        $this->assertSame([], $preview['rows'][0]['errors']);
    }

    #[Test]
    public function it_rejects_unknown_columns_and_invalid_optional_values(): void
    {
        $file = UploadedFile::fake()->createWithContent('products.csv', implode("\n", [
            'internal_sku,product_name,condition,purchase_unit_cost,purchase_quantity,quantity_available,inventory_status,purchase_date,purchase_shipping_cost',
            'SKU-1,商品A,used,1000,1,1,in_stock,2026-02-30,invalid',
        ]));

        $preview = app(ProductCsvPreviewService::class)->preview($file);

        $this->assertContains('purchase_dateはYYYY-MM-DD形式にしてください。', $preview['rows'][0]['errors']);
        $this->assertContains('purchase_shipping_costは0以上の整数にしてください。', $preview['rows'][0]['errors']);
    }

    #[Test]
    public function it_accepts_the_minimum_furimadeck_headers_and_applies_safe_defaults(): void
    {
        $file = UploadedFile::fake()->createWithContent('products.csv', "management_id,title,status\nFD-001,商品, selling");

        $preview = app(ProductCsvPreviewService::class)->preview($file);

        $this->assertSame([], $preview['rows'][0]['errors']);
        $this->assertSame('FD-001', $preview['rows'][0]['values']['internal_sku']);
        $this->assertSame('商品', $preview['rows'][0]['values']['product_name']);
        $this->assertSame('in_stock', $preview['rows'][0]['values']['inventory_status']);
        $this->assertSame('1', $preview['rows'][0]['values']['purchase_quantity']);
    }

    #[Test]
    public function it_accepts_japanese_backup_aliases(): void
    {
        $file = UploadedFile::fake()->createWithContent('products.csv', "管理ID,商品タイトル,仕入れ値,ステータス\nFD-002,和名商品,1200,SOLD");

        $preview = app(ProductCsvPreviewService::class)->preview($file);

        $this->assertSame([], $preview['rows'][0]['errors']);
        $this->assertSame('FD-002', $preview['rows'][0]['values']['internal_sku']);
        $this->assertSame('1200', $preview['rows'][0]['values']['purchase_unit_cost']);
    }
}
