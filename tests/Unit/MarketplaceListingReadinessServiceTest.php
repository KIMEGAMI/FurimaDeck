<?php

namespace Tests\Unit;

use App\Models\Marketplace;
use App\Services\MarketplaceListingReadinessService;
use Tests\TestCase;

class MarketplaceListingReadinessServiceTest extends TestCase
{
    public function test_yahoo_auctions_requires_the_auction_specific_sale_settings(): void
    {
        $marketplace = new Marketplace(['code' => 'yahoo_auctions']);

        $requirements = app(MarketplaceListingReadinessService::class)->requirementsFor($marketplace);

        $this->assertArrayHasKey('sale_format', $requirements);
        $this->assertArrayHasKey('listing_period_days', $requirements);
        $this->assertArrayHasKey('shipping_payer', $requirements);
        $this->assertArrayHasKey('listing_title', $requirements);
    }

    public function test_rakuma_requires_its_purchase_application_setting(): void
    {
        $marketplace = new Marketplace(['code' => 'rakuma']);

        $requirements = app(MarketplaceListingReadinessService::class)->requirementsFor($marketplace);

        $this->assertArrayHasKey('purchase_application', $requirements);
        $this->assertArrayNotHasKey('sale_format', $requirements);
    }
}
