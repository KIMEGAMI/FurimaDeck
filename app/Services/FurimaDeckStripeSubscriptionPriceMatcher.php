<?php

namespace App\Services;

class FurimaDeckStripeSubscriptionPriceMatcher
{
    /** @param array<string, mixed> $subscription */
    public function matchesConfiguredPrice(array $subscription): bool
    {
        $configuredPriceId = config('furimadeck.billing.stripe_price_id');
        $items = data_get($subscription, 'items.data');

        if (! is_string($configuredPriceId)
            || ! str_starts_with($configuredPriceId, 'price_')
            || ! is_array($items)) {
            return false;
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $price = data_get($item, 'price');
            $priceId = is_array($price) ? data_get($price, 'id') : $price;
            if (is_string($priceId) && hash_equals($configuredPriceId, $priceId)) {
                return true;
            }
        }

        return false;
    }
}
