<?php

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductCsvImportCommitTest extends TestCase
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

    public function test_valid_preview_can_be_explicitly_committed_as_products(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $file = UploadedFile::fake()->createWithContent('products.csv', implode("\n", [
            'internal_sku,product_name,condition,purchase_unit_cost,purchase_quantity,quantity_available,inventory_status',
            'CSV-001,CSV商品,used,1000,2,2,in_stock',
        ]));

        $this->actingAs($user)
            ->post(route('products.imports.preview'), ['csv_file' => $file])
            ->assertRedirect();

        $batch = ImportBatch::query()->firstOrFail();
        $this->actingAs($user)
            ->post(route('products.imports.commit', $batch))
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', [
            'user_id' => $user->id,
            'internal_sku' => 'CSV-001',
            'product_name' => 'CSV商品',
            'quantity_available' => 2,
        ], 'furimadeck');
        $this->assertSame('completed', $batch->fresh()->status);
    }

    public function test_existing_sku_prevents_the_entire_import_from_being_registered(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $user->products()->create([
            'internal_sku' => 'DUPLICATE-001',
            'product_name' => '既存商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $file = UploadedFile::fake()->createWithContent('products.csv', implode("\n", [
            'internal_sku,product_name,condition,purchase_unit_cost,purchase_quantity,quantity_available,inventory_status',
            'DUPLICATE-001,重複商品,used,1000,1,1,in_stock',
        ]));

        $this->actingAs($user)->post(route('products.imports.preview'), ['csv_file' => $file]);
        $batch = ImportBatch::query()->firstOrFail();

        $this->actingAs($user)
            ->from(route('products.imports.show', $batch))
            ->post(route('products.imports.commit', $batch))
            ->assertRedirect(route('products.imports.show', $batch))
            ->assertSessionHasErrors('csv_file');

        $this->assertSame(1, $user->products()->count());
        $this->assertSame('preview', $batch->fresh()->status);
    }

    public function test_active_user_can_download_their_product_csv(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $user->products()->create([
            'internal_sku' => 'EXPORT-001',
            'product_name' => '出力商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-export.products'))
            ->assertOk()
            ->assertDownload('products.csv');
    }

    public function test_product_csv_escapes_formula_like_cells(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $user->products()->create([
            'internal_sku' => '=IMPORTXML("https://example.test","//title")',
            'product_name' => "\t+SUM(1,1)",
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $user->products()->create([
            'internal_sku' => 'EXPORT-002',
            'product_name' => '＝SUM(1,1)',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);

        $response = $this->actingAs($user)->get(route('furimadeck-export.products'));

        $response->assertOk()->assertDownload('products.csv');

        $csv = $response->streamedContent();

        $this->assertStringContainsString("'=IMPORTXML(\"\"https://example.test\"\",\"\"//title\"\")", $csv);
        $this->assertStringContainsString("'\t+SUM(1,1)", $csv);
        $this->assertStringContainsString("'＝SUM(1,1)", $csv);
    }
}
