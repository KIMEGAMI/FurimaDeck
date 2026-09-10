<?php

namespace Tests\Feature;

use App\Models\AuctionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuctionItemMultipleImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_form_supports_multiple_image_selection_and_reordering(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(route('auction-items.create'))
            ->assertOk()
            ->assertSee('name="images[]"', false)
            ->assertSee('multiple', false)
            ->assertSee('画像をここへドラッグ＆ドロップ', false)
            ->assertSee('最大10枚', false)
            ->assertSee('const selectedImages = [];', false);
    }

    public function test_user_can_register_up_to_ten_images_in_the_selected_order(): void
    {
        $user = User::factory()->create();
        $images = collect(range(1, 10))
            ->map(fn (int $position) => UploadedFile::fake()->image("image-{$position}.jpg"))
            ->all();

        $this->actingAs($user)
            ->post(route('auction-items.store'), $this->payload(['images' => $images]))
            ->assertRedirect(route('auction-items.index'));

        $item = AuctionItem::query()->where('management_id', 'IMAGES-001')->firstOrFail();

        $this->assertCount(10, $item->images);
        $this->assertSame(range(0, 9), $item->images->pluck('position')->all());
        $this->assertSame($item->images->first()->path, $item->image_path);

        $item->images->each(function ($image): void {
            $this->assertIsString($image->path);
            $this->assertStringStartsWith('auction-items/', $image->path);
        });
    }

    public function test_user_cannot_register_more_than_ten_images(): void
    {
        $user = User::factory()->create();
        $images = collect(range(1, 11))
            ->map(fn (int $position) => UploadedFile::fake()->image("image-{$position}.jpg"))
            ->all();

        $this->actingAs($user)
            ->from(route('auction-items.create'))
            ->post(route('auction-items.store'), $this->payload(['images' => $images]))
            ->assertRedirect(route('auction-items.create'))
            ->assertSessionHasErrors('images');

        $this->assertDatabaseMissing('auction_items', ['management_id' => 'IMAGES-001']);
    }

    public function test_user_cannot_register_an_image_larger_than_two_megabytes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('auction-items.create'))
            ->post(route('auction-items.store'), $this->payload([
                'images' => [UploadedFile::fake()->create('too-large.jpg', 2049, 'image/jpeg')],
            ]))
            ->assertRedirect(route('auction-items.create'))
            ->assertSessionHasErrors('images.0');

        $this->assertDatabaseMissing('auction_items', ['management_id' => 'IMAGES-001']);
    }

    public function test_account_deletion_removes_additional_product_images(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $item = AuctionItem::create([
            'user_id' => $user->id,
            'management_id' => 'IMAGES-DELETE-001',
            'title' => '削除確認の商品',
            'platform' => AuctionItem::PLATFORM_OTHER,
            'image_path' => 'auction-items/primary.jpg',
            'status' => AuctionItem::STATUS_SELLING,
            'purchase_price' => 1000,
            'sold_price' => 0,
            'sales_fee_rate' => 0,
            'sales_fee' => 0,
            'shipping_fee' => 0,
            'profit' => 0,
        ]);

        $item->images()->create([
            'path' => 'auction-items/primary.jpg',
            'position' => 0,
        ]);
        $item->images()->create([
            'path' => 'auction-items/additional.jpg',
            'position' => 1,
        ]);
        Storage::disk('public')->put('auction-items/primary.jpg', 'primary');
        Storage::disk('public')->put('auction-items/additional.jpg', 'additional');

        $this->actingAs($user)
            ->delete('/profile', ['password' => 'password'])
            ->assertRedirect('/');

        Storage::disk('public')->assertMissing('auction-items/primary.jpg');
        Storage::disk('public')->assertMissing('auction-items/additional.jpg');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'management_id' => 'IMAGES-001',
            'title' => '複数画像の商品',
            'platform' => AuctionItem::PLATFORM_MERCARI,
            'purchase_price' => 1000,
            'sold_price' => 2000,
            'shipping_fee' => 200,
            'sales_fee_rate' => 10,
        ], $overrides);
    }
}
