<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VerifyFurimaDeckR2DevelopmentStorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('furimadeck_r2_dev');
        config()->set('filesystems.disks.furimadeck_r2_dev.key', 'test-access-key');
        config()->set('filesystems.disks.furimadeck_r2_dev.secret', 'test-secret-key');
        config()->set('filesystems.disks.furimadeck_r2_dev.bucket', 'furimadeck-dev-images');
        config()->set('filesystems.disks.furimadeck_r2_dev.endpoint', 'https://example.test');
        config()->set('filesystems.disks.furimadeck_r2.key', 'test-production-access-key');
        config()->set('filesystems.disks.furimadeck_r2.secret', 'test-production-secret-key');
        config()->set('filesystems.disks.furimadeck_r2.bucket', 'furimadeck-prod-images');
    }

    public function test_development_r2_verification_puts_reads_and_deletes_a_temporary_object(): void
    {
        $this->artisan('furimadeck:verify-r2-dev')
            ->expectsOutput('開発用R2のPut/Get/Deleteと一時オブジェクト削除を確認しました。')
            ->assertSuccessful();

        $this->assertSame([], Storage::disk('furimadeck_r2_dev')->allFiles('furimadeck-r2-verification'));
    }

    public function test_development_r2_verification_safely_reports_disk_initialization_failure(): void
    {
        Storage::shouldReceive('disk')
            ->once()
            ->with('furimadeck_r2_dev')
            ->andThrow(new \InvalidArgumentException('Missing SDK manifest path must not be displayed.'));

        $this->artisan('furimadeck:verify-r2-dev')
            ->expectsOutput('Initialize failed: InvalidArgumentException')
            ->assertFailed();
    }

    public function test_development_r2_verification_refuses_production_environment(): void
    {
        app()->detectEnvironment(fn (): string => 'production');

        $this->artisan('furimadeck:verify-r2-dev')
            ->expectsOutput('この検証コマンドは本番環境では実行できません。')
            ->assertFailed();
    }

    public function test_development_r2_verification_refuses_a_production_bucket_before_network_access(): void
    {
        config()->set('filesystems.disks.furimadeck_r2_dev.bucket', 'furimadeck-prod-images');

        $this->artisan('furimadeck:verify-r2-dev')
            ->expectsOutput('開発用ディスクが開発バケットまたは開発専用認証情報を指していません。R2へ接続せず停止しました。')
            ->assertFailed();

        $this->assertSame([], Storage::disk('furimadeck_r2_dev')->allFiles('furimadeck-r2-verification'));
    }

    public function test_development_r2_verification_refuses_credentials_shared_with_production(): void
    {
        config()->set('filesystems.disks.furimadeck_r2_dev.secret', 'test-production-secret-key');

        $this->artisan('furimadeck:verify-r2-dev')
            ->expectsOutput('開発用ディスクが開発バケットまたは開発専用認証情報を指していません。R2へ接続せず停止しました。')
            ->assertFailed();

        $this->assertSame([], Storage::disk('furimadeck_r2_dev')->allFiles('furimadeck-r2-verification'));
    }
}
