<?php

namespace Tests\Feature;

use App\Models\AuctionItem;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThreeLevelCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('furimadeck.cutover_enabled', false);
    }

    public function test_user_can_register_a_product_in_a_third_level_category_and_filter_by_its_parent(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $parent = Category::create(['name' => '家電', 'sort_order' => 1]);
        $middle = Category::create(['parent_id' => $parent->id, 'name' => 'カメラ', 'sort_order' => 1]);
        $leaf = Category::create(['parent_id' => $middle->id, 'name' => 'デジタル一眼', 'sort_order' => 1]);

        $this->actingAs($user)
            ->post(route('auction-items.store'), [
                'management_id' => 'CATEGORY-001',
                'title' => 'デジタル一眼カメラ',
                'platform' => AuctionItem::PLATFORM_MERCARI,
                'category_id' => $leaf->id,
                'purchase_price' => 10000,
                'sold_price' => 20000,
                'shipping_fee' => 750,
                'sales_fee_rate' => 10,
            ])
            ->assertRedirect(route('auction-items.index'));

        $this->assertDatabaseHas('auction_items', [
            'user_id' => $user->id,
            'management_id' => 'CATEGORY-001',
            'category_id' => $leaf->id,
        ]);

        $this->actingAs($user)
            ->get(route('auction-items.index', ['parent_category_id' => $parent->id]))
            ->assertOk()
            ->assertSee('デジタル一眼カメラ', false);
    }

    public function test_product_form_keeps_existing_two_level_categories_usable(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $parent = Category::create(['name' => '本', 'sort_order' => 1]);
        Category::create(['parent_id' => $parent->id, 'name' => '小説', 'sort_order' => 1]);

        $this->actingAs($user)
            ->get(route('auction-items.create'))
            ->assertOk()
            ->assertSee('大ジャンル', false)
            ->assertSee('中ジャンル', false)
            ->assertSee('小ジャンル', false)
            ->assertSee('中ジャンルを最終ジャンルとして使用', false);
    }
}
