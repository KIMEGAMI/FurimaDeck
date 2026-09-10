<?php

namespace Tests\Feature;

use App\Models\CategoryAttribute;
use App\Models\ProductCategory;
use Database\Seeders\FurimaDeckCategorySeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FurimaDeckCategorySeederTest extends TestCase
{
    public function test_it_creates_categories_and_attributes_without_duplicate_records(): void
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

        app(FurimaDeckCategorySeeder::class)->run();
        app(FurimaDeckCategorySeeder::class)->run();

        $this->assertSame(4, ProductCategory::query()->whereNull('parent_id')->count());
        $this->assertSame(1, ProductCategory::query()->where('name', 'ゲーム機本体')->count());
        $this->assertSame(4, CategoryAttribute::query()
            ->whereHas('category', fn ($query) => $query->where('name', 'ゲーム機本体'))
            ->count());
    }
}
