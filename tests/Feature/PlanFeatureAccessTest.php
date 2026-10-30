<?php

namespace Tests\Feature;

use App\Models\AuctionItem;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanFeatureAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_user_cannot_open_premium_only_pages(): void
    {
        $user = User::factory()->create(['subscription_plan' => User::SUBSCRIPTION_INACTIVE]);

        foreach ([
            route('auction-items.csv-import'),
            route('auction-items.duplicates'),
            route('sales.index'),
            route('category-sales.index'),
        ] as $url) {
            $this->actingAs($user)
                ->get($url)
                ->assertRedirect(route('subscriptions.index'))
                ->assertSessionHas('error');
        }
    }

    public function test_premium_user_can_open_premium_only_pages(): void
    {
        $user = $this->premiumUser();

        $this->actingAs($user)->get(route('auction-items.csv-import'))->assertOk();
        $this->actingAs($user)->get(route('auction-items.duplicates'))->assertOk();
        $this->actingAs($user)->get(route('sales.index'))->assertOk();
        $this->actingAs($user)->get(route('category-sales.index'))->assertOk();
    }

    public function test_admin_user_can_open_premium_only_pages_without_stripe_subscription(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
            'subscription_plan' => User::SUBSCRIPTION_INACTIVE,
            'subscription_status' => null,
            'stripe_customer_id' => null,
            'stripe_subscription_id' => null,
        ]);

        $this->actingAs($user)->get(route('auction-items.csv-import'))->assertOk();
        $this->actingAs($user)->get(route('auction-items.duplicates'))->assertOk();
        $this->actingAs($user)->get(route('sales.index'))->assertOk();
        $this->actingAs($user)->get(route('category-sales.index'))->assertOk();
    }

    public function test_demo_user_can_open_premium_only_pages_without_stripe_subscription(): void
    {
        config(['demo.user_enabled' => true, 'demo.user_email' => 'demo@example.com']);

        $user = User::factory()->create([
            'email' => 'demo@example.com',
            'subscription_plan' => User::SUBSCRIPTION_INACTIVE,
            'subscription_status' => null,
            'stripe_customer_id' => null,
            'stripe_subscription_id' => null,
        ]);

        $this->actingAs($user)->get(route('auction-items.csv-import'))->assertOk();
        $this->actingAs($user)->get(route('auction-items.duplicates'))->assertOk();
        $this->actingAs($user)->get(route('sales.index'))->assertOk();
        $this->actingAs($user)->get(route('category-sales.index'))->assertOk();
    }

    public function test_free_user_cannot_create_more_than_free_item_limit(): void
    {
        $user = User::factory()->create(['subscription_plan' => User::SUBSCRIPTION_INACTIVE]);

        for ($index = 1; $index <= User::FREE_AUCTION_ITEM_LIMIT; $index++) {
            $this->createAuctionItem($user, 'FREE-'.$index);
        }

        $overflowManagementId = 'FREE-'.(User::FREE_AUCTION_ITEM_LIMIT + 1);

        $this->actingAs($user)
            ->post(route('auction-items.store'), $this->auctionItemPayload($overflowManagementId))
            ->assertRedirect(route('subscriptions.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('auction_items', [
            'user_id' => $user->id,
            'management_id' => $overflowManagementId,
        ]);
    }

    public function test_free_user_cannot_use_more_than_five_categories(): void
    {
        $user = User::factory()->create(['subscription_plan' => User::SUBSCRIPTION_INACTIVE]);
        $categories = $this->childCategories(User::FREE_CATEGORY_LIMIT + 1);

        foreach ($categories->take(User::FREE_CATEGORY_LIMIT) as $index => $category) {
            $this->createAuctionItem($user, 'CAT-'.$index, $category->id);
        }

        $this->actingAs($user)
            ->post(route('auction-items.store'), $this->auctionItemPayload('CAT-6', $categories->last()->id))
            ->assertRedirect(route('subscriptions.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('auction_items', [
            'user_id' => $user->id,
            'management_id' => 'CAT-6',
        ]);
    }

    private function premiumUser(): User
    {
        return User::factory()->create([
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
    }

    private function createAuctionItem(User $user, string $managementId, ?int $categoryId = null): AuctionItem
    {
        return AuctionItem::create([
            ...$this->auctionItemPayload($managementId, $categoryId),
            'user_id' => $user->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function auctionItemPayload(string $managementId, ?int $categoryId = null): array
    {
        return [
            'management_id' => $managementId,
            'title' => 'テスト商品',
            'comment' => null,
            'platform' => AuctionItem::PLATFORM_OTHER,
            'category_id' => $categoryId,
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

    private function childCategories(int $count)
    {
        $parent = Category::create(['name' => '親カテゴリ', 'sort_order' => 1]);

        return collect(range(1, $count))->map(
            fn (int $index) => Category::create([
                'parent_id' => $parent->id,
                'name' => '子カテゴリ'.$index,
                'sort_order' => $index,
            ])
        );
    }
}
