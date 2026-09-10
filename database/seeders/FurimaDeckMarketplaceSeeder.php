<?php

namespace Database\Seeders;

use App\Models\Marketplace;
use Illuminate\Database\Seeder;

class FurimaDeckMarketplaceSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'mercari', 'name' => 'メルカリ', 'base_url' => 'https://jp.mercari.com/'],
            ['code' => 'yahoo_flea_market', 'name' => 'Yahoo!フリマ', 'base_url' => 'https://paypayfleamarket.yahoo.co.jp/'],
            ['code' => 'yahoo_auctions', 'name' => 'Yahoo!オークション', 'base_url' => 'https://auctions.yahoo.co.jp/'],
            ['code' => 'rakuma', 'name' => 'ラクマ', 'base_url' => 'https://fril.jp/'],
            ['code' => 'other', 'name' => 'その他', 'base_url' => 'https://example.invalid/'],
        ] as $marketplace) {
            Marketplace::firstOrCreate(['code' => $marketplace['code']], $marketplace + [
                'fee_type' => 'percentage',
                'default_fee_rate' => 0,
                'is_active' => true,
            ]);
        }
    }
}
