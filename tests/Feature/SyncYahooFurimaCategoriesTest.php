<?php

namespace Tests\Feature;

use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncYahooFurimaCategoriesTest extends TestCase
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
            'https://paypayfleamarket.yahoo.co.jp/category' => Http::response($this->categoryPage([
                '/category/100' => '大項目',
            ])),
            'https://paypayfleamarket.yahoo.co.jp/category/100' => Http::response($this->categoryPage([
                '/category/100/200' => '中項目',
            ])),
            'https://paypayfleamarket.yahoo.co.jp/category/100/200' => Http::response($this->categoryPage([
                '/category/100/200/300' => '小項目',
            ])),
            'https://paypayfleamarket.yahoo.co.jp/category/100/200/300' => Http::response($this->categoryPage([
                '/category/100/200/300/400' => '詳細カテゴリ',
            ])),
            'https://paypayfleamarket.yahoo.co.jp/category/100/200/300/400' => Http::response($this->categoryPage()),
        ]);

        $this->artisan('furimadeck:sync-yahoo-categories')
            ->expectsOutputToContain('全4件')
            ->assertSuccessful();

        $detail = ProductCategory::query()->where('source_path', '100/200/300/400')->firstOrFail();
        $this->assertSame('詳細カテゴリ', $detail->name);
        $this->assertSame('100/200/300', $detail->parent->source_path);
        $this->assertTrue($legacyLeaf->fresh()->is_active);
        $this->assertTrue($legacyRoot->fresh()->is_active);
    }

    /** @param array<string, string> $links */
    private function categoryPage(array $links = []): string
    {
        return '<html><body>'.collect($links)
            ->map(fn (string $name, string $href) => '<a href="'.$href.'"><span>'.$name.'</span></a>')
            ->implode('').'</body></html>';
    }
}
