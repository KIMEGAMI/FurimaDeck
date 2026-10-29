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

    public function test_dry_run_reports_source_capacity_and_validates_source_files(): void
    {
        $image = $this->localImage();
        Storage::fake('furimadeck_r2');
        $sourceBytes = Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->size($image->original_path)
            + Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->size($image->derived_path);

        $this->artisan('furimadeck:secure-product-images', [
            '--source' => ProductImage::PRIVATE_STORAGE_DISK,
            '--target' => 'furimadeck_r2',
        ])
            ->expectsOutputToContain('Dry-run: 対象 1件、コピー元容量 '.$sourceBytes.' bytes')
            ->assertExitCode(0);

        $this->assertSame(ProductImage::PRIVATE_STORAGE_DISK, $image->fresh()->storage_disk);
        Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->assertExists($image->original_path);
        Storage::disk('furimadeck_r2')->assertMissing($image->original_path);
    }

    public function test_dry_run_marks_missing_source_files_as_failed_without_changing_data(): void
    {
        $image = $this->localImage();
        Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->delete($image->derived_path);
        Storage::fake('furimadeck_r2');

        $this->artisan('furimadeck:secure-product-images', [
            '--source' => ProductImage::PRIVATE_STORAGE_DISK,
            '--target' => 'furimadeck_r2',
        ])
            ->expectsOutputToContain('画像ID '.$image->id.' を移行できませんでした。コピー元は変更していません。')
            ->assertExitCode(1);

        $this->assertSame(ProductImage::PRIVATE_STORAGE_DISK, $image->fresh()->storage_disk);
        Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->assertExists($image->original_path);
        Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->assertMissing($image->derived_path);
        Storage::disk('furimadeck_r2')->assertMissing($image->original_path);
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

    public function test_apply_copies_local_images_to_configured_r2_disk_and_keeps_local_source(): void
    {
        $image = $this->localImage();
        Storage::fake('furimadeck_r2');

        $this->artisan('furimadeck:secure-product-images', [
            '--source' => ProductImage::PRIVATE_STORAGE_DISK,
            '--target' => 'furimadeck_r2',
            '--apply' => true,
        ])->assertExitCode(0);

        $this->assertSame('furimadeck_r2', $image->fresh()->storage_disk);
        $this->assertSame(ProductImage::PRIVATE_STORAGE_DISK, ProductImage::privateStorageDisk());
        Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->assertExists($image->original_path);
        Storage::disk('furimadeck_r2')->assertExists($image->original_path);
        $this->assertSame(
            hash('sha256', Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->get($image->original_path)),
            hash('sha256', Storage::disk('furimadeck_r2')->get($image->original_path)),
        );
    }

    public function test_copy_verification_failure_keeps_source_and_storage_marker_unchanged(): void
    {
        $image = $this->localImage();
        Storage::fake('furimadeck_r2');
        Storage::disk('furimadeck_r2')->put($image->original_path, 'different content');

        $this->artisan('furimadeck:secure-product-images', [
            '--source' => ProductImage::PRIVATE_STORAGE_DISK,
            '--target' => 'furimadeck_r2',
            '--apply' => true,
        ])->assertExitCode(1);

        $this->assertSame(ProductImage::PRIVATE_STORAGE_DISK, $image->fresh()->storage_disk);
        Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->assertExists($image->original_path);
    }

    public function test_local_environment_cannot_migrate_images_to_the_production_r2_disk(): void
    {
        config()->set('app.env', 'local');
        $image = $this->localImage();
        Storage::fake(ProductImage::PRODUCTION_R2_DISK);

        $this->artisan('furimadeck:secure-product-images', [
            '--source' => ProductImage::PRIVATE_STORAGE_DISK,
            '--target' => ProductImage::PRODUCTION_R2_DISK,
            '--apply' => true,
        ])
            ->expectsOutputToContain('画像の保存先ディスクが許可されていません。')
            ->assertExitCode(1);

        $this->assertSame(ProductImage::PRIVATE_STORAGE_DISK, $image->fresh()->storage_disk);
        Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->assertExists($image->original_path);
        Storage::disk(ProductImage::PRODUCTION_R2_DISK)->assertMissing($image->original_path);
    }

    public function test_production_environment_cannot_migrate_images_to_the_development_r2_disk(): void
    {
        config()->set('app.env', 'production');
        $image = $this->localImage();
        Storage::fake(ProductImage::DEVELOPMENT_R2_DISK);

        $this->artisan('furimadeck:secure-product-images', [
            '--source' => ProductImage::PRIVATE_STORAGE_DISK,
            '--target' => ProductImage::DEVELOPMENT_R2_DISK,
            '--apply' => true,
        ])
            ->expectsOutputToContain('画像の保存先ディスクが許可されていません。')
            ->assertExitCode(1);

        $this->assertSame(ProductImage::PRIVATE_STORAGE_DISK, $image->fresh()->storage_disk);
        Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->assertExists($image->original_path);
        Storage::disk(ProductImage::DEVELOPMENT_R2_DISK)->assertMissing($image->original_path);
    }

    public function test_local_environment_cannot_read_from_the_production_r2_disk(): void
    {
        config()->set('app.env', 'local');
        $image = $this->localImage();
        Storage::fake(ProductImage::PRODUCTION_R2_DISK);
        Storage::fake(ProductImage::DEVELOPMENT_R2_DISK);

        $this->artisan('furimadeck:secure-product-images', [
            '--source' => ProductImage::PRODUCTION_R2_DISK,
            '--target' => ProductImage::DEVELOPMENT_R2_DISK,
            '--apply' => true,
        ])
            ->expectsOutputToContain('画像のコピー元ディスクが許可されていません。')
            ->assertExitCode(1);

        $this->assertSame(ProductImage::PRIVATE_STORAGE_DISK, $image->fresh()->storage_disk);
        Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->assertExists($image->original_path);
        Storage::disk(ProductImage::PRODUCTION_R2_DISK)->assertMissing($image->original_path);
        Storage::disk(ProductImage::DEVELOPMENT_R2_DISK)->assertMissing($image->original_path);
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

    private function localImage(): ProductImage
    {
        $image = $this->legacyImage();
        Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->put($image->original_path, 'local-original-content');
        Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->put($image->derived_path, 'local-thumbnail-content');
        $image->forceFill(['storage_disk' => ProductImage::PRIVATE_STORAGE_DISK])->save();

        return $image;
    }
}
