<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.connections.sqlite');
        $connection['database'] = ':memory:';
        config()->set('database.connections.furimadeck', $connection);
        config()->set('furimadeck.cutover_enabled', true);
        config()->set('furimadeck.product_management_enabled', true);
        DB::purge('furimadeck');
        DB::setDefaultConnection('furimadeck');
        $this->artisan('migrate', [
            '--database' => 'furimadeck',
            '--path' => 'database/migrations/furimadeck',
        ])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');

        parent::tearDown();
    }

    public function test_supplier_types_are_displayed_in_japanese_without_changing_the_saved_value(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->suppliers()->create([
            'name' => 'テスト仕入先',
            'type' => 'online_shop',
        ]);

        $this->actingAs($user)
            ->get(route('suppliers.index'))
            ->assertOk()
            ->assertSee('ネットショップ')
            ->assertDontSee('>online_shop<', false)
            ->assertSee('value="online_shop"', false);

        $this->assertSame('ネットショップ', Supplier::query()->firstOrFail()->typeLabel());
    }
}
