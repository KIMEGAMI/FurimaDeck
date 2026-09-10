<?php

namespace Tests\Feature;

use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecureFurimaDeckProductImagesCommandTest extends TestCase
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
        Storage::fake(ProductImage::LEGACY_STORAGE_DISK);
        Storage::fake(ProductImage::PRIVATE_STORAGE_DISK);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');

        parent::tearDown();
    }

    public function test_dry_run_leaves_legacy_images_and_database_marker_unchanged(): void
    {
        $image = $this->legacyImage();

        $this->artisan('furimadeck:secure-product-images')
            ->assertExitCode(0);

        $this->assertNull($image->fresh()->storage_disk);
        Storage::disk(ProductImage::LEGACY_STORAGE_DISK)->assertExists($image->original_path);
        Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->assertMissing($image->original_path);
    }

    public function test_apply_copies_then_marks_legacy_images_private_without_deleting_public_source(): void
    {
        $image = $this->legacyImage();

        $this->artisan('furimadeck:secure-product-images', ['--apply' => true])
            ->assertExitCode(0);

        $this->assertSame(ProductImage::PRIVATE_STORAGE_DISK, $image->fresh()->storage_disk);
        Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->assertExists($image->original_path);
        Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->assertExists($image->derived_path);
        Storage::disk(ProductImage::LEGACY_STORAGE_DISK)->assertExists($image->original_path);
        Storage::disk(ProductImage::LEGACY_STORAGE_DISK)->assertExists($image->derived_path);
    }

    private function legacyImage(): ProductImage
    {
        $user = User::factory()->create();
        $product = $user->products()->create([
            'internal_sku' => 'LEGACY-'.uniqid(),
            'product_name' => '旧公開画像の商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $originalPath = 'products/'.$user->id.'/original.webp';
        $derivedPath = 'products/'.$user->id.'/thumbnail.webp';
        Storage::disk(ProductImage::LEGACY_STORAGE_DISK)->put($originalPath, 'original');
        Storage::disk(ProductImage::LEGACY_STORAGE_DISK)->put($derivedPath, 'thumbnail');

        return $product->images()->create([
            'original_path' => $originalPath,
            'derived_path' => $derivedPath,
            'mime_type' => 'image/webp',
            'file_size' => Storage::disk(ProductImage::LEGACY_STORAGE_DISK)->size($originalPath),
            'position' => 1,
        ]);
    }
}
