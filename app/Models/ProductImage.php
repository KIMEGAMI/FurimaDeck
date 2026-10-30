<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class ProductImage extends Model
{
    public const LEGACY_STORAGE_DISK = 'public';

    public const PRIVATE_STORAGE_DISK = 'local';

    public const DEVELOPMENT_R2_DISK = 'furimadeck_r2_dev';

    public const PRODUCTION_R2_DISK = 'furimadeck_r2';

    protected $fillable = [
        'original_path',
        'derived_path',
        'storage_disk',
        'mime_type',
        'file_size',
        'position',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'position' => 'integer',
            'is_primary' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function storageDisk(): string
    {
        if ($this->storage_disk === null || $this->storage_disk === self::LEGACY_STORAGE_DISK) {
            return self::LEGACY_STORAGE_DISK;
        }

        $disk = (string) $this->storage_disk;
        $allowedDisks = config('furimadeck.product_images.allowed_disks', [self::PRIVATE_STORAGE_DISK]);
        $configuredDisks = config('filesystems.disks', []);

        if (
            ! in_array($disk, $allowedDisks, true)
            || ! array_key_exists($disk, $configuredDisks)
            || ! self::isStorageDiskAllowedInCurrentEnvironment($disk)
        ) {
            throw new InvalidArgumentException('商品画像の保存先設定が無効です。');
        }

        return $disk;
    }

    public static function privateStorageDisk(): string
    {
        $disk = config('furimadeck.product_images.disk', self::PRIVATE_STORAGE_DISK);
        $allowedDisks = config('furimadeck.product_images.allowed_disks', [self::PRIVATE_STORAGE_DISK]);
        $configuredDisks = config('filesystems.disks', []);

        if (
            ! is_string($disk)
            || ! in_array($disk, $allowedDisks, true)
            || ! array_key_exists($disk, $configuredDisks)
            || ! self::isPrivateStorageDiskAllowedInCurrentEnvironment($disk)
        ) {
            throw new InvalidArgumentException('商品画像の保存先設定が無効です。');
        }

        return $disk;
    }

    public static function isStorageDiskAllowedInCurrentEnvironment(string $disk): bool
    {
        $environment = (string) config('app.env', 'local');
        if ($environment === 'testing') {
            return true;
        }

        return $environment === 'production'
            ? $disk !== self::DEVELOPMENT_R2_DISK
            : $disk !== self::PRODUCTION_R2_DISK;
    }

    public static function isPrivateStorageDiskAllowedInCurrentEnvironment(string $disk): bool
    {
        $environment = (string) config('app.env', 'local');
        if ($environment === 'testing') {
            return true;
        }

        return $environment === 'production'
            ? $disk === self::PRODUCTION_R2_DISK
            : $disk !== self::PRODUCTION_R2_DISK;
    }
}
