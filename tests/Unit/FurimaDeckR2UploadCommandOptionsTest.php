<?php

namespace Tests\Unit;

use App\Services\FurimaDeckR2UploadCommandOptions;
use Aws\Command;
use Aws\Result;
use Aws\S3\S3ClientInterface;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\AwsS3V3\PortableVisibilityConverter;
use League\Flysystem\Config;
use League\Flysystem\Visibility;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class FurimaDeckR2UploadCommandOptionsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_upload_callback_removes_only_the_acl_parameter(): void
    {
        $command = new Command('PutObject', [
            'ACL' => 'private',
            'Bucket' => 'test-bucket',
            'Key' => 'products/test.webp',
        ]);

        FurimaDeckR2UploadCommandOptions::removeUnsupportedAcl($command);

        $this->assertArrayNotHasKey('ACL', $command->toArray());
        $this->assertSame('test-bucket', $command['Bucket']);
        $this->assertSame('products/test.webp', $command['Key']);
    }

    public function test_flysystem_passes_acl_removal_callback_to_aws_sdk_before_upload(): void
    {
        $client = Mockery::mock(S3ClientInterface::class);
        $client->shouldReceive('upload')
            ->once()
            ->withArgs(static function (string $bucket, string $key, string $contents, string $acl, array $options): bool {
                if ($bucket !== 'test-bucket'
                    || $key !== 'products/test.webp'
                    || $contents !== 'test image'
                    || $acl !== 'private'
                    || ($options['mup_threshold'] ?? null) !== PHP_INT_MAX
                    || ($options['before_upload'] ?? null) !== [FurimaDeckR2UploadCommandOptions::class, 'removeUnsupportedAcl']) {
                    return false;
                }

                $command = new Command('PutObject', ['ACL' => $acl]);
                $options['before_upload']($command);

                return ! array_key_exists('ACL', $command->toArray());
            })
            ->andReturn(new Result([]));

        $adapter = new AwsS3V3Adapter(
            $client,
            'test-bucket',
            '',
            new PortableVisibilityConverter(Visibility::PRIVATE),
            null,
            [
                'before_upload' => [FurimaDeckR2UploadCommandOptions::class, 'removeUnsupportedAcl'],
                'mup_threshold' => PHP_INT_MAX,
            ],
        );

        $adapter->write(
            'products/test.webp',
            'test image',
            new Config(['visibility' => Visibility::PRIVATE]),
        );
    }
}
