<?php

namespace Tests\Feature;

use App\Models\AccountingEntry;
use App\Models\Marketplace;
use App\Models\User;
use App\Services\FurimaDeckAccountingEntryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FurimaDeckAccountingWorkflowTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');
        parent::tearDown();
    }

    public function test_review_then_confirm_changes_only_unsent_entries_in_the_selected_period(): void
    {
        [$user, $entry] = $this->entryFixture();
        $this->actingAs($user)->post(route('furimadeck-accounting.review'), ['year' => 2026])->assertRedirect();
        $this->assertSame('review', $entry->fresh()->source_status);

        $this->actingAs($user)->post(route('furimadeck-accounting.confirm'), ['year' => 2026])->assertRedirect();
        $this->assertSame('confirmed', $entry->fresh()->source_status);
    }

    public function test_sync_period_respects_japan_year_boundaries(): void
    {
        [$user, $entry] = $this->entryFixture();
        $baseSale = $user->sales()->firstOrFail();
        $baseSale->update(['sold_at' => '2025-12-31', 'sold_price' => 10000, 'net_profit' => 9000]);

        foreach ([
            ['date' => '2026-01-01', 'amount' => 20000],
            ['date' => '2026-06-15', 'amount' => 30000],
            ['date' => '2026-12-31', 'amount' => 40000],
            ['date' => '2027-01-01', 'amount' => 50000],
        ] as $boundary) {
            $sale = $baseSale->replicate();
            $sale->sold_at = $boundary['date'];
            $sale->sold_price = $boundary['amount'];
            $sale->net_profit = $boundary['amount'] - 1000;
            $sale->save();
        }

        $entries = app(FurimaDeckAccountingEntryService::class)->syncPeriod(
            $user,
            CarbonImmutable::parse('2026-01-01', config('app.timezone')),
            CarbonImmutable::parse('2027-01-01', config('app.timezone')),
        );

        $this->assertCount(3, $entries);
        $this->assertSame(90000, (int) $entries->sum('sale_amount'));
        $this->assertSame(['2026-01-01', '2026-06-15', '2026-12-31'], $entries->pluck('transaction_date')->map(fn ($date): string => $date->toDateString())->all());
        $this->assertDatabaseMissing('accounting_entries', ['transaction_date' => '2025-12-31']);
        $this->assertDatabaseMissing('accounting_entries', ['transaction_date' => '2027-01-01']);
    }

    public function test_review_is_user_scoped(): void
    {
        [$owner, $entry] = $this->entryFixture();
        $otherUser = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);

        $this->actingAs($otherUser)->post(route('furimadeck-accounting.review'), ['year' => 2026])->assertRedirect();
        $this->assertSame('ready', $entry->fresh()->source_status);
    }

    /** @return array{0: User, 1: AccountingEntry} */
    private function entryFixture(): array
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $product = $user->products()->create([
            'internal_sku' => 'WORKFLOW-'.uniqid(),
            'product_name' => '会計ワークフロー商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 0,
            'inventory_status' => 'out_of_stock',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'workflow-'.uniqid(),
            'name' => 'ワークフロー販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);
        $sale = $user->sales()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => 1,
            'sold_price' => 3000,
            'sold_at' => '2026-01-10',
            'status' => 'completed',
            'cost_basis' => 1000,
            'net_profit' => 2000,
        ]);
        $entry = AccountingEntry::query()->create([
            'user_id' => $user->id,
            'sale_id' => $sale->id,
            'transaction_date' => '2026-01-10',
            'management_id' => $product->internal_sku,
            'title' => $product->product_name,
            'sale_amount' => 3000,
            'purchase_cost' => 1000,
            'furimadeck_actual_profit' => 2000,
            'source_status' => 'ready',
            'sync_status' => 'not_synced',
        ]);

        return [$user, $entry];
    }
}
