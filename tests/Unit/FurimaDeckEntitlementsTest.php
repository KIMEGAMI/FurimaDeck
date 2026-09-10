<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\FurimaDeckEntitlements;
use Tests\TestCase;

class FurimaDeckEntitlementsTest extends TestCase
{
    public function test_free_user_cannot_create_a_product_at_the_configured_limit(): void
    {
        config()->set('furimadeck.plans.free.product_limit', 50);
        $user = new User(['subscription_plan' => User::SUBSCRIPTION_INACTIVE]);
        $entitlements = app(FurimaDeckEntitlements::class);

        $this->assertTrue($entitlements->canCreateProduct($user, 49));
        $this->assertFalse($entitlements->canCreateProduct($user, 50));
    }

    public function test_premium_user_has_no_product_count_limit(): void
    {
        $user = new User([
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);

        $this->assertNull(app(FurimaDeckEntitlements::class)->productLimit($user));
    }
}
