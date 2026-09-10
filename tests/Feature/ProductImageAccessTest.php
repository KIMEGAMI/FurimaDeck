<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        $product = $this->productFor($owner);
        $path = 'products/'.$owner->id.'/original.webp';
        $image = $product->images()->create([
            'original_path' => $path,
            'storage_disk' => ProductImage::PRIVATE_STORAGE_DISK,
            'mime_type' => 'image/webp',
            'file_size' => 1,
            'position' => 1,
        ]);
        Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->put($path, 'image');

        $this->actingAs($otherUser)
            ->get(route('products.images.show', [
                'product' => $product,
                'image' => $image,
                'variant' => 'original',
            ]))
            ->assertNotFound();
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
