<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\ProductImageProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class ProductImageAccessTest extends TestCase
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
        Storage::fake(ProductImage::PRIVATE_STORAGE_DISK);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');

        parent::tearDown();
    }

    public function test_owner_can_download_a_private_product_thumbnail_through_the_authorized_route(): void
    {
        $user = $this->verifiedUser();
        $product = $this->productFor($user);
        $path = 'products/'.$user->id.'/thumbnail.webp';
        $image = $product->images()->create([
            'original_path' => 'products/'.$user->id.'/original.webp',
            'derived_path' => $path,
            'storage_disk' => ProductImage::PRIVATE_STORAGE_DISK,
            'mime_type' => 'image/webp',
            'file_size' => 1,
            'position' => 1,
        ]);
        $this->assertSame(ProductImage::PRIVATE_STORAGE_DISK, $image->storageDisk());
        Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->put($path, 'image');

        $response = $this->actingAs($user)->get(route('products.images.show', [
            'product' => $product,
            'image' => $image,
            'variant' => 'thumbnail',
        ]));

        $response->assertOk();
        $response->assertHeaderContains('Cache-Control', 'private');
        $response->assertHeaderContains('Cache-Control', 'no-store');
        $response->assertHeader('Content-Type', 'image/webp');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_other_user_cannot_access_a_private_product_image(): void
    {
        $owner = $this->verifiedUser();
        $otherUser = $this->verifiedUser();
        $this->assertNotSame($owner->id, $otherUser->id);
        $product = $this->productFor($owner);
        $this->assertSame($owner->id, $product->user_id);
        $path = 'products/'.$owner->id.'/original.webp';
        $image = $product->images()->create([
            'original_path' => $path,
            'storage_disk' => ProductImage::PRIVATE_STORAGE_DISK,
            'mime_type' => 'image/webp',
            'file_size' => 1,
            'position' => 1,
        ]);
        $this->assertSame(ProductImage::PRIVATE_STORAGE_DISK, $image->storageDisk());
        Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->put($path, 'image');

        $this->actingAs($otherUser)
            ->get(route('products.images.show', [
                'product' => $product,
                'image' => $image,
                'variant' => 'original',
            ]))
            ->assertNotFound();

    }

    public function test_r2_backed_images_are_served_through_owner_authorization(): void
    {
        Storage::fake('furimadeck_r2');
        $owner = $this->verifiedUser();
        $otherUser = $this->verifiedUser();
        $this->assertNotSame($owner->id, $otherUser->id);
        $product = $this->productFor($owner);
        $this->assertSame($owner->id, $product->user_id);
        $path = 'products/'.$owner->id.'/private-r2.webp';
        $image = $product->images()->create([
            'original_path' => $path,
            'storage_disk' => 'furimadeck_r2',
            'mime_type' => 'image/webp',
            'file_size' => 5,
            'position' => 1,
        ]);
        $this->assertSame('furimadeck_r2', $image->storageDisk());
        Storage::disk('furimadeck_r2')->put($path, 'image');

        $this->actingAs($owner)
            ->get(route('products.images.show', [
                'product' => $product,
                'image' => $image,
                'variant' => 'original',
            ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp');

        $this->actingAs($otherUser)
            ->get(route('products.images.show', [
                'product' => $product,
                'image' => $image,
                'variant' => 'original',
            ]))
            ->assertNotFound();

        $this->actingAs($owner)
            ->from(route('products.edit', $product))
            ->delete(route('products.images.destroy', ['product' => $product, 'image' => $image]))
            ->assertRedirect(route('products.edit', $product));

        $this->assertDatabaseMissing('product_images', ['id' => $image->id]);
        Storage::disk('furimadeck_r2')->assertMissing($path);
    }

    public function test_new_product_images_cannot_use_the_other_environment_r2_disk(): void
    {
        config()->set('app.env', 'local');
        config()->set('furimadeck.product_images.disk', ProductImage::DEVELOPMENT_R2_DISK);
        $this->assertSame(ProductImage::DEVELOPMENT_R2_DISK, ProductImage::privateStorageDisk());

        config()->set('furimadeck.product_images.disk', ProductImage::PRODUCTION_R2_DISK);
        try {
            ProductImage::privateStorageDisk();
            $this->fail('A local environment must not select the production R2 disk.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('商品画像の保存先設定が無効です。', $exception->getMessage());
        }

        config()->set('app.env', 'production');
        config()->set('furimadeck.product_images.disk', ProductImage::PRODUCTION_R2_DISK);
        $this->assertSame(ProductImage::PRODUCTION_R2_DISK, ProductImage::privateStorageDisk());

        config()->set('furimadeck.product_images.disk', ProductImage::DEVELOPMENT_R2_DISK);
        try {
            ProductImage::privateStorageDisk();
            $this->fail('A production environment must not select the development R2 disk.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('商品画像の保存先設定が無効です。', $exception->getMessage());
        }
    }

    public function test_existing_product_image_rows_cannot_read_the_other_environment_r2_disk(): void
    {
        $image = new ProductImage(['storage_disk' => ProductImage::PRODUCTION_R2_DISK]);
        config()->set('app.env', 'local');
        try {
            $image->storageDisk();
            $this->fail('A local environment must not read the production R2 disk.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('商品画像の保存先設定が無効です。', $exception->getMessage());
        }

        $image->storage_disk = ProductImage::DEVELOPMENT_R2_DISK;
        config()->set('app.env', 'production');
        try {
            $image->storageDisk();
            $this->fail('A production environment must not read the development R2 disk.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('商品画像の保存先設定が無効です。', $exception->getMessage());
        }
    }

    public function test_live_development_r2_product_image_flow_when_explicitly_enabled(): void
    {
        if (env('FURIMADECK_R2_LIVE_TEST') !== '1') {
            $this->markTestSkipped('Use the explicit -EnableDevelopmentR2LiveTest runner switch to run the development-bucket integration test.');
        }

        $diskName = 'furimadeck_r2_dev';
        $diskConfig = config('filesystems.disks.'.$diskName, []);
        $productionConfig = config('filesystems.disks.furimadeck_r2', []);
        $this->assertSame('furimadeck-dev-images', $diskConfig['bucket'] ?? null);
        $this->assertNotSame($productionConfig['bucket'] ?? null, $diskConfig['bucket'] ?? null);
        $this->assertFalse(hash_equals((string) ($productionConfig['key'] ?? ''), (string) ($diskConfig['key'] ?? '')));
        $this->assertFalse(hash_equals((string) ($productionConfig['secret'] ?? ''), (string) ($diskConfig['secret'] ?? '')));

        Storage::forgetDisk($diskName);
        config()->set('furimadeck.product_images.disk', $diskName);
        $this->assertSame($diskName, ProductImage::privateStorageDisk());

        $owner = $this->verifiedUser();
        $otherUser = $this->verifiedUser();
        $product = $this->productFor($owner);
        $disk = Storage::disk($diskName);
        $uploadedPaths = [];

        try {
            $uploaded = app(ProductImageProcessor::class)->process(
                UploadedFile::fake()->image('r2-live-check.png', 600, 400),
                (int) $owner->id,
            );
            $uploadedPaths = [$uploaded['original_path'], $uploaded['derived_path']];
            $image = $product->images()->create([
                ...$uploaded,
                'storage_disk' => $diskName,
                'position' => 1,
            ]);

            $storedImage = $disk->get($image->original_path);
            $this->assertIsString($storedImage);
            $this->assertSame([600, 400], array_slice(getimagesizefromstring($storedImage), 0, 2));
            $this->assertTrue($disk->exists($image->derived_path));

            $this->actingAs($owner)
                ->get(route('products.images.show', [$product, $image, 'thumbnail']))
                ->assertOk()
                ->assertHeader('Content-Type', 'image/webp')
                ->assertHeaderContains('Cache-Control', 'private');

            $this->actingAs($otherUser)
                ->get(route('products.images.show', [$product, $image, 'original']))
                ->assertNotFound();

            $this->actingAs($owner)
                ->from(route('products.edit', $product))
                ->delete(route('products.images.destroy', [$product, $image]))
                ->assertRedirect(route('products.edit', $product));

            $this->assertDatabaseMissing('product_images', ['id' => $image->id]);
            $this->assertFalse($disk->exists($image->original_path));
            $this->assertFalse($disk->exists($image->derived_path));
        } finally {
            if ($uploadedPaths !== []) {
                $disk->delete($uploadedPaths);
            }
        }
    }

    public function test_live_production_r2_product_image_flow_when_explicitly_enabled(): void
    {
        if (env('FURIMADECK_R2_PRODUCTION_LIVE_TEST') !== '1') {
            $this->markTestSkipped('Use the explicit -EnableProductionR2LiveTest runner switch to run the production-bucket integration test.');
        }

        $diskName = 'furimadeck_r2';
        $diskConfig = config('filesystems.disks.'.$diskName, []);
        $developmentConfig = config('filesystems.disks.furimadeck_r2_dev', []);
        $this->assertSame('furimadeck-prod-images', $diskConfig['bucket'] ?? null);
        $this->assertSame('furimadeck-dev-images', $developmentConfig['bucket'] ?? null);
        $this->assertGreaterThan(0, strlen((string) ($diskConfig['key'] ?? '')));
        $this->assertGreaterThan(0, strlen((string) ($diskConfig['secret'] ?? '')));
        $this->assertGreaterThan(0, strlen((string) ($developmentConfig['key'] ?? '')));
        $this->assertGreaterThan(0, strlen((string) ($developmentConfig['secret'] ?? '')));
        $this->assertFalse(hash_equals((string) ($developmentConfig['key'] ?? ''), (string) ($diskConfig['key'] ?? '')));
        $this->assertFalse(hash_equals((string) ($developmentConfig['secret'] ?? ''), (string) ($diskConfig['secret'] ?? '')));

        Storage::forgetDisk($diskName);
        config()->set('furimadeck.product_images.disk', $diskName);
        $this->assertSame($diskName, ProductImage::privateStorageDisk());

        $owner = $this->verifiedUser();
        $otherUser = $this->verifiedUser();
        $product = $this->productFor($owner);
        $disk = Storage::disk($diskName);
        $uploadedPaths = [];

        try {
            $uploaded = app(ProductImageProcessor::class)->process(
                UploadedFile::fake()->image('r2-production-live-check.png', 600, 400),
                (int) $owner->id,
            );
            $uploadedPaths = [$uploaded['original_path'], $uploaded['derived_path']];
            $image = $product->images()->create([
                ...$uploaded,
                'storage_disk' => $diskName,
                'position' => 1,
            ]);

            $storedImage = $disk->get($image->original_path);
            $this->assertIsString($storedImage);
            $this->assertSame([600, 400], array_slice(getimagesizefromstring($storedImage), 0, 2));
            $this->assertTrue($disk->exists($image->derived_path));

            $this->actingAs($owner)
                ->get(route('products.images.show', [$product, $image, 'thumbnail']))
                ->assertOk()
                ->assertHeader('Content-Type', 'image/webp')
                ->assertHeaderContains('Cache-Control', 'private');

            $this->actingAs($otherUser)
                ->get(route('products.images.show', [$product, $image, 'original']))
                ->assertNotFound();

            $this->actingAs($owner)
                ->from(route('products.edit', $product))
                ->delete(route('products.images.destroy', [$product, $image]))
                ->assertRedirect(route('products.edit', $product));

            $this->assertDatabaseMissing('product_images', ['id' => $image->id]);
            $this->assertFalse($disk->exists($image->original_path));
            $this->assertFalse($disk->exists($image->derived_path));
        } finally {
            if ($uploadedPaths !== []) {
                $disk->delete($uploadedPaths);
            }
        }
    }

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    private function productFor(User $user): Product
    {
        return $user->products()->create([
            'internal_sku' => 'PRIVATE-'.uniqid(),
            'product_name' => '画像保護テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
    }
}
