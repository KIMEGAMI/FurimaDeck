<?php

namespace Tests\Feature;

use App\Services\FurimaDeckR2UploadCommandOptions;
use Tests\TestCase;

class FurimaDeckR2FilesystemConfigTest extends TestCase
{
    public function test_development_and_production_r2_disks_use_private_s3_uploads_without_acl(): void
    {
        foreach (['furimadeck_r2_dev', 'furimadeck_r2'] as $disk) {
            $this->assertSame('s3', config("filesystems.disks.{$disk}.driver"));
            $this->assertSame('private', config("filesystems.disks.{$disk}.visibility"));
            $this->assertSame(
                [FurimaDeckR2UploadCommandOptions::class, 'removeUnsupportedAcl'],
                config("filesystems.disks.{$disk}.options.before_upload"),
            );
            $this->assertSame(PHP_INT_MAX, config("filesystems.disks.{$disk}.options.mup_threshold"));
        }
    }
}
