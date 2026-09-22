<?php

namespace Tests\Feature;

use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncRakumaCategoriesTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');

        parent::tearDown();
    }

    public function test_it_imports_the_public_tree_at_any_depth_and_preserves_legacy_categories_used_by_products(): void
    {
        $legacyRoot = ProductCategory::query()->create([
            'name' => '旧カテゴリ',
            'slug' => 'legacy-root',
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $legacyLeaf = ProductCategory::query()->create([
            'parent_id' => $legacyRoot->id,
            'name' => '旧商品カテゴリ',
            'slug' => 'legacy-leaf',
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $user = User::factory()->create();
        $user->products()->create([
            'internal_sku' => 'LEGACY-CATEGORY-001',
            'product_name' => '既存商品',
            'category_id' => $legacyLeaf->id,
            'condition' => 'used',
            'purchase_unit_cost' => 0,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);

        Http::fake([
            'https://fril.jp/category' => Http::response($this->categoryPage()),
        ]);

        $this->artisan('furimadeck:sync-rakuma-categories')
            ->expectsOutputToContain('全4件')
            ->assertSuccessful();

        $detail = ProductCategory::query()->where('source_path', 'rakuma/400')->firstOrFail();
        $this->assertSame('詳細カテゴリ', $detail->name);
        $this->assertSame('rakuma/300', $detail->parent->source_path);
        $this->assertTrue($legacyLeaf->fresh()->is_active);
        $this->assertTrue($legacyRoot->fresh()->is_active);
    }

    private function categoryPage(): string
    {
        $categories = [
            ['id' => 100, 'parentId' => 0, 'name' => '大項目'],
            ['id' => 200, 'parentId' => 100, 'name' => '中項目'],
            ['id' => 300, 'parentId' => 200, 'name' => '小項目'],
            ['id' => 400, 'parentId' => 300, 'name' => '詳細カテゴリ'],
        ];

        $json = json_encode($categories, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return '<script>self.__next_f.push([1,"categoryList\\":'.addcslashes($json, '"').'"])</script>';
    }
}
