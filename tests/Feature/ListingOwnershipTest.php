<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\Marketplace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListingOwnershipTest extends TestCase
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

    public function test_other_user_cannot_view_update_or_delete_a_listing(): void
    {
        [$owner, $listing] = $this->listingFixture();
        $otherUser = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($otherUser)->get(route('listings.edit', $listing))->assertNotFound();
        $this->actingAs($otherUser)->put(route('listings.update', $listing), [
            'product_id' => $listing->product_id,
            'marketplace_id' => $listing->marketplace_id,
            'listing_title' => '不正更新',
            'listing_quantity' => 1,
            'expected_fee_rate' => '0.00',
            'status' => 'draft',
        ])->assertNotFound();
        $this->actingAs($otherUser)->delete(route('listings.destroy', $listing), ['confirm_deletion' => '1'])->assertNotFound();

        $this->assertSame('元の出品', $listing->fresh()->listing_title);
        $this->assertSame($owner->id, $listing->fresh()->user_id);
    }

    /** @return array{0: User, 1: Listing} */
    private function listingFixture(): array
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $product = $owner->products()->create([
            'internal_sku' => 'LISTING-OWNER-'.uniqid(),
            'product_name' => '所有者分離テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'listing_ready',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'listing-owner-'.uniqid(),
            'name' => '所有者分離テスト販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);
        $listing = $owner->listings()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'listing_title' => '元の出品',
            'listing_quantity' => 1,
            'expected_fee_rate' => 0,
            'status' => 'draft',
        ]);

        return [$owner, $listing];
    }
}
