<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\ProductImageProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FurimaDeckProductImageTest extends TestCase
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

    public function test_product_registration_rejects_more_than_ten_images(): void
    {
        $user = $this->verifiedUser();
        $images = collect(range(1, 11))
            ->map(fn (int $number) => UploadedFile::fake()->image("image-{$number}.jpg"))
            ->all();

        $this->actingAs($user)
            ->from(route('products.create'))
            ->post(route('products.store'), [...$this->productPayload(), 'images' => $images])
            ->assertRedirect(route('products.create'))
            ->assertSessionHasErrors('images');

        $this->assertSame(0, $user->products()->count());
    }

    public function test_product_registration_saves_description_and_redirects_to_product_list(): void
    {
        $user = $this->verifiedUser();

        $this->actingAs($user)
            ->post(route('products.store'), [
                ...$this->productPayload(),
                'description_base' => '商品の状態と注意点の説明です。',
            ])
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', [
            'user_id' => $user->id,
            'description_base' => '商品の状態と注意点の説明です。',
        ], 'furimadeck');
    }

    public function test_product_registration_rejects_an_image_over_two_megabytes(): void
    {
        $user = $this->verifiedUser();

        $this->actingAs($user)
            ->from(route('products.create'))
            ->post(route('products.store'), [
                ...$this->productPayload(),
                'images' => [UploadedFile::fake()->create('too-large.jpg', 2049, 'image/jpeg')],
            ])
            ->assertRedirect(route('products.create'))
            ->assertSessionHasErrors('images.0');

        $this->assertSame(0, $user->products()->count());
    }

    public function test_product_image_processor_stores_webp_main_and_thumbnail_with_private_paths(): void
    {
        $user = $this->verifiedUser();
        $result = app(ProductImageProcessor::class)->process(
            UploadedFile::fake()->image('large.jpg', 2400, 1200),
            (int) $user->id,
        );

        $disk = Storage::disk(ProductImage::PRIVATE_STORAGE_DISK);
        $this->assertSame('image/webp', $result['mime_type']);
        $this->assertStringEndsWith('.webp', $result['original_path']);
        $this->assertStringEndsWith('.thumb.webp', $result['derived_path']);
        $this->assertTrue($disk->exists($result['original_path']));
        $this->assertTrue($disk->exists($result['derived_path']));
        $this->assertSame((int) config('furimadeck.product_images.max_dimension'), getimagesize($disk->path($result['original_path']))[0]);
        $this->assertSame(400, getimagesize($disk->path($result['derived_path']))[0]);
        $this->assertStringStartsWith('products/'.$user->id.'/', $result['original_path']);
    }

    public function test_product_image_reordering_rejects_duplicate_ids(): void
    {
        $user = $this->verifiedUser();
        $product = $this->productFor($user);
        $images = collect(range(1, 2))->map(fn (int $position) => $product->images()->create([
            'original_path' => "products/{$user->id}/{$position}.webp",
            'derived_path' => "products/{$user->id}/{$position}.thumb.webp",
            'storage_disk' => ProductImage::PRIVATE_STORAGE_DISK,
            'mime_type' => 'image/webp',
            'file_size' => 1,
            'position' => $position,
        ]));

        $this->actingAs($user)
            ->patchJson(route('products.images.order', $product), [
                'image_ids' => [$images[0]->id, $images[0]->id],
            ])
            ->assertStatus(422);
    }

    public function test_other_user_cannot_read_reorder_or_delete_product_images(): void
    {
        $owner = $this->verifiedUser();
        $other = $this->verifiedUser();
        $product = $this->productFor($owner);
        $image = $product->images()->create([
            'original_path' => "products/{$owner->id}/private.webp",
            'derived_path' => "products/{$owner->id}/private.thumb.webp",
            'storage_disk' => ProductImage::PRIVATE_STORAGE_DISK,
            'mime_type' => 'image/webp',
            'file_size' => 1,
            'position' => 0,
        ]);

        $this->actingAs($other)
            ->get(route('products.images.show', [$product, $image, 'original']))
            ->assertNotFound();
        $this->actingAs($other)
            ->patchJson(route('products.images.order', $product), ['image_ids' => [$image->id]])
            ->assertNotFound();
        $this->actingAs($other)
            ->delete(route('products.images.destroy', [$product, $image]))
            ->assertNotFound();

        $this->assertDatabaseHas('product_images', ['id' => $image->id]);
    }

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function productPayload(): array
    {
        return [
            'internal_sku' => 'IMAGE-'.uniqid(),
            'product_name' => '画像上限テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 100,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ];
    }

    private function productFor(User $user): Product
    {
        return $user->products()->create([
            'internal_sku' => 'ORDER-'.uniqid(),
            'product_name' => '並び順テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
    }
}
