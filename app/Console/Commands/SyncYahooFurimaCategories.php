<?php

namespace App\Console\Commands;

use App\Services\YahooFurimaCategorySynchronizer;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

class SyncYahooFurimaCategories extends Command
{
    protected $signature = 'furimadeck:sync-yahoo-categories
        {--request-interval=1000 : Yahoo!フリマへのリクエスト間隔（ミリ秒）}';

    protected $description = 'Yahoo!フリマの公開カテゴリ体系を同期します。既存商品は削除しません。';

    public function handle(): int
    {
        try {
            $requestInterval = filter_var($this->option('request-interval'), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 500, 'max_range' => 10000],
            ]);
            if ($requestInterval === false) {
                $this->error('リクエスト間隔は500〜10000ミリ秒の整数で指定してください。');

                return self::INVALID;
            }

            $synchronizer = new YahooFurimaCategorySynchronizer($requestInterval);
            $result = $synchronizer->synchronize();
        } catch (ConnectionException) {
            $this->error('Yahoo!フリマの公開カテゴリを取得できませんでした。カテゴリは変更していません。');

            return self::FAILURE;
        } catch (RequestException $exception) {
            $status = $exception->response?->status() ?? '不明';
            $this->error("Yahoo!フリマの公開カテゴリ取得がHTTP {$status}で失敗しました。カテゴリは変更していません。");

            return self::FAILURE;
        }

        $this->info("Yahoo!フリマカテゴリを同期しました: 全{$result['total']}件、新規{$result['created']}件、更新{$result['updated']}件、未使用旧カテゴリを非表示{$result['deactivated']}件。");

        return self::SUCCESS;
    }
}
