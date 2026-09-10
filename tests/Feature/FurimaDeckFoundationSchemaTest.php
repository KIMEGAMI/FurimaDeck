<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FurimaDeckFoundationSchemaTest extends TestCase
{
    public function test_clean_furimadeck_migration_creates_the_foundation_without_legacy_tables(): void
    {
        $connection = config('database.connections.sqlite');
        $connection['database'] = ':memory:';

        config(['database.connections.furimadeck' => $connection]);
        DB::purge('furimadeck');

        $this->artisan('migrate', [
            '--database' => 'furimadeck',
            '--path' => 'database/migrations/furimadeck',
        ])->assertSuccessful();

        $schema = Schema::connection('furimadeck');

        foreach ([
            'users',
            'password_reset_tokens',
            'sessions',
            'suppliers',
            'product_categories',
            'category_attributes',
            'products',
            'product_attribute_values',
            'product_images',
            'marketplaces',
            'listings',
            'sales',
            'audit_logs',
            'ai_response_caches',
            'ai_usage_logs',
            'import_batches',
            'import_row_results',
            'furimadeck_stripe_webhook_events',
        ] as $table) {
            $this->assertTrue($schema->hasTable($table), $table.' must exist in the FurimaDeck database.');
        }

        $this->assertFalse($schema->hasTable('auction_items'));
        $this->assertTrue($schema->hasColumns('products', [
            'user_id',
            'internal_sku',
            'product_name',
            'quantity_available',
            'inventory_status',
        ]));
        $this->assertTrue($schema->hasColumn('product_images', 'storage_disk'));
    }
}
