<?php

namespace Tests\Feature;

use App\Http\Controllers\FurimaDeckAccountingExportController;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

class FurimaDeckAccountingExportCleanupTest extends TestCase
{
    public function test_generated_csv_inside_system_temporary_directory_is_deleted(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'furimadeck-accounting-');
        $this->assertNotFalse($path);

        try {
            $this->assertNotFalse(file_put_contents($path, 'generated test csv'));
            $this->invokeCleanup($path);

            $this->assertFileDoesNotExist($path);
        } finally {
            File::delete($path);
        }
    }

    public function test_unrelated_temporary_file_is_not_deleted(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'unrelated-');
        $this->assertNotFalse($path);

        try {
            $this->assertNotFalse(file_put_contents($path, 'unrelated test file'));
            $this->invokeCleanup($path);

            $this->assertFileExists($path);
        } finally {
            File::delete($path);
        }
    }

    public function test_matching_name_outside_system_temporary_directory_is_not_deleted(): void
    {
        $path = tempnam(base_path(), 'furimadeck-accounting-outside-');
        $this->assertNotFalse($path);
        $this->assertNotSame(realpath(sys_get_temp_dir()), realpath(dirname($path)));

        try {
            $this->assertNotFalse(file_put_contents($path, 'outside test file'));
            $this->invokeCleanup($path);

            $this->assertFileExists($path);
        } finally {
            File::delete($path);
        }
    }

    private function invokeCleanup(string $path): void
    {
        $cleanup = new ReflectionMethod(FurimaDeckAccountingExportController::class, 'cleanupTemporaryCsv');
        $cleanup->invoke(new FurimaDeckAccountingExportController, $path);
    }
}
