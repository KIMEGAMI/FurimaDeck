<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private const TEST_STORAGE_PATH = 'storage/framework/testing/application';

    protected function setUp(): void
    {
        parent::setUp();

        app()->useStoragePath(base_path(self::TEST_STORAGE_PATH));

        // Tests for the legacy application must never inherit cutover flags
        // from a developer's local .env file. FurimaDeck-specific test cases
        // explicitly enable these flags in their own setup.
        config()->set('furimadeck.cutover_enabled', false);
        config()->set('furimadeck.product_management_enabled', false);
    }
}
