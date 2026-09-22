<?php

namespace App\Console\Commands;

use App\Services\RakumaCategorySynchronizer;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

class SyncRakumaCategories extends Command
{
    protected $signature = 'furimadeck:sync-rakuma-categories';

    protected $description = 'Rakumaの公開カテゴリ体系を同期します。既存商品は削除しません。';

    public function handle(RakumaCategorySynchronizer $synchronizer): int
    {
        try {
            $result = $synchronizer->synchronize();
        } catch (ConnectionException) {
            $this->error('Rakumaの公開カテゴリを取得できませんでした。カテゴリは変更していません。');

            return self::FAILURE;
        } catch (RequestException $exception) {
            $status = $exception->response?->status() ?? '不明';
            $this->error("Rakumaの公開カテゴリ取得がHTTP {$status}で失敗しました。カテゴリは変更していません。");

            return self::FAILURE;
        } catch (\UnexpectedValueException|\JsonException) {
            $this->error('Rakumaの公開カテゴリの形式を確認できませんでした。カテゴリは変更していません。');

            return self::FAILURE;
        }

        $this->info("Rakumaカテゴリを同期しました: 全{$result['total']}件、新規{$result['created']}件、更新{$result['updated']}件、未使用旧カテゴリを非表示{$result['deactivated']}件。");

        return self::SUCCESS;
    }
}
