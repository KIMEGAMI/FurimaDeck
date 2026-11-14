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

    public function test_admin_and_demo_user_have_complimentary_premium_access(): void
    {
        config()->set('demo.user_enabled', true);
        config()->set('demo.user_email', 'demo@example.com');

        $admin = new User(['is_admin' => true, 'subscription_plan' => User::SUBSCRIPTION_INACTIVE]);
        $demo = new User(['email' => 'demo@example.com', 'subscription_plan' => User::SUBSCRIPTION_INACTIVE]);

        $this->assertTrue($admin->hasComplimentaryPremiumAccess());
        $this->assertTrue($admin->hasActiveSubscription());
        $this->assertTrue($demo->hasComplimentaryPremiumAccess());
        $this->assertTrue($demo->hasActiveSubscription());
    }

    public function test_configured_demo_user_email_matches_case_insensitively_without_upgrading_other_free_users(): void
    {
        config()->set('demo.user_enabled', true);
        config()->set('demo.user_email', 'Demo.User@example.com');

        $demo = new User([
            'email' => 'demo.user@example.com',
            'subscription_plan' => User::SUBSCRIPTION_INACTIVE,
        ]);
        $free = new User([
            'email' => 'another.user@example.com',
            'subscription_plan' => User::SUBSCRIPTION_INACTIVE,
        ]);

        $this->assertTrue($demo->isDemoUser());
        $this->assertTrue($demo->hasActiveSubscription());
        $this->assertFalse($free->isDemoUser());
        $this->assertFalse($free->hasActiveSubscription());
    }
}
