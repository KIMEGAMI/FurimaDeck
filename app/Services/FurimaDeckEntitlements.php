<?php

namespace App\Services;

use App\Models\User;

class FurimaDeckEntitlements
{
    public function canCreateProduct(User $user, int $currentProductCount): bool
    {
        $limit = $this->productLimit($user);

        return $limit === null || $currentProductCount < $limit;
    }

    public function productLimit(User $user): ?int
    {
        if ($user->hasActiveSubscription()) {
            return null;
        }

        return (int) config('furimadeck.plans.free.product_limit');
    }
}
