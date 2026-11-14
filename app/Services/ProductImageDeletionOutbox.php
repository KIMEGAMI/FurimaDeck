<?php

namespace App\Services;

use App\Models\ProductImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

class ProductImageDeletionOutbox
{
    private const TABLE = 'product_image_deletion_outbox';

    private const MAX_BATCH_SIZE = 100;

    public function enqueueImages(iterable $images, int $userId): array
    {
        $pathsByDisk = [];
        foreach ($images as $image) {
            $pathsByDisk[$image->storageDisk()][] = $image->original_path;
            if (is_string($image->derived_path) && $image->derived_path !== '') {
                $pathsByDisk[$image->storageDisk()][] = $image->derived_path;
            }
        }

        return $this->enqueuePathsByDisk($pathsByDisk, $userId);
    }

    public function enqueuePathsByDisk(array $pathsByDisk, int $userId): array
    {
        $ids = [];
        $configuredDisks = config('filesystems.disks', []);
        $allowedDisks = array_merge(
            [ProductImage::LEGACY_STORAGE_DISK],
            (array) config('furimadeck.product_images.allowed_disks', []),
        );

        foreach ($pathsByDisk as $disk => $paths) {
            if (! is_string($disk) || ! in_array($disk, $allowedDisks, true) || ! array_key_exists($disk, $configuredDisks)) {
                throw new InvalidArgumentException('商品画像の削除先設定が無効です。');
            }

            foreach (array_values(array_unique($paths)) as $path) {
                if (! $this->isSafeProductImagePath($path, $userId)) {
                    throw new InvalidArgumentException('商品画像の削除キーが無効です。');
                }

                $ids[] = (int) DB::table(self::TABLE)->insertGetId([
                    'disk' => $disk,
                    'object_key' => $path,
                    'attempts' => 0,
                    'last_error_class' => null,
                    'last_attempted_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return $ids;
    }

    public function process(?array $ids = null): array
    {
        $query = DB::table(self::TABLE)
            ->orderBy('attempts')
            ->orderBy('id')
            ->limit(self::MAX_BATCH_SIZE);
        if ($ids !== null) {
            if ($ids === []) {
                return ['attempted' => 0, 'deleted' => 0, 'pending' => 0];
            }
            $query->whereIn('id', $ids);
        }

        $items = $query->get(['id', 'disk', 'object_key']);
        $deleted = 0;
        $failed = 0;

        foreach ($items as $item) {
            try {
                $removed = Storage::disk((string) $item->disk)->delete([(string) $item->object_key]);
                if (! $removed) {
                    $this->retainForRetry((int) $item->id, 'FilesystemDeleteReturnedFalse');
                    $failed++;

                    continue;
                }
                DB::table(self::TABLE)->where('id', $item->id)->delete();
                $deleted++;
            } catch (Throwable $exception) {
                $this->retainForRetry((int) $item->id, $exception::class);
                $failed++;
            }
        }

        if ($failed > 0) {
            Log::warning('Product image deletion outbox has pending objects.', [
                'pending_count' => $failed,
            ]);
        }

        $pendingQuery = DB::table(self::TABLE);
        if ($ids !== null) {
            $pendingQuery->whereIn('id', $ids);
        }

        return [
            'attempted' => $items->count(),
            'deleted' => $deleted,
            'pending' => (int) $pendingQuery->count(),
        ];
    }

    private function retainForRetry(int $id, string $errorClass): void
    {
        DB::table(self::TABLE)->where('id', $id)->update([
            'attempts' => DB::raw('attempts + 1'),
            'last_error_class' => substr($errorClass, 0, 255),
            'last_attempted_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function isSafeProductImagePath(mixed $path, int $userId): bool
    {
        return is_string($path)
            && $path !== ''
            && ! str_contains($path, '..')
            && ! str_starts_with($path, '/')
            && ! str_starts_with($path, '\\')
            && str_starts_with($path, 'products/'.$userId.'/');
    }
}
