<?php

namespace Tests\Feature;

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
        $other = $this->createCategory('本', 'books');
        $this->createProduct($user, 'CATEGORY-FILTER-001', '対象商品', $minor->id);
        $this->createProduct($user, 'CATEGORY-FILTER-002', '対象外商品', $other->id);

        $this->actingAs($user)
            ->get(route('products.index', ['category_id' => $major->id]))
            ->assertOk()
            ->assertSee('対象商品')
            ->assertDontSee('対象外商品');
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

    private function createProduct(User $user, string $sku, string $name, int $categoryId): void
    {
        $user->products()->create([
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
