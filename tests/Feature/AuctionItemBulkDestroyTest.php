<?php

namespace Tests\Feature;

use App\Models\AuctionItem;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuctionItemBulkDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_delete_only_their_auction_items_after_confirmation(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $category = Category::create(['name' => '親カテゴリ', 'sort_order' => 1]);
        $pathSuffix = uniqid();
        $itemImagePath = 'auction-items/test-bulk-destroy-item-'.$pathSuffix.'.jpg';
        $soldImagePath = 'auction-items/test-bulk-destroy-sold-'.$pathSuffix.'.jpg';
        $otherImagePath = 'auction-items/test-bulk-destroy-other-'.$pathSuffix.'.jpg';

        Storage::disk('public')->put($itemImagePath, 'item-image');
        Storage::disk('public')->put($soldImagePath, 'sold-image');
        Storage::disk('public')->put($otherImagePath, 'other-image');

        try {
            Storage::disk('public')->assertExists($otherImagePath);

            AuctionItem::create([
                ...$this->auctionItemPayload('ITEM-001'),
                'user_id' => $user->id,
                'category_id' => $category->id,
                'image_path' => $itemImagePath,
                'sold_image_path' => $soldImagePath,
            ]);

            AuctionItem::create([
                ...$this->auctionItemPayload('OTHER-001'),
                'user_id' => $otherUser->id,
                'image_path' => $otherImagePath,
            ]);

            $this
                ->actingAs($user)
                ->delete(route('auction-items.bulk-destroy'), [
                    'confirm_delete_all_items' => '1',
                ])
                ->assertRedirect(route('auction-items.index'))
                ->assertSessionHas('success');

            $this->assertDatabaseMissing('auction_items', [
                'user_id' => $user->id,
                'management_id' => 'ITEM-001',
            ]);

            $this->assertDatabaseHas('auction_items', [
                'user_id' => $otherUser->id,
                'management_id' => 'OTHER-001',
            ]);

            $this->assertDatabaseHas('categories', [
                'id' => $category->id,
                'name' => '親カテゴリ',
            ]);

            Storage::disk('public')->assertMissing($itemImagePath);
            Storage::disk('public')->assertMissing($soldImagePath);
            Storage::disk('public')->assertExists($otherImagePath);
        } finally {
            Storage::disk('public')->delete($otherImagePath);
        }
    }

    public function test_user_cannot_bulk_delete_items_without_confirmation_checkbox(): void
    {
        $user = User::factory()->create();

        AuctionItem::create([
            ...$this->auctionItemPayload('ITEM-001'),
            'user_id' => $user->id,
        ]);

        $this
            ->actingAs($user)
            ->from(route('auction-items.bulk-destroy.confirm'))
            ->delete(route('auction-items.bulk-destroy'))
            ->assertRedirect(route('auction-items.bulk-destroy.confirm'))
            ->assertSessionHasErrors('confirm_delete_all_items');

        $this->assertDatabaseHas('auction_items', [
            'user_id' => $user->id,
            'management_id' => 'ITEM-001',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function auctionItemPayload(string $managementId): array
    {
        return [
            'management_id' => $managementId,
            'title' => 'テスト商品',
            'comment' => null,
            'platform' => AuctionItem::PLATFORM_OTHER,
            'category_id' => null,
            'image_path' => null,
            'sold_image_path' => null,
            'status' => AuctionItem::STATUS_SELLING,
            'purchase_price' => 100,
            'sold_price' => 200,
            'sales_fee_rate' => 0,
            'sales_fee' => 0,
            'shipping_fee' => 0,
            'profit' => 0,
            'sold_at' => null,
        ];
    }
}
