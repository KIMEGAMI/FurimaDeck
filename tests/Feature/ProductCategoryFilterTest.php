<?php

namespace Tests\Feature;

use App\Models\Marketplace;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductCategoryFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.connections.sqlite');
        $connection['database'] = ':memory:';
        config()->set('database.connections.furimadeck', $connection);
        config()->set('furimadeck.cutover_enabled', true);
        config()->set('furimadeck.product_management_enabled', true);
        DB::purge('furimadeck');
        DB::setDefaultConnection('furimadeck');
        $this->artisan('migrate', [
            '--database' => 'furimadeck',
            '--path' => 'database/migrations/furimadeck',
        ])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');

        parent::tearDown();
    }

    public function test_filtering_by_a_major_category_includes_its_descendant_product_categories(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $major = $this->createCategory('家電', 'electronics');
        $middle = $this->createCategory('カメラ', 'electronics-camera', $major->id);
        $minor = $this->createCategory('デジタルカメラ', 'electronics-camera-digital', $middle->id);
        $detail = $this->createCategory('ミラーレス一眼', 'electronics-camera-digital-mirrorless', $minor->id);
        $other = $this->createCategory('本', 'books');
        $this->createProduct($user, 'CATEGORY-FILTER-001', '対象商品', $detail->id);
        $this->createProduct($user, 'CATEGORY-FILTER-002', '対象外商品', $other->id);

        $this->actingAs($user)
            ->get(route('products.index', ['category_id' => $major->id]))
            ->assertOk()
            ->assertSee('対象商品')
            ->assertDontSee('対象外商品');
    }

    public function test_filtering_by_unsold_days_only_shows_available_old_inventory(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $category = $this->createCategory('家電', 'electronics');
        $oldProduct = $this->createProduct($user, 'STALE-001', '30日以上の在庫', $category->id);
        $newProduct = $this->createProduct($user, 'STALE-002', '新しい在庫', $category->id);
        $this->createProduct($user, 'STALE-003', '在庫なしの商品', $category->id);

        $marketplace = Marketplace::query()->create(['code' => 'mercari', 'name' => 'メルカリ', 'base_url' => 'https://example.test', 'fee_type' => 'percentage', 'default_fee_rate' => 0, 'is_active' => true]);
        $user->listings()->create(['product_id' => $oldProduct->id, 'marketplace_id' => $marketplace->id, 'listing_title' => $oldProduct->product_name, 'listing_price' => 1000, 'listing_quantity' => 1, 'status' => 'active', 'listed_at' => now()->subDays(30)]);
        $user->listings()->create(['product_id' => $newProduct->id, 'marketplace_id' => $marketplace->id, 'listing_title' => $newProduct->product_name, 'listing_price' => 1000, 'listing_quantity' => 1, 'status' => 'active', 'listed_at' => now()->subDays(29)]);
        $user->products()->where('internal_sku', 'STALE-003')->firstOrFail()->forceFill(['created_at' => now()->subDays(60), 'quantity_available' => 0])->save();

        $this->actingAs($user)
            ->get(route('products.index', ['stale_days' => 30]))
            ->assertOk()
            ->assertSee('出品日から30日以上、売上履歴がなく在庫が残っている商品を表示しています。')
            ->assertSee('30日以上の在庫')
            ->assertDontSee('新しい在庫')
            ->assertDontSee('在庫なしの商品');
    }

    private function createCategory(string $name, string $slug, ?int $parentId = null): ProductCategory
    {
        return ProductCategory::query()->create([
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => $slug,
            'sort_order' => 0,
            'is_active' => true,
        ]);
    }

    private function createProduct(User $user, string $sku, string $name, int $categoryId): Product
    {
        return $user->products()->create([
            'internal_sku' => $sku,
            'product_name' => $name,
            'category_id' => $categoryId,
            'condition' => 'used',
            'purchase_unit_cost' => 0,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
    }
}
