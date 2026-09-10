<?php

namespace App\Services;

use App\Models\Marketplace;

class MarketplaceListingReadinessService
{
    private const BASE_REQUIREMENTS = [
        'product_id' => '商品',
        'listing_title' => '出品タイトル',
        'marketplace_category' => '販売先カテゴリ',
        'marketplace_condition' => '販売先の商品状態',
        'listing_description' => '出品説明',
        'listing_price' => '出品価格',
        'shipping_method' => '配送方法',
        'sender_region' => '発送元地域',
        'dispatch_days' => '発送までの日数',
    ];

    private const REQUIREMENTS_BY_MARKETPLACE = [
        'mercari' => [
            'shipping_payer' => '送料負担',
        ],
        'yahoo_auctions' => [
            'shipping_payer' => '送料負担',
            'sale_format' => '販売形式',
            'listing_period_days' => '掲載期間',
        ],
        'rakuma' => [
            'shipping_payer' => '送料負担',
            'purchase_application' => '購入申請',
        ],
    ];

    /** @param iterable<int, Marketplace> $marketplaces
     * @return array<int, array<string, string>>
     */
    public function requirementsForMarketplaces(iterable $marketplaces): array
    {
        $requirements = [];

        foreach ($marketplaces as $marketplace) {
            $requirements[$marketplace->id] = $this->requirementsFor($marketplace);
        }

        return $requirements;
    }

    /** @return array<string, string> */
    public function requirementsFor(Marketplace $marketplace): array
    {
        return [
            ...self::BASE_REQUIREMENTS,
            ...(self::REQUIREMENTS_BY_MARKETPLACE[$marketplace->code] ?? []),
        ];
    }
}
