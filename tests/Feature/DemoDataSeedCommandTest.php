<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoDataSeedCommandTest extends TestCase
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

    public function test_it_seeds_products_listings_and_sales_without_duplicating_existing_demo_data(): void
    {
        config([
            'demo.user_enabled' => true,
            'demo.user_email' => 'demo@example.com',
            'demo.user_password' => 'demo-password',
        ]);
        $user = User::factory()->create(['email' => 'demo@example.com']);
        $root = ProductCategory::query()->create([
            'name' => 'テスト大項目',
            'slug' => 'demo-test-root',
            'sort_order' => 0,
            'is_active' => true,
        ]);
        ProductCategory::query()->create([
            'parent_id' => $root->id,
            'name' => 'テスト詳細カテゴリ',
            'slug' => 'demo-test-leaf',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this->artisan('furimadeck:seed-demo-data')
            ->expectsOutputToContain('商品244件')
            ->assertSuccessful();

        $this->assertSame(244, Product::query()->where('user_id', $user->id)->count());
        $this->assertGreaterThan(0, Listing::query()->where('user_id', $user->id)->count());
        $this->assertGreaterThan(0, Sale::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, Product::query()->where('user_id', $user->id)->whereNull('category_id')->count());

        $this->artisan('furimadeck:seed-demo-data')
            ->expectsOutputToContain('商品0件')
            ->assertSuccessful();

        $this->assertSame(244, Product::query()->where('user_id', $user->id)->count());
        $this->assertSame(157, Sale::query()->where('user_id', $user->id)->count());
    }
}
