<?php

namespace App\Console\Commands;

use App\Models\ProductImage;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SecureFurimaDeckProductImages extends Command
{
    private const BATCH_SIZE = 100;

    protected $signature = 'furimadeck:secure-product-images {--source=public : Source disk (public for legacy rows, or an allowed private disk)} {--target= : Destination private disk (defaults to FURIMADECK_PRODUCT_IMAGE_DISK)} {--apply : Copy and verify images before updating their storage marker}';

    protected $description = 'Preview or copy FurimaDeck product images to the configured private disk without deleting source files';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $sourceDisk = (string) $this->option('source');
        $allowedSources = array_merge([ProductImage::LEGACY_STORAGE_DISK], config('furimadeck.product_images.allowed_disks', []));
        if (
            ! in_array($sourceDisk, $allowedSources, true)
            || ! array_key_exists($sourceDisk, config('filesystems.disks', []))
            || ! ProductImage::isStorageDiskAllowedInCurrentEnvironment($sourceDisk)
        ) {
            $this->error('画像のコピー元ディスクが許可されていません。');

            return self::FAILURE;
        }

        $targetOption = $this->option('target');
        $targetDisk = $targetOption === null ? ProductImage::privateStorageDisk() : (string) $targetOption;
        if (! in_array($targetDisk, config('furimadeck.product_images.allowed_disks', []), true)
            || ! array_key_exists($targetDisk, config('filesystems.disks', []))
            || ! ProductImage::isPrivateStorageDiskAllowedInCurrentEnvironment($targetDisk)) {
            $this->error('画像の保存先ディスクが許可されていません。');

            return self::FAILURE;
        }

        if ($sourceDisk === $targetDisk) {
            $this->error('コピー元と保存先が同じです。FURIMADECK_PRODUCT_IMAGE_DISKを確認してください。');

            return self::FAILURE;
        }

        $eligible = 0;
        $sourceBytes = 0;
        $copied = 0;
        $failed = 0;

        $query = ProductImage::query()
            ->when(
                $sourceDisk === ProductImage::LEGACY_STORAGE_DISK,
                fn ($images) => $images->where(fn ($legacy) => $legacy->whereNull('storage_disk')->orWhere('storage_disk', ProductImage::LEGACY_STORAGE_DISK)),
                fn ($images) => $images->where('storage_disk', $sourceDisk),
            );

        $query
            ->with('product:id,user_id')
            ->lazyById(self::BATCH_SIZE)
            ->each(function (ProductImage $image) use ($apply, $sourceDisk, $targetDisk, &$eligible, &$sourceBytes, &$copied, &$failed): void {
                try {
                    $paths = $this->imagePaths($image);
                    $imageBytes = $this->sourceBytes($sourceDisk, $paths);
                    if ($imageBytes > PHP_INT_MAX - $sourceBytes) {
                        throw new RuntimeException('コピー元の合計サイズを計算できません。');
                    }
                    $eligible++;
                    $sourceBytes += $imageBytes;

                    if (! $apply) {
                        return;
                    }

                    foreach ($paths as $path) {
                        $this->copyIfNeeded($path, $sourceDisk, $targetDisk);
                    }

                    $image->forceFill(['storage_disk' => $targetDisk])->save();
                    $copied++;
                } catch (Throwable) {
                    $failed++;
                    $this->error('画像ID '.$image->getKey().' を移行できませんでした。コピー元は変更していません。');
                }
            });

        $this->info(sprintf(
            '%s: 対象 %d件、コピー元容量 %d bytes、保存先を更新 %d件、失敗 %d件。コピー元ディスク %s のファイルは削除していません。',
            $apply ? '適用結果' : 'Dry-run',
            $eligible,
            $sourceBytes,
            $copied,
            $failed,
            $sourceDisk,
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

    /** @param array<int, string> $paths */
    private function sourceBytes(string $sourceDisk, array $paths): int
    {
        $source = Storage::disk($sourceDisk);
        $bytes = 0;

        foreach ($paths as $path) {
            if (! $source->exists($path)) {
                throw new RuntimeException('コピー元の画像が見つかりません。');
            }

            $size = $source->size($path);
            if ($size < 1 || $size > PHP_INT_MAX - $bytes) {
                throw new RuntimeException('コピー元の画像サイズを確認できません。');
            }

            $bytes += $size;
        }

        return $bytes;
    }

    private function copyIfNeeded(string $path, string $sourceDisk, string $targetDisk): void
    {
        $source = Storage::disk($sourceDisk);
        $target = Storage::disk($targetDisk);

        if (! $source->exists($path)) {
            throw new RuntimeException('コピー元ディスクに画像がありません。');
        }

        $sourceHash = $this->sha256($source, $path);
        if ($target->exists($path)) {
            if ($source->size($path) !== $target->size($path) || ! hash_equals($sourceHash, $this->sha256($target, $path))) {
                throw new RuntimeException('保存先画像の内容がコピー元と一致しません。');
            }

            return;
        }

        $stream = $source->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException('コピー元画像を読み取れません。');
        }

        try {
            if (! $target->writeStream($path, $stream)) {
                throw new RuntimeException('保存先画像を書き込めません。');
            }
        } finally {
            fclose($stream);
        }

        if (! $target->exists($path) || $source->size($path) !== $target->size($path) || ! hash_equals($sourceHash, $this->sha256($target, $path))) {
            throw new RuntimeException('保存先画像の内容を検証できません。コピー元とデータベースは変更していません。');
        }
    }

    private function sha256(Filesystem $disk, string $path): string
    {
        $stream = $disk->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException('画像の内容を読み取れません。');
        }

        try {
            $context = hash_init('sha256');
            if (hash_update_stream($context, $stream) === false) {
                throw new RuntimeException('画像の内容を検証できません。');
            }

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }
}
