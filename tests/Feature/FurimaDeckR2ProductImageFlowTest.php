<?php

namespace Tests\Feature;

use App\Models\Marketplace;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FurimaDeckR2ProductImageFlowTest extends TestCase
{
    private const DEV_DISK = 'furimadeck_r2_dev';

    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.connections.sqlite');
        $connection['database'] = ':memory:';
        $connection['url'] = null;
        config()->set('database.connections.furimadeck', $connection);
        DB::purge('furimadeck');
        DB::setDefaultConnection('furimadeck');

        $this->artisan('migrate', [
            '--database' => 'furimadeck',
            '--path' => 'database/migrations/furimadeck',
        ])->assertSuccessful();

        config()->set('furimadeck.cutover_enabled', true);
        config()->set('furimadeck.product_management_enabled', true);
        config()->set('furimadeck.product_images.disk', self::DEV_DISK);
        Storage::fake(self::DEV_DISK);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');

        parent::tearDown();
    }

    public function test_one_product_image_is_saved_to_the_selected_r2_disk(): void
    {
        $user = $this->verifiedUser();

        $this->actingAs($user)
            ->post(route('products.store'), [
                ...$this->productPayload(),
                'images' => [UploadedFile::fake()->image('single.jpg', 640, 480)],
            ])
            ->assertRedirect(route('products.index'));

        $image = ProductImage::query()->firstOrFail();
        $disk = Storage::disk(self::DEV_DISK);

        $this->assertSame(self::DEV_DISK, $image->storageDisk());
        $this->assertTrue($disk->exists($image->original_path));
        $this->assertTrue($disk->exists($image->derived_path));
        $this->assertSame('image/webp', $image->mime_type);
    }

    public function test_ten_product_images_are_saved_without_missing_objects_or_duplicate_keys(): void
    {
        $user = $this->verifiedUser();
        $images = collect(range(1, 10))
            ->map(fn (int $number) => UploadedFile::fake()->image("image-{$number}.jpg", 640, 480))
            ->all();

        $this->actingAs($user)
            ->post(route('products.store'), [
                ...$this->productPayload(),
                'images' => $images,
            ])
            ->assertRedirect(route('products.index'));

        $productImages = ProductImage::query()->get();
        $disk = Storage::disk(self::DEV_DISK);
        $this->assertCount(10, $productImages);
        $this->assertSame(20, count($disk->allFiles('products/'.$user->id)));
        $this->assertSame(10, $productImages->pluck('original_path')->unique()->count());
        $this->assertSame(10, $productImages->pluck('derived_path')->unique()->count());

        foreach ($productImages as $image) {
            $this->assertSame(self::DEV_DISK, $image->storageDisk());
            $this->assertTrue($disk->exists($image->original_path));
            $this->assertTrue($disk->exists($image->derived_path));
        }
    }

    public function test_deleting_a_product_removes_its_r2_objects_and_database_rows(): void
    {
        $user = $this->verifiedUser();
        $product = $user->products()->create($this->productPayload());
        $originalPath = 'products/'.$user->id.'/delete-original.webp';
        $thumbnailPath = 'products/'.$user->id.'/delete-thumbnail.webp';
        $disk = Storage::disk(self::DEV_DISK);
        $disk->put($originalPath, 'original image');
        $disk->put($thumbnailPath, 'thumbnail image');
        $image = $product->images()->create([
            'original_path' => $originalPath,
            'derived_path' => $thumbnailPath,
            'storage_disk' => self::DEV_DISK,
            'mime_type' => 'image/webp',
            'file_size' => 14,
            'position' => 1,
        ]);

        $this->actingAs($user)
            ->delete(route('products.destroy', $product), ['confirm_deletion' => '1'])
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('product_images', ['id' => $image->id]);
        $disk->assertMissing($originalPath);
        $disk->assertMissing($thumbnailPath);
    }

    public function test_database_failure_after_image_upload_removes_orphaned_r2_objects(): void
    {
        $user = $this->verifiedUser();
        DB::connection('furimadeck')->unprepared(
            "CREATE TRIGGER reject_product_image BEFORE INSERT ON product_images BEGIN SELECT RAISE(ABORT, 'forced image insert failure'); END"
        );
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($user)->post(route('products.store'), [
                ...$this->productPayload(),
                'images' => [UploadedFile::fake()->image('rollback.jpg', 640, 480)],
            ]);

            $this->fail('The injected image database failure was not raised.');
        } catch (QueryException) {
            $this->assertSame(0, $user->products()->count());
            $this->assertSame([], Storage::disk(self::DEV_DISK)->allFiles('products/'.$user->id));
        }
    }

    public function test_later_product_transaction_failure_removes_images_already_uploaded_to_r2(): void
    {
        $user = $this->verifiedUser();
        $marketplace = Marketplace::query()->create([
            'code' => 'r2-rollback-test',
            'name' => 'R2ロールバックテスト',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 10,
            'is_active' => true,
        ]);
        DB::connection('furimadeck')->unprepared(
            "CREATE TRIGGER reject_initial_listing BEFORE INSERT ON listings BEGIN SELECT RAISE(ABORT, 'forced listing insert failure'); END"
        );
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($user)->post(route('products.store'), [
                ...$this->productPayload(),
                'initial_marketplace_id' => $marketplace->id,
                'initial_listing_price' => 1000,
                'images' => [UploadedFile::fake()->image('later-rollback.jpg', 640, 480)],
            ]);

            $this->fail('The injected listing database failure was not raised.');
        } catch (QueryException) {
            $this->assertSame(0, $user->products()->count());
            $this->assertSame([], Storage::disk(self::DEV_DISK)->allFiles('products/'.$user->id));
        }
    }

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function productPayload(): array
    {
        return [
            'internal_sku' => 'R2-'.bin2hex(random_bytes(8)),
            'product_name' => 'R2画像保存テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 100,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ];
    }
}
