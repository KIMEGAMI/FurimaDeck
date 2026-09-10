<?php

namespace Tests\Unit;

use App\Models\ProductImage;
use App\Services\ProductImageProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ProductImageProcessorTest extends TestCase
{
    private string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->diskRoot = storage_path('app/testing-product-images/'.Str::uuid());
        config()->set('filesystems.disks.'.ProductImage::PRIVATE_STORAGE_DISK, [
            'driver' => 'local',
            'root' => $this->diskRoot,
            'throw' => true,
        ]);
        Storage::forgetDisk(ProductImage::PRIVATE_STORAGE_DISK);
    }

    protected function tearDown(): void
    {
        Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->deleteDirectory('');

        parent::tearDown();
    }

    public function test_it_saves_resized_webp_and_thumbnail_without_retaining_the_source_file(): void
    {
        config()->set('furimadeck.product_images.max_dimension', 2000);
        config()->set('furimadeck.product_images.thumbnail_dimension', 400);
        config()->set('furimadeck.product_images.webp_quality', 80);
        $file = UploadedFile::fake()->image('source.png', 2400, 1200);

        $image = app(ProductImageProcessor::class)->process($file, 123);

        Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->assertExists($image['original_path']);
        Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->assertExists($image['derived_path']);
        $this->assertSame('image/webp', $image['mime_type']);
        $this->assertSame($image['file_size'], Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->size($image['original_path']));
        $this->assertSame([2000, 1000], array_slice(getimagesize(Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->path($image['original_path'])), 0, 2));
        $this->assertSame([400, 200], array_slice(getimagesize(Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->path($image['derived_path'])), 0, 2));
        Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->assertMissing('products/123/source.png');
    }

    public function test_it_rejects_images_above_configured_source_pixel_limit(): void
    {
        config()->set('furimadeck.product_images.max_source_pixels', 1);

        $file = UploadedFile::fake()->image('source.png', 2, 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('画像の画素数が上限を超えています。');

        app(ProductImageProcessor::class)->process($file, 123);
    }
}
