<?php

namespace Database\Seeders;

use App\Models\Marketplace;
use Illuminate\Database\Seeder;

class FurimaDeckMarketplaceSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'mercari', 'name' => 'メルカリ', 'base_url' => 'https://jp.mercari.com/', 'default_fee_rate' => 10.0],
            ['code' => 'yahoo_flea_market', 'name' => 'Yahoo!フリマ', 'base_url' => 'https://paypayfleamarket.yahoo.co.jp/', 'default_fee_rate' => 5.0],
            ['code' => 'yahoo_auctions', 'name' => 'Yahoo!オークション', 'base_url' => 'https://auctions.yahoo.co.jp/', 'default_fee_rate' => 10.0],
            ['code' => 'rakuma', 'name' => 'ラクマ', 'base_url' => 'https://fril.jp/', 'default_fee_rate' => 10.0],
            ['code' => 'other', 'name' => 'その他', 'base_url' => 'https://example.invalid/', 'default_fee_rate' => 0.0],
        ] as $marketplace) {
            Marketplace::firstOrCreate(['code' => $marketplace['code']], $marketplace + [
                'fee_type' => 'percentage',
                'default_fee_rate' => $marketplace['default_fee_rate'],
                'is_active' => true,
            ]);
        }
    }
}
