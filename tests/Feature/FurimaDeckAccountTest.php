<?php

namespace Tests\Feature;

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
