<?php

namespace Tests\Feature;

use App\Models\Marketplace;
use App\Models\User;
use App\Services\ListingLifecycleService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ListingLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.connections.sqlite');
        $connection['database'] = ':memory:';
        config()->set('database.connections.furimadeck', $connection);
        config()->set('furimadeck.cutover_enabled', true);
        config()->set('furimadeck.product_management_enabled', true);
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

    public function test_listing_can_move_to_active_but_cannot_move_back_to_ready(): void
    {
        $user = User::factory()->create();
        $product = $user->products()->create([
            'internal_sku' => 'LISTING-001',
            'product_name' => '出品テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'listing_ready',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'listing-marketplace',
            'name' => '出品テスト販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);
        $listing = $user->listings()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'listing_title' => '出品タイトル',
            'status' => 'ready',
        ]);

        $active = app(ListingLifecycleService::class)->update($user, $listing, [
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'listing_title' => '出品タイトル',
            'status' => 'active',
        ]);

        $this->assertSame('active', $active->status);
        $this->assertNotNull($active->listed_at);

        $this->expectException(RuntimeException::class);
        app(ListingLifecycleService::class)->update($user, $active, [
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'listing_title' => '出品タイトル',
            'status' => 'ready',
        ]);
    }

    public function test_listing_preserves_marketplace_specific_delivery_and_sale_details(): void
    {
        $user = User::factory()->create();
        $product = $user->products()->create([
            'internal_sku' => 'LISTING-DETAIL-001',
            'product_name' => '出品詳細テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'listing_ready',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'listing-detail-marketplace',
            'name' => '出品詳細テスト販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);

        $listing = app(ListingLifecycleService::class)->create($user, [
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'listing_title' => '出品詳細タイトル',
            'shipping_payer' => 'seller',
            'sender_region' => '東京都',
            'dispatch_days' => 2,
            'shipping_size' => '60サイズ',
            'shipping_weight_grams' => 500,
            'sale_format' => 'fixed_price',
            'listing_period_days' => 7,
            'return_policy' => '返品不可',
            'purchase_application' => 'disabled',
            'status' => 'draft',
        ]);

        $this->assertSame('seller', $listing->shipping_payer);
        $this->assertSame('東京都', $listing->sender_region);
        $this->assertSame(2, $listing->dispatch_days);
        $this->assertSame('60サイズ', $listing->shipping_size);
        $this->assertSame(500, $listing->shipping_weight_grams);
        $this->assertSame('fixed_price', $listing->sale_format);
        $this->assertSame(7, $listing->listing_period_days);
        $this->assertSame('返品不可', $listing->return_policy);
        $this->assertSame('disabled', $listing->purchase_application);
    }

    public function test_listing_create_page_renders_the_marketplace_readiness_preview(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->products()->create([
            'internal_sku' => 'LISTING-PREVIEW-001',
            'product_name' => '出品確認テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'listing_ready',
        ]);
        Marketplace::query()->create([
            'code' => 'yahoo_auctions',
            'name' => 'Yahoo!オークション',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('listings.create'))
            ->assertOk()
            ->assertSee('出品前確認')
            ->assertSee('Yahoo!オークション');
    }

    public function test_listing_store_calculates_the_profit_estimate_on_the_server(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $product = $user->products()->create([
            'internal_sku' => 'LISTING-PROFIT-001',
            'product_name' => '見込み利益テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 3000,
            'purchase_quantity' => 2,
            'quantity_available' => 2,
            'inventory_status' => 'listing_ready',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'listing-profit-marketplace',
            'name' => '見込み利益テスト販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('listings.store'), [
                'product_id' => $product->id,
                'marketplace_id' => $marketplace->id,
                'listing_title' => '見込み利益テスト出品',
                'listing_price' => 10000,
                'listing_quantity' => 2,
                'expected_fee_rate' => '10.00',
                'shipping_fee' => 750,
                'status' => 'draft',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('listings', [
            'product_id' => $product->id,
            'expected_profit' => 2250,
            'expected_margin' => 22.5,
        ]);

        $this->actingAs($user)
            ->get(route('listings.index'))
            ->assertOk()
            ->assertSee('見込み利益')
            ->assertSee('¥2,250');
    }

    public function test_other_marketplace_requires_and_stores_a_user_defined_name(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $product = $user->products()->create([
            'internal_sku' => 'LISTING-OTHER-001',
            'product_name' => 'その他販売先テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 0,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'listing_ready',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'other',
            'name' => 'その他',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);
        $payload = [
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'listing_title' => 'その他販売先テスト出品',
            'listing_quantity' => 1,
            'expected_fee_rate' => '0.00',
            'shipping_fee' => 0,
            'status' => 'draft',
        ];

        $this->actingAs($user)
            ->from(route('listings.create'))
            ->post(route('listings.store'), $payload)
            ->assertRedirect(route('listings.create'))
            ->assertSessionHasErrors('marketplace_other_name');

        $this->actingAs($user)
            ->post(route('listings.store'), [...$payload, 'marketplace_other_name' => '自社ECサイト'])
            ->assertRedirect();

        $this->assertDatabaseHas('listings', [
            'marketplace_id' => $marketplace->id,
            'marketplace_other_name' => '自社ECサイト',
        ]);
    }
}
