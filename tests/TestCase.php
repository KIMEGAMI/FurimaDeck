<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    private const TEST_STORAGE_PATH = 'storage/framework/testing/application';

    private const MARIADB_GATE_DATABASE = 'furimadeck_csv_gate';

    protected function setUp(): void
    {
        $isMariaDbGateTest = $this instanceof Feature\FurimaDeckMariaDbCsvGateTest
            && getenv('FURIMADECK_MARIADB_GATE') === '1';

        if (! $isMariaDbGateTest) {
            $testDatabaseEnvironment = [
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => ':memory:',
                'DB_URL' => '',
                'FURIMADECK_DB_DRIVER' => 'sqlite',
                'FURIMADECK_DB_DATABASE' => ':memory:',
                'FURIMADECK_DB_URL' => '',
            ];

            foreach ($testDatabaseEnvironment as $name => $value) {
                putenv($name.'='.$value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }

        parent::setUp();

        Http::preventStrayRequests();

        $furimadeckConnection = config('database.connections.furimadeck');
        $mariaDbGatePort = (string) env('FURIMADECK_MARIADB_GATE_PORT', '3306');
        $isIsolatedMariaDbGate = $this instanceof Feature\FurimaDeckMariaDbCsvGateTest
            && env('FURIMADECK_MARIADB_GATE') === '1'
            && ($furimadeckConnection['driver'] ?? null) === 'mysql'
            && ($furimadeckConnection['host'] ?? null) === '127.0.0.1'
            && (string) ($furimadeckConnection['port'] ?? '') === $mariaDbGatePort
            && ($furimadeckConnection['database'] ?? null) === self::MARIADB_GATE_DATABASE;

        if (! $isIsolatedMariaDbGate) {
            $sqliteConnection = config('database.connections.sqlite');
            $sqliteConnection['database'] = ':memory:';
            $sqliteConnection['url'] = null;

            config()->set('database.default', 'sqlite');
            config()->set('database.connections.sqlite', $sqliteConnection);
            config()->set('database.connections.furimadeck', $sqliteConnection);
            DB::setDefaultConnection('sqlite');
        }

        app()->useStoragePath(base_path(self::TEST_STORAGE_PATH));

        // Tests for the legacy application must never inherit cutover flags
        // from a developer's local .env file. FurimaDeck-specific test cases
        // explicitly enable these flags in their own setup.
        config()->set('furimadeck.cutover_enabled', false);
        config()->set('furimadeck.product_management_enabled', false);
    }
}
