<?php

namespace Tests\Feature;

use App\Models\AccountingConnection;
use App\Models\AccountingEntry;
use App\Models\Marketplace;
use App\Models\User;
use App\Services\FurimaDeckMoneyForwardAccountingSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FurimaDeckMoneyForwardAccountingSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $connection = config('database.connections.sqlite');
        $connection['database'] = ':memory:';
        config()->set('database.connections.furimadeck', $connection);
        DB::purge('furimadeck');
        DB::setDefaultConnection('furimadeck');
        $this->artisan('migrate', ['--database' => 'furimadeck', '--path' => 'database/migrations/furimadeck'])->assertSuccessful();
        config()->set('furimadeck.cutover_enabled', true);
        config()->set('furimadeck.product_management_enabled', true);
        config()->set('furimadeck.accounting.oauth.money_forward.accounting_api_base', 'https://api-accounting.test');
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');
        parent::tearDown();
    }

    public function test_confirmed_entry_is_sent_to_money_forward_and_marked_synced(): void
    {
        [$user, $entry] = $this->entryFixture();
        Http::fake(['https://api-accounting.test/api/v3/journals' => Http::response(['journal' => ['id' => 'mf-journal-1']], 201)]);

        $id = app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);

        $this->assertSame('mf-journal-1', $id);
        $this->assertSame('synced', $entry->fresh()->sync_status);
        Http::assertSent(function ($request): bool {
            $journal = $request->data()['journal'];

            return $request->url() === 'https://api-accounting.test/api/v3/journals'
                && $journal['journal_type'] === 'journal_entry'
                && count($journal['branches']) === 3
                && $journal['branches'][0]['debitor']['value'] === 10000;
        });
    }

    /** @return array{0: User, 1: AccountingEntry} */
    private function entryFixture(): array
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $product = $user->products()->create(['internal_sku' => 'MF-'.uniqid(), 'product_name' => 'MF商品', 'condition' => 'used', 'purchase_unit_cost' => 1000, 'purchase_quantity' => 1, 'quantity_available' => 0, 'inventory_status' => 'out_of_stock']);
        $marketplace = Marketplace::query()->create(['code' => 'mf-'.uniqid(), 'name' => 'MF販売先', 'base_url' => 'https://example.test', 'fee_type' => 'percentage', 'default_fee_rate' => 0, 'is_active' => true]);
        $sale = $user->sales()->create(['product_id' => $product->id, 'marketplace_id' => $marketplace->id, 'internal_sku_snapshot' => $product->internal_sku, 'product_name_snapshot' => $product->product_name, 'quantity' => 1, 'sold_price' => 10000, 'sold_at' => '2026-01-10', 'status' => 'completed', 'cost_basis' => 1000, 'sales_fee' => 500, 'shipping_fee' => 500, 'net_profit' => 8000]);
        $entry = AccountingEntry::query()->create(['user_id' => $user->id, 'sale_id' => $sale->id, 'transaction_date' => '2026-01-10', 'management_id' => $product->internal_sku, 'title' => $product->product_name, 'sale_amount' => 10000, 'purchase_cost' => 1000, 'selling_fee' => 500, 'shipping_cost' => 500, 'furimadeck_actual_profit' => 8000, 'source_status' => 'confirmed', 'sync_status' => 'not_synced']);
        AccountingConnection::query()->create(['user_id' => $user->id, 'provider' => 'money_forward', 'access_token' => 'mf-access', 'refresh_token' => 'mf-refresh', 'expires_at' => now()->addHour(), 'status' => 'connected', 'settings' => ['account_ids' => ['settlement' => 'settlement-id', 'sales' => 'sales-id', 'fees' => 'fees-id', 'cost' => 'cost-id', 'inventory' => 'inventory-id'], 'tax_id' => 'tax-id']]);

        return [$user, $entry];
    }
}
