<?php

namespace App\Services;

use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ProductImageProcessor
{
    /**
     * @return array{original_path: string, derived_path: string, mime_type: string, file_size: int}
     */
    public function process(UploadedFile $file, int $userId): array
    {
        if (! function_exists('imagewebp')) {
            throw new RuntimeException('画像処理機能が利用できません。しばらくしてからもう一度お試しください。');
        }

        $this->ensureSourcePixelLimit($file);

        $contents = file_get_contents($file->getRealPath());
        $source = $contents === false ? false : @imagecreatefromstring($contents);

        if ($source === false) {
            throw new RuntimeException('画像を処理できませんでした。別の画像をお試しください。');
        }

        $source = $this->applyOrientation($source, $file);
        $main = $this->resize($source, (int) config('furimadeck.product_images.max_dimension'));
        $thumbnail = $this->resize($source, (int) config('furimadeck.product_images.thumbnail_dimension'));
        imagedestroy($source);

        $directory = 'products/'.$userId;
        $identifier = (string) Str::uuid();
        $mainPath = $directory.'/'.$identifier.'.webp';
        $thumbnailPath = $directory.'/'.$identifier.'.thumb.webp';
        Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->makeDirectory('products');
        Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->makeDirectory($directory);

        try {
            $mainWritten = Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->put($mainPath, $this->encodeWebp($main));
            $thumbnailWritten = Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->put($thumbnailPath, $this->encodeWebp($thumbnail));
        } finally {
            imagedestroy($main);
            imagedestroy($thumbnail);
        }

        if (! $mainWritten || ! $thumbnailWritten) {
            Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->delete([$mainPath, $thumbnailPath]);

            throw new RuntimeException('画像の保存に失敗しました。しばらくしてからもう一度お試しください。');
        }

        return [
            'original_path' => $mainPath,
            'derived_path' => $thumbnailPath,
            'mime_type' => 'image/webp',
            'file_size' => Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->size($mainPath),
        ];
    }

    private function ensureSourcePixelLimit(UploadedFile $file): void
    {
        $imageSize = @getimagesize($file->getRealPath());
        $width = is_array($imageSize) ? (int) ($imageSize[0] ?? 0) : 0;
        $height = is_array($imageSize) ? (int) ($imageSize[1] ?? 0) : 0;
        $maxSourcePixels = (int) config('furimadeck.product_images.max_source_pixels');

        if (
            $maxSourcePixels < 1
            || $width < 1
            || $height < 1
            || $width > intdiv(PHP_INT_MAX, $height)
            || ($width * $height) > $maxSourcePixels
        ) {
            throw new RuntimeException('画像の画素数が上限を超えています。小さい画像をお試しください。');
        }
    }

    private function applyOrientation(\GdImage $image, UploadedFile $file): \GdImage
    {
        if (! function_exists('exif_read_data') || ! in_array($file->getMimeType(), ['image/jpeg', 'image/jpg'], true)) {
            return $image;
        }

        $metadata = @exif_read_data($file->getRealPath());
        $orientation = is_array($metadata) ? (int) ($metadata['Orientation'] ?? 1) : 1;
        $degrees = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($degrees === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $degrees, 0);
        if ($rotated === false) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    private function resize(\GdImage $source, int $maxDimension): \GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $maxDimension = max(1, $maxDimension);
        $ratio = min(1, $maxDimension / max($width, $height));
        $targetWidth = max(1, (int) round($width * $ratio));
        $targetHeight = max(1, (int) round($height * $ratio));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);

        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $target;
    }

    private function encodeWebp(\GdImage $image): string
    {
        ob_start();
        $encoded = imagewebp($image, null, (int) config('furimadeck.product_images.webp_quality'));
        $contents = ob_get_clean();

        if (! $encoded || $contents === false) {
            throw new RuntimeException('画像をWebP形式に変換できませんでした。別の画像をお試しください。');
        }

        return $contents;
    }
}
