<?php

namespace Tests\Feature;

use Tests\TestCase;

class TestDatabaseIsolationTest extends TestCase
{
    public function test_phpunit_defaults_to_isolated_sqlite_connections(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame('sqlite', config('database.connections.sqlite.driver'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame('sqlite', config('database.connections.furimadeck.driver'));
        $this->assertSame(':memory:', config('database.connections.furimadeck.database'));
    }
}
