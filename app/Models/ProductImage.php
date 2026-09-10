<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    public const LEGACY_STORAGE_DISK = 'public';

    public const PRIVATE_STORAGE_DISK = 'local';

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
        return $this->storage_disk === self::PRIVATE_STORAGE_DISK
            ? self::PRIVATE_STORAGE_DISK
            : self::LEGACY_STORAGE_DISK;
    }
}
