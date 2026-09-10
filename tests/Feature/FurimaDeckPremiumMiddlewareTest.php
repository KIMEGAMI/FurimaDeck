<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FurimaDeckPremiumMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.connections.sqlite');
        $connection['database'] = ':memory:';
        config()->set('database.connections.furimadeck', $connection);
        DB::purge('furimadeck');
        DB::setDefaultConnection('furimadeck');
        $this->artisan('migrate', [
            '--database' => 'furimadeck',
            '--path' => 'database/migrations/furimadeck',
        ])->assertSuccessful();
        config()->set('furimadeck.cutover_enabled', true);
        config()->set('furimadeck.product_management_enabled', true);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');

        parent::tearDown();
    }

    public function test_free_user_is_redirected_from_csv_import(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(route('products.imports.create'))
            ->assertRedirect(route('furimadeck-billing.index'))
            ->assertSessionHas('error');
    }

    public function test_active_user_can_open_csv_import(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);

        $this->actingAs($user)->get(route('products.imports.create'))->assertOk();
    }

    public function test_free_user_cannot_register_a_product_over_the_limit(): void
    {
        config()->set('furimadeck.plans.free.product_limit', 1);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->products()->create([
            'internal_sku' => 'FREE-LIMIT-001',
            'product_name' => '登録済み商品',
            'condition' => 'used',
            'purchase_unit_cost' => 0,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);

        $this->actingAs($user)
            ->from(route('products.create'))
            ->post(route('products.store'), [
                'internal_sku' => 'FREE-LIMIT-002',
                'product_name' => '上限超過商品',
                'condition' => 'used',
                'purchase_unit_cost' => 0,
                'purchase_quantity' => 1,
                'quantity_available' => 1,
                'inventory_status' => 'in_stock',
            ])
            ->assertRedirect(route('products.create'))
            ->assertSessionHasErrors('product_limit');

        $this->assertSame(1, $user->products()->count());
    }
}
