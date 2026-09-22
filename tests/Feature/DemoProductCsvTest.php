<?php

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoProductCsvTest extends TestCase
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
        config()->set('furimadeck.cutover_enabled', true);
        config()->set('furimadeck.product_management_enabled', true);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');

        parent::tearDown();
    }

    public function test_the_demo_csv_can_be_previewed_and_committed_as_furimadeck_products(): void
    {
        $user = User::factory()->create([
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $csv = file_get_contents(base_path('dummy_auction_items_2026_06_2027_06.csv'));

        $this->assertNotFalse($csv);

        $this->actingAs($user)
            ->post(route('products.imports.preview'), [
                'csv_file' => UploadedFile::fake()->createWithContent('demo-products.csv', $csv),
            ])
            ->assertRedirect();

        $batch = ImportBatch::query()->firstOrFail();
        $this->assertSame(244, $batch->total_rows);
        $this->assertSame(244, $batch->success_rows);
        $this->assertSame(0, $batch->failed_rows);

        $this->actingAs($user)
            ->post(route('products.imports.commit', $batch))
            ->assertRedirect(route('products.index'));

        $products = Product::query()->where('user_id', $user->id);
        $this->assertSame(244, $products->count());
        $this->assertStringStartsWith('2026-06', (string) (clone $products)->min('purchase_date'));
        $this->assertStringStartsWith('2027-06', (string) (clone $products)->max('purchase_date'));
    }
}
