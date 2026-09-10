<?php

namespace Tests\Feature;

use App\Models\Category;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UniversalCategorySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_adds_three_level_categories_for_multiple_product_types_without_removing_existing_categories(): void
    {
        Category::create(['name' => 'トップス', 'sort_order' => 0]);

        $this->seed(CategorySeeder::class);

        $camera = Category::query()
            ->where('name', 'カメラ')
            ->whereHas('parent', fn ($query) => $query->where('name', '家電・スマホ・カメラ'))
            ->firstOrFail();

        $this->assertDatabaseHas('categories', [
            'parent_id' => $camera->id,
            'name' => 'デジタルカメラ',
        ]);
        $this->assertDatabaseHas('categories', [
            'parent_id' => null,
            'name' => 'トップス',
        ]);
    }
}
