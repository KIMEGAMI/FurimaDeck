<?php

namespace Tests\Feature;

use App\Models\Marketplace;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class FurimaDeckAccountTest extends TestCase
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

    public function test_user_can_update_account_information(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->patch(route('furimadeck-account.update'), [
                'name' => '更新後の名前',
                'email' => 'updated@example.com',
            ])
            ->assertRedirect(route('furimadeck-account.edit'))
            ->assertSessionHas('status', 'profile-updated')
            ->assertSessionHas('verification_status', 'verification-link-sent');

        $user->refresh();
        $this->assertSame('更新後の名前', $user->name);
        $this->assertSame('updated@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user->fresh(), VerifyEmail::class);
    }

    public function test_user_cannot_update_account_to_another_users_email(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $otherUser = User::factory()->create();

        $this->actingAs($user)
            ->from(route('furimadeck-account.edit'))
            ->patch(route('furimadeck-account.update'), [
                'name' => $user->name,
                'email' => $otherUser->email,
            ])
            ->assertRedirect(route('furimadeck-account.edit'))
            ->assertSessionHasErrors('email');
    }

    public function test_user_can_delete_all_furimadeck_data_without_deleting_account_or_other_users_data(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $otherUser = User::factory()->create(['email_verified_at' => now()]);
        $userProduct = $user->products()->create([
            'internal_sku' => 'PURGE-001',
            'product_name' => '削除対象商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $otherProduct = $otherUser->products()->create([
            'internal_sku' => 'KEEP-001',
            'product_name' => '保持対象商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $user->suppliers()->create(['name' => '削除対象仕入先', 'type' => 'other']);
        app(AuditLogger::class)->log($user, 'product', $userProduct->id, 'product.created');
        app(AuditLogger::class)->log($otherUser, 'product', $otherProduct->id, 'product.created');

        $this->actingAs($user)
            ->get(route('furimadeck-account.data-delete.confirm'))
            ->assertOk()
            ->assertSee('本当に削除しますか？')
            ->assertSee('商品、商品画像、仕入先');

        $this->actingAs($user)
            ->from(route('furimadeck-account.data-delete.confirm'))
            ->delete(route('furimadeck-account.data-delete'), ['password' => 'password'])
            ->assertRedirect(route('furimadeck-account.data-delete.confirm'))
            ->assertSessionHasErrorsIn('dataDeletion', 'confirm_data_deletion');

        $this->assertDatabaseHas('products', ['id' => $userProduct->id], 'furimadeck');

        $this->actingAs($user)
            ->delete(route('furimadeck-account.data-delete'), [
                'password' => 'password',
                'confirm_data_deletion' => '1',
            ])
            ->assertRedirect(route('furimadeck-account.edit'))
            ->assertSessionHas('status', 'data-deleted');

        $this->assertDatabaseHas('users', ['id' => $user->id], 'furimadeck');
        $this->assertDatabaseMissing('products', ['id' => $userProduct->id], 'furimadeck');
        $this->assertDatabaseMissing('suppliers', ['user_id' => $user->id], 'furimadeck');
        $this->assertDatabaseMissing('audit_logs', ['user_id' => $user->id], 'furimadeck');
        $this->assertDatabaseHas('products', ['id' => $otherProduct->id], 'furimadeck');
        $this->assertDatabaseHas('audit_logs', ['user_id' => $otherUser->id], 'furimadeck');
    }

    public function test_data_deletion_requires_the_current_password(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $product = $user->products()->create([
            'internal_sku' => 'PURGE-002',
            'product_name' => '削除対象商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);

        $this->actingAs($user)
            ->from(route('furimadeck-account.data-delete.confirm'))
            ->delete(route('furimadeck-account.data-delete'), [
                'password' => 'wrong-password',
                'confirm_data_deletion' => '1',
            ])
            ->assertRedirect(route('furimadeck-account.data-delete.confirm'))
            ->assertSessionHasErrorsIn('dataDeletion', 'password');

        $this->assertDatabaseHas('products', ['id' => $product->id], 'furimadeck');
    }

    public function test_user_can_delete_sales_data_for_one_month_only_with_confirmation(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $otherUser = User::factory()->create(['email_verified_at' => now()]);
        $marketplace = Marketplace::query()->create([
            'code' => 'monthly-delete-test',
            'name' => '月次削除テスト',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);
        $product = $user->products()->create([
            'internal_sku' => 'MONTH-001',
            'product_name' => '月次削除対象商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 0,
            'inventory_status' => 'out_of_stock',
        ]);
        $inside = $user->sales()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => 1,
            'sold_price' => 5000,
            'sold_at' => '2026-09-10 12:00:00',
            'status' => 'completed',
            'cost_basis' => 1000,
            'net_profit' => 4000,
        ]);
        $outside = $user->sales()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => 1,
            'sold_price' => 6000,
            'sold_at' => '2026-10-10 12:00:00',
            'status' => 'completed',
            'cost_basis' => 1000,
            'net_profit' => 5000,
        ]);
        $otherProduct = $otherUser->products()->create([
            'internal_sku' => 'OTHER-001',
            'product_name' => '他ユーザー商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 0,
            'inventory_status' => 'out_of_stock',
        ]);
        $otherSale = $otherUser->sales()->create([
            'product_id' => $otherProduct->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $otherProduct->internal_sku,
            'product_name_snapshot' => $otherProduct->product_name,
            'quantity' => 1,
            'sold_price' => 7000,
            'sold_at' => '2026-09-10 12:00:00',
            'status' => 'completed',
            'cost_basis' => 1000,
            'net_profit' => 6000,
        ]);
        DB::table('accounting_entries')->insert([
            'user_id' => $user->id,
            'sale_id' => $inside->id,
            'transaction_date' => '2026-09-10',
            'management_id' => 'MONTH-001',
            'title' => '月次削除テスト',
            'sale_amount' => 5000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('audit_logs')->insert([
            ['user_id' => $user->id, 'entity_type' => 'sale', 'entity_id' => $inside->id, 'action' => 'sale.created', 'created_at' => '2026-09-10 12:00:00'],
            ['user_id' => $user->id, 'entity_type' => 'sale', 'entity_id' => $outside->id, 'action' => 'sale.created', 'created_at' => '2026-10-10 12:00:00'],
            ['user_id' => $otherUser->id, 'entity_type' => 'sale', 'entity_id' => $otherSale->id, 'action' => 'sale.created', 'created_at' => '2026-09-10 12:00:00'],
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-account.monthly-data-delete.confirm', ['month' => '2026-09']))
            ->assertOk()
            ->assertSee('2026-09 の売上関連データを削除します');

        $this->actingAs($user)
            ->from(route('furimadeck-account.monthly-data-delete.confirm', ['month' => '2026-09']))
            ->delete(route('furimadeck-account.monthly-data-delete'), ['month' => '2026-09', 'password' => 'password'])
            ->assertRedirect(route('furimadeck-account.monthly-data-delete.confirm', ['month' => '2026-09']))
            ->assertSessionHasErrorsIn('monthlyDataDeletion', 'confirm_monthly_data_deletion');

        $this->assertDatabaseHas('sales', ['id' => $inside->id], 'furimadeck');

        $this->actingAs($user)
            ->delete(route('furimadeck-account.monthly-data-delete'), [
                'month' => '2026-09',
                'password' => 'password',
                'confirm_monthly_data_deletion' => '1',
            ])
            ->assertRedirect(route('furimadeck-account.edit'))
            ->assertSessionHas('status', 'monthly-data-deleted');

        $this->assertDatabaseMissing('sales', ['id' => $inside->id], 'furimadeck');
        $this->assertDatabaseMissing('accounting_entries', ['sale_id' => $inside->id], 'furimadeck');
        $this->assertDatabaseHas('sales', ['id' => $outside->id], 'furimadeck');
        $this->assertDatabaseHas('sales', ['id' => $otherSale->id], 'furimadeck');
        $this->assertDatabaseHas('products', ['id' => $product->id], 'furimadeck');
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'entity_id' => $outside->id], 'furimadeck');
        $this->assertDatabaseHas('audit_logs', ['user_id' => $otherUser->id], 'furimadeck');
    }

    public function test_account_deletion_cancels_the_current_stripe_subscription(): void
    {
        config()->set('furimadeck.billing.stripe_secret', 'sk_test_example');
        config()->set('furimadeck.billing.stripe_api_base', 'https://stripe.test/v1');
        Http::fake(['https://stripe.test/v1/subscriptions/sub_furimadeck' => Http::response([], 200)]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
            'stripe_subscription_id' => 'sub_furimadeck',
        ]);

        $this->actingAs($user)
            ->delete(route('furimadeck-account.destroy'), ['password' => 'password', 'confirm_deletion' => '1'])
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $user->id], 'furimadeck');
        Http::assertSent(fn ($request) => $request->url() === 'https://stripe.test/v1/subscriptions/sub_furimadeck');
    }

    public function test_account_deletion_cancels_a_stripe_subscription_found_by_customer_id_before_webhook_sync(): void
    {
        config()->set('furimadeck.billing.stripe_secret', 'sk_test_example');
        config()->set('furimadeck.billing.stripe_api_base', 'https://stripe.test/v1');
        Http::fake([
            'https://stripe.test/v1/subscriptions/sub_pending_webhook' => Http::response([], 200),
            'https://stripe.test/v1/subscriptions*' => Http::response([
                'data' => [[
                    'id' => 'sub_pending_webhook',
                    'status' => 'active',
                ]],
                'has_more' => false,
            ], 200),
        ]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'stripe_customer_id' => 'cus_pending_webhook',
        ]);

        $this->actingAs($user)
            ->delete(route('furimadeck-account.destroy'), ['password' => 'password', 'confirm_deletion' => '1'])
            ->assertRedirect('/');

        $this->assertDatabaseMissing('users', ['id' => $user->id], 'furimadeck');
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://stripe.test/v1/subscriptions?customer=cus_pending_webhook&status=all&limit=100');
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://stripe.test/v1/subscriptions/sub_pending_webhook');
    }

    public function test_account_deletion_stops_when_an_active_subscription_cannot_be_cancelled(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
            'stripe_subscription_id' => 'sub_furimadeck',
        ]);

        $this->actingAs($user)
            ->from(route('furimadeck-account.edit'))
            ->delete(route('furimadeck-account.destroy'), ['password' => 'password', 'confirm_deletion' => '1'])
            ->assertRedirect(route('furimadeck-account.edit'))
            ->assertSessionHasErrors(['password'], null, 'userDeletion');

        $this->assertDatabaseHas('users', ['id' => $user->id], 'furimadeck');
    }

    public function test_activity_page_shows_only_the_authenticated_users_actions(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $otherUser = User::factory()->create();
        app(AuditLogger::class)->log($user, 'product', 1, 'product.created');
        app(AuditLogger::class)->log($otherUser, 'product', 2, 'product.deleted');

        $this->actingAs($user)
            ->get(route('furimadeck-activity.index'))
            ->assertOk()
            ->assertSee('product.created')
            ->assertDontSee('product.deleted');
    }
}
