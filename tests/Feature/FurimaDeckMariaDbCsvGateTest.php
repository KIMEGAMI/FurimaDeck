<?php

namespace Tests\Feature;

use App\Models\Marketplace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FurimaDeckMariaDbCsvGateTest extends TestCase
{
    public function test_restore_csv_export_runs_against_mariadb_without_persisting_test_data(): void
    {
        if (env('FURIMADECK_MARIADB_GATE') !== '1') {
            $this->markTestSkipped('MariaDB gate is opt-in.');
        }

        config()->set('furimadeck.cutover_enabled', true);
        config()->set('furimadeck.product_management_enabled', true);
        DB::purge('furimadeck');
        DB::setDefaultConnection('furimadeck');
        $schema = Schema::connection('furimadeck');
        $this->assertTrue($schema->hasTable('products'));
        $this->assertTrue($schema->hasTable('listings'));
        $this->assertTrue($schema->hasTable('sales'));

        DB::beginTransaction();
        try {
            $marketplace = Marketplace::query()->create([
                'code' => 'mariadb-gate-'.bin2hex(random_bytes(4)),
                'name' => 'MariaDBゲート',
                'base_url' => 'https://example.test',
                'default_fee_rate' => 10,
                'is_active' => true,
            ]);
            $user = User::factory()->create([
                'email_verified_at' => now(),
                'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
                'subscription_status' => 'active',
            ]);
            $product = $user->products()->create([
                'internal_sku' => 'MARIADB-GATE-001',
                'product_name' => 'MariaDB確認商品',
                'condition' => 'used',
                'purchase_unit_cost' => 1200,
                'purchase_quantity' => 1,
                'quantity_available' => 1,
                'inventory_status' => 'listed',
            ]);
            $user->listings()->create([
                'product_id' => $product->id,
                'marketplace_id' => $marketplace->id,
                'listing_title' => $product->product_name,
                'listing_price' => 2500,
                'expected_fee_rate' => 10,
                'shipping_fee' => 210,
                'status' => 'active',
            ]);

            $response = $this->actingAs($user)->get(route('furimadeck-export.restore-spec'));

            $response->assertOk()->assertDownload('furimadeck-restore.csv');
            $this->assertStringContainsString('MARIADB-GATE-001,MariaDB確認商品', $response->streamedContent());
        } finally {
            DB::rollBack();
        }
    }
}
