<?php

namespace App\Console\Commands;

use App\Services\ProductImageDeletionOutbox;
use Illuminate\Console\Command;

class ProcessProductImageDeletionOutbox extends Command
{
    protected $signature = 'furimadeck:process-product-image-deletions';

    protected $description = 'Retry pending private product image deletions without exposing object keys';

    public function handle(ProductImageDeletionOutbox $outbox): int
    {
        $result = $outbox->process();
        $this->info('商品画像削除の再試行: '.$result['attempted'].'件を確認、'.$result['pending'].'件が保留中です。');

        return $result['pending'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
