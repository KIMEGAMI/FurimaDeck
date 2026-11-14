<?php

namespace Database\Seeders;

use App\Services\RakumaCategorySynchronizer;
use Illuminate\Database\Seeder;
use RuntimeException;

class FurimaDeckCategorySeeder extends Seeder
{
    private const SNAPSHOT_PATH = __DIR__.'/data/rakuma-categories-2026-11-05.json';

    public function run(RakumaCategorySynchronizer $synchronizer): void
    {
        $contents = file_get_contents(self::SNAPSHOT_PATH);
        if ($contents === false) {
            throw new RuntimeException('FurimaDeckのカテゴリスナップショットを読み込めませんでした。');
        }

        $snapshot = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($snapshot)
            || ! is_int($snapshot['total'] ?? null)
            || $snapshot['total'] < 1
            || ! is_array($snapshot['categories'] ?? null)) {
            throw new RuntimeException('FurimaDeckのカテゴリスナップショットの形式が不正です。');
        }

        $result = $synchronizer->synchronizeTree($snapshot['categories']);
        if ($result['total'] !== $snapshot['total']) {
            throw new RuntimeException('FurimaDeckのカテゴリスナップショット件数が一致しません。');
        }

        $this->command?->info("FurimaDeckカテゴリを登録しました: 全{$result['total']}件、新規{$result['created']}件、更新{$result['updated']}件。");
    }
}
