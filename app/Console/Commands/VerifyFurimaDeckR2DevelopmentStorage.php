<?php

namespace App\Console\Commands;

use Aws\Exception\AwsException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class VerifyFurimaDeckR2DevelopmentStorage extends Command
{
    private const DISK = 'furimadeck_r2_dev';

    private const DEVELOPMENT_BUCKET = 'furimadeck-dev-images';

    private const PRODUCTION_DISK = 'furimadeck_r2';

    protected $signature = 'furimadeck:verify-r2-dev';

    protected $description = 'Verify Put/Get/Delete against the development-only R2 disk using a temporary object';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('この検証コマンドは本番環境では実行できません。');

            return self::FAILURE;
        }

        $config = config('filesystems.disks.'.self::DISK, []);
        $productionConfig = config('filesystems.disks.'.self::PRODUCTION_DISK, []);
        if (
            ($config['bucket'] ?? null) !== self::DEVELOPMENT_BUCKET
            || ($productionConfig['bucket'] ?? null) === self::DEVELOPMENT_BUCKET
            || $this->sharesProductionCredential($config, $productionConfig, 'key')
            || $this->sharesProductionCredential($config, $productionConfig, 'secret')
        ) {
            $this->error('開発用ディスクが開発バケットまたは開発専用認証情報を指していません。R2へ接続せず停止しました。');

            return self::FAILURE;
        }

        foreach (['key', 'secret', 'bucket', 'endpoint'] as $required) {
            if (! is_string($config[$required] ?? null) || trim($config[$required]) === '') {
                $this->error('開発用R2の設定が不足しています。秘密値は表示しません。');

                return self::FAILURE;
            }
        }

        $key = 'furimadeck-r2-verification/'.Str::uuid().'.txt';
        $contents = 'FurimaDeck development R2 verification '.Str::uuid();
        $disk = null;
        $failure = null;
        $cleanupFailure = null;
        $operation = 'Initialize';

        try {
            $disk = Storage::disk(self::DISK);
            $operation = 'Put';
            if (! $disk->put($key, $contents)) {
                throw new RuntimeException('R2 did not confirm the upload.');
            }

            $operation = 'Get';
            if (! $disk->exists($key) || $disk->get($key) !== $contents) {
                throw new RuntimeException('Uploaded object did not match the expected content.');
            }
        } catch (Throwable $exception) {
            $failure = $operation.' failed: '.$this->safeErrorSummary($exception);
        } finally {
            if ($disk !== null) {
                try {
                    if ($disk->exists($key)) {
                        if (! $disk->delete($key) || $disk->exists($key)) {
                            throw new RuntimeException('Temporary object could not be removed.');
                        }
                    }
                } catch (Throwable $exception) {
                    $cleanupFailure = 'Cleanup failed: '.$this->safeErrorSummary($exception);
                }
            }
        }

        if ($failure !== null || $cleanupFailure !== null) {
            $this->error(implode(' ', array_filter([$failure, $cleanupFailure])));

            return self::FAILURE;
        }

        $this->info('開発用R2のPut/Get/Deleteと一時オブジェクト削除を確認しました。');

        return self::SUCCESS;
    }

    private function sharesProductionCredential(array $developmentConfig, array $productionConfig, string $key): bool
    {
        $developmentValue = $developmentConfig[$key] ?? null;
        $productionValue = $productionConfig[$key] ?? null;

        return is_string($developmentValue)
            && $developmentValue !== ''
            && is_string($productionValue)
            && $productionValue !== ''
            && hash_equals($developmentValue, $productionValue);
    }

    private function safeErrorSummary(Throwable $exception): string
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof AwsException) {
                $code = $current->getAwsErrorCode() ?? 'R2 request error';
                $status = $current->getStatusCode();

                return $status === null ? $code : $code.' (HTTP '.$status.')';
            }
        }

        return class_basename($exception);
    }
}
