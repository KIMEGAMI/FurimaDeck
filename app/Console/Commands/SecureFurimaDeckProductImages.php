<?php

namespace App\Console\Commands;

use App\Models\ProductImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SecureFurimaDeckProductImages extends Command
{
    private const BATCH_SIZE = 100;

    protected $signature = 'furimadeck:secure-product-images {--apply : Copy verified legacy public images and update their storage marker}';

    protected $description = 'Preview or copy legacy FurimaDeck product images to private storage without deleting public files';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $eligible = 0;
        $copied = 0;
        $failed = 0;

        ProductImage::query()
            ->whereNull('storage_disk')
            ->with('product:id,user_id')
            ->lazyById(self::BATCH_SIZE)
            ->each(function (ProductImage $image) use ($apply, &$eligible, &$copied, &$failed): void {
                try {
                    $paths = $this->imagePaths($image);
                    $eligible++;

                    if (! $apply) {
                        return;
                    }

                    foreach ($paths as $path) {
                        $this->copyIfNeeded($path);
                    }

                    $image->forceFill(['storage_disk' => ProductImage::PRIVATE_STORAGE_DISK])->save();
                    $copied++;
                } catch (Throwable) {
                    $failed++;
                    $this->error('画像ID '.$image->getKey().' を移行できませんでした。公開元は変更していません。');
                }
            });

        $this->info(sprintf(
            '%s: 対象 %d件、private保存済み %d件、失敗 %d件。公開ディスクのファイルは削除していません。',
            $apply ? '適用結果' : 'Dry-run',
            $eligible,
            $copied,
            $failed,
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<int, string> */
    private function imagePaths(ProductImage $image): array
    {
        $userId = $image->product?->user_id;
        $paths = array_filter([$image->original_path, $image->derived_path], 'is_string');

        if (! is_int($userId) || $paths === []) {
            throw new RuntimeException('画像の所有者または保存先が不正です。');
        }

        foreach ($paths as $path) {
            if (! str_starts_with($path, 'products/'.$userId.'/') || str_contains($path, '..')) {
                throw new RuntimeException('画像の保存先が不正です。');
            }
        }

        return array_values(array_unique($paths));
    }

    private function copyIfNeeded(string $path): void
    {
        $source = Storage::disk(ProductImage::LEGACY_STORAGE_DISK);
        $target = Storage::disk(ProductImage::PRIVATE_STORAGE_DISK);

        if (! $source->exists($path)) {
            throw new RuntimeException('公開ディスクに画像がありません。');
        }

        if ($target->exists($path)) {
            if ($source->size($path) !== $target->size($path)) {
                throw new RuntimeException('private画像のサイズが公開元と一致しません。');
            }

            return;
        }

        $stream = $source->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException('公開画像を読み取れません。');
        }

        try {
            if (! $target->writeStream($path, $stream)) {
                throw new RuntimeException('private画像を書き込めません。');
            }
        } finally {
            fclose($stream);
        }
    }
}
