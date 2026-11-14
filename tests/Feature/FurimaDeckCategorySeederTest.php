<?php

namespace Tests\Feature;

use App\Models\ProductCategory;
use Database\Seeders\FurimaDeckCategorySeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FurimaDeckCategorySeederTest extends TestCase
{
    public function test_it_installs_the_full_offline_rakuma_category_snapshot_idempotently(): void
    {
        $connection = config('database.connections.sqlite');
        $connection['database'] = ':memory:';

        config([
            'database.connections.furimadeck' => $connection,
            'database.default' => 'furimadeck',
        ]);
        DB::purge('furimadeck');

        $this->artisan('migrate', [
            '--database' => 'furimadeck',
            '--path' => 'database/migrations/furimadeck',
        ])->assertSuccessful();

        $this->seed(FurimaDeckCategorySeeder::class);
        $this->seed(FurimaDeckCategorySeeder::class);

        $this->assertSame(1686, ProductCategory::query()->count());
        $this->assertSame(14, ProductCategory::query()->whereNull('parent_id')->count());
        $this->assertDatabaseHas('product_categories', [
            'name' => 'メンズ',
            'source_path' => 'rakuma/10005',
        ], 'furimadeck');
    }
}
