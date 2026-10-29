<?php

use App\Models\AuctionItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

Schedule::command('furimadeck:accounting-recover-stale-sends')->everyMinute()->withoutOverlapping();

Artisan::command('furugi:dummy-sales', function () {
    $user = User::query()->first();

    if (! $user) {
        $this->error('ユーザーが存在しません。先に会員登録をしてください。');

        return;
    }

    $platforms = ['ヤフオク', 'メルカリ', 'ラクマ', 'Yahooフリマ', 'その他'];

    $titles = [
        'ヴィンテージ デニムジャケット',
        '古着 スウェット',
        'チェックシャツ',
        'ミリタリージャケット',
        'レザージャケット',
        'ワークパンツ',
        'ナイロンパーカー',
        '古着 Tシャツ',
        'カーディガン',
        'コーデュロイパンツ',
    ];

    $createdCount = 0;

    for ($monthOffset = 0; $monthOffset < 6; $monthOffset++) {
        $targetMonth = Carbon::now()->subMonths($monthOffset);

        for ($i = 1; $i <= 5; $i++) {
            $purchasePrice = rand(800, 5000);
            $soldPrice = $purchasePrice + rand(1000, 9000);
            $platform = $platforms[array_rand($platforms)];
            $salesFeeRate = defaultFurugiSalesFeeRate($platform);
            $salesFee = (int) round($soldPrice * ($salesFeeRate / 100));
            $shippingFee = rand(300, 1200);
            $profit = $soldPrice - $purchasePrice - $salesFee - $shippingFee;

            $item = new AuctionItem;

            if (Schema::hasColumn('auction_items', 'user_id')) {
                $item->user_id = $user->id;
            }

            if (Schema::hasColumn('auction_items', 'management_id')) {
                $item->management_id = 'DUMMY-'.$targetMonth->format('Ym').'-'.Str::upper(Str::random(5));
            }

            if (Schema::hasColumn('auction_items', 'title')) {
                $item->title = $titles[array_rand($titles)];
            }

            if (Schema::hasColumn('auction_items', 'comment')) {
                $item->comment = '月別売上グラフ確認用のダミーデータです。';
            }

            if (Schema::hasColumn('auction_items', 'purchase_price')) {
                $item->purchase_price = $purchasePrice;
            }

            if (Schema::hasColumn('auction_items', 'sold_price')) {
                $item->sold_price = $soldPrice;
            }

            if (Schema::hasColumn('auction_items', 'sales_fee_rate')) {
                $item->sales_fee_rate = $salesFeeRate;
            }

            if (Schema::hasColumn('auction_items', 'sales_fee')) {
                $item->sales_fee = $salesFee;
            }

            if (Schema::hasColumn('auction_items', 'shipping_fee')) {
                $item->shipping_fee = $shippingFee;
            }

            if (Schema::hasColumn('auction_items', 'profit')) {
                $item->profit = $profit;
            }

            if (Schema::hasColumn('auction_items', 'status')) {
                $item->status = 'sold';
            }

            if (Schema::hasColumn('auction_items', 'platform')) {
                $item->platform = $platform;
            }

            if (Schema::hasColumn('auction_items', 'sold_at')) {
                $item->sold_at = $targetMonth->copy()->day(rand(1, 25))->format('Y-m-d');
            }

            $item->save();

            $createdCount++;
        }
    }

    $this->info($createdCount.'件のダミー売上データを作成しました。');
})->purpose('furugi用の月別売上確認ダミーデータを作成します');

Artisan::command('furugi:delete-dummy-sales', function () {
    $deletedCount = AuctionItem::query()
        ->where('management_id', 'like', 'DUMMY-%')
        ->delete();

    $this->info($deletedCount.'件のダミー売上データを削除しました。');
})->purpose('furugi用の月別売上確認ダミーデータを削除します');

Artisan::command('furugi:recalculate-sales', function () {
    $updatedCount = 0;

    AuctionItem::query()
        ->where('status', 'sold')
        ->chunkById(100, function ($items) use (&$updatedCount) {
            foreach ($items as $item) {
                $platform = $item->platform ?: 'その他';
                $soldPrice = (int) ($item->sold_price ?? 0);
                $purchasePrice = (int) ($item->purchase_price ?? 0);
                $salesFeeRate = (float) ($item->sales_fee_rate ?: defaultFurugiSalesFeeRate($platform));
                $salesFee = (int) round($soldPrice * ($salesFeeRate / 100));
                $shippingFee = (int) ($item->shipping_fee ?? 0);
                $profit = $soldPrice - $purchasePrice - $salesFee - $shippingFee;

                if (Schema::hasColumn('auction_items', 'sales_fee_rate')) {
                    $item->sales_fee_rate = $salesFeeRate;
                }

                if (Schema::hasColumn('auction_items', 'sales_fee')) {
                    $item->sales_fee = $salesFee;
                }

                if (Schema::hasColumn('auction_items', 'profit')) {
                    $item->profit = $profit;
                }

                $item->save();

                $updatedCount++;
            }
        });

    $this->info($updatedCount.'件のSOLD商品の手数料・実利益を再計算しました。');
})->purpose('既存SOLD商品の販売手数料・実利益を再計算します');

if (! function_exists('defaultFurugiSalesFeeRate')) {
    function defaultFurugiSalesFeeRate(string $platform): float
    {
        return match ($platform) {
            'ヤフオク' => 10.0,
            'メルカリ' => 10.0,
            'ラクマ' => 10.0,
            'Yahooフリマ' => 5.0,
            'PayPayフリマ' => 5.0,
            default => 0.0,
        };
    }
}
