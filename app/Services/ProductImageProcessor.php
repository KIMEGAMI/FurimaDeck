<?php

namespace App\Services;

use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProductImageProcessor
{
    /**
     * @return array{original_path: string, derived_path: string, mime_type: string, file_size: int}
     */
    public function process(UploadedFile $file, int $userId): array
    {
        $disk = ProductImage::privateStorageDisk();

        if (! function_exists('imagewebp')) {
            throw new RuntimeException('画像処理機能が利用できません。しばらくしてからもう一度お試しください。');
        }

        $this->ensureSourcePixelLimit($file);

        $contents = file_get_contents($file->getRealPath());
        $source = $contents === false ? false : @imagecreatefromstring($contents);
        unset($contents);

        if ($source === false) {
            throw new RuntimeException('画像を処理できませんでした。別の画像をお試しください。');
        }

        $source = $this->applyOrientation($source, $file);
        $directory = 'products/'.$userId;
        $identifier = (string) Str::uuid();
        $mainPath = $directory.'/'.$identifier.'.webp';
        $thumbnailPath = $directory.'/'.$identifier.'.thumb.webp';

        $main = null;
        $thumbnail = null;
        $mainFileSize = 0;
        try {
            $main = $this->resize($source, (int) config('furimadeck.product_images.max_dimension'));
            imagedestroy($source);
            $source = null;

            $thumbnail = $this->resize($main, (int) config('furimadeck.product_images.thumbnail_dimension'));
            $mainWritten = $this->storeWebp($main, $mainPath, $disk);
            imagedestroy($main);
            $main = null;

            $thumbnailWritten = $mainWritten && $this->storeWebp($thumbnail, $thumbnailPath, $disk);
            if ($mainWritten && $thumbnailWritten) {
                $mainFileSize = Storage::disk($disk)->size($mainPath);
            }
        } catch (Throwable $exception) {
            $this->deleteAfterFailure($disk, [$mainPath, $thumbnailPath]);

            throw $exception;
        } finally {
            if ($source instanceof \GdImage) {
                imagedestroy($source);
            }
            if ($main instanceof \GdImage) {
                imagedestroy($main);
            }
            if ($thumbnail instanceof \GdImage) {
                imagedestroy($thumbnail);
            }
        }

        if (! $mainWritten || ! $thumbnailWritten) {
            $this->deleteAfterFailure($disk, [$mainPath, $thumbnailPath]);

            throw new RuntimeException('画像の保存に失敗しました。しばらくしてからもう一度お試しください。');
        }

        return [
            'original_path' => $mainPath,
            'derived_path' => $thumbnailPath,
            'mime_type' => 'image/webp',
            'file_size' => $mainFileSize,
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
        if ($target === false) {
            throw new RuntimeException('画像の縮小に必要なメモリを確保できませんでした。小さい画像をお試しください。');
        }

        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        if ($transparent === false || ! imagefill($target, 0, 0, $transparent)
            || ! imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
            imagedestroy($target);

            throw new RuntimeException('画像を縮小できませんでした。別の画像をお試しください。');
        }

        return $target;
    }

    private function storeWebp(\GdImage $image, string $path, string $disk): bool
    {
        $stream = tmpfile();
        if ($stream === false) {
            return false;
        }

        try {
            $encoded = imagewebp($image, $stream, (int) config('furimadeck.product_images.webp_quality'));
            $stat = fstat($stream);
            if (! $encoded || ! is_array($stat) || (int) ($stat['size'] ?? 0) < 1 || ! rewind($stream)) {
                return false;
            }

            return Storage::disk($disk)->put($path, $stream);
        } finally {
            fclose($stream);
        }
    }

    /** @param array<int, string> $paths */
    private function deleteAfterFailure(string $disk, array $paths): void
    {
        try {
            Storage::disk($disk)->delete($paths);
        } catch (Throwable) {
            // Preserve the original upload error; the source upload is never deleted here.
        }
    }
}
