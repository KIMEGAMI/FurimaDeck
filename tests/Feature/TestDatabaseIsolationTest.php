<?php

namespace Tests\Feature;

use Tests\TestCase;

class TestDatabaseIsolationTest extends TestCase
{
    public function test_phpunit_defaults_to_isolated_sqlite_connections(): void
    {
        $this->assertSame('sqlite', getenv('DB_CONNECTION'));
        $this->assertSame(':memory:', getenv('DB_DATABASE'));
        $this->assertSame('', getenv('DB_URL'));
        $this->assertSame('sqlite', getenv('FURIMADECK_DB_DRIVER'));
        $this->assertSame(':memory:', getenv('FURIMADECK_DB_DATABASE'));
        $this->assertSame('', getenv('FURIMADECK_DB_URL'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame('sqlite', config('database.connections.sqlite.driver'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame('sqlite', config('database.connections.furimadeck.driver'));
        $this->assertSame(':memory:', config('database.connections.furimadeck.database'));
        $this->assertEmpty(config('database.connections.furimadeck.url'));
    }
}
