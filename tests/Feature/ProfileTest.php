<?php

namespace Tests\Feature;

use App\Models\AuctionItem;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_guest_cannot_delete_a_profile(): void
    {
        $user = User::factory()->create();

        $this->delete('/profile', [
            'password' => 'password',
        ])->assertRedirect(route('login'));

        $this->assertNotNull($user->fresh());
        $this->assertGuest();
    }

    public function test_guest_cannot_delete_a_profile(): void
    {
        $user = User::factory()->create();

        $this->delete('/profile', [
            'password' => 'password',
        ])->assertRedirect(route('login'));

        $this->assertNotNull($user->fresh());
        $this->assertGuest();
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_user_deletion_cancels_stripe_subscription_and_clears_auth_state_before_reregistering(): void
    {
        Notification::fake();

        config(['services.stripe.secret' => 'sk_test_example']);

        Http::fake([
            'https://api.stripe.com/v1/subscriptions?customer=cus_test_123*' => Http::response([
                'data' => [
                    [
                        'id' => 'sub_test_123',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'sub_test_extra',
                        'status' => 'trialing',
                    ],
                    [
                        'id' => 'sub_test_canceled',
                        'status' => 'canceled',
                    ],
                ],
            ]),
            'https://api.stripe.com/v1/subscriptions/sub_test_123' => Http::response([
                'id' => 'sub_test_123',
                'status' => 'canceled',
            ]),
            'https://api.stripe.com/v1/subscriptions/sub_test_extra' => Http::response([
                'id' => 'sub_test_extra',
                'status' => 'canceled',
            ]),
        ]);

        $user = User::factory()->create([
            'email' => 'reregister@example.com',
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
            'stripe_customer_id' => 'cus_test_123',
            'stripe_subscription_id' => 'sub_test_123',
        ]);

        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => 'old-token',
            'created_at' => now(),
        ]);

        DB::table('sessions')->insert([
            'id' => 'old-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test Browser',
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);

        $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://api.stripe.com/v1/subscriptions/sub_test_123');
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://api.stripe.com/v1/subscriptions/sub_test_extra');
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://api.stripe.com/v1/subscriptions/sub_test_canceled');

        $this->assertNull($user->fresh());
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'reregister@example.com']);
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);

        $this->post('/register', [
            'name' => 'Re Register',
            'email' => 'reregister@example.com',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
            'terms_accepted' => '1',
            'privacy_accepted' => '1',
        ])->assertRedirect(route('verification.notice', absolute: false));

        $newUser = User::where('email', 'reregister@example.com')->firstOrFail();

        $this->assertNull($newUser->email_verified_at);
        $this->assertNull($newUser->stripe_subscription_id);
        Notification::assertSentTo($newUser, VerifyEmail::class);
    }

    public function test_user_deletion_cancels_stripe_subscription_found_by_customer_id(): void
    {
        config(['services.stripe.secret' => 'sk_test_example']);

        Http::fake([
            'https://api.stripe.com/v1/subscriptions?customer=cus_test_456*' => Http::response([
                'data' => [
                    [
                        'id' => 'sub_customer_only',
                        'status' => 'active',
                    ],
                ],
            ]),
            'https://api.stripe.com/v1/subscriptions/sub_customer_only' => Http::response([
                'id' => 'sub_customer_only',
                'status' => 'canceled',
            ]),
        ]);

        $user = User::factory()->create([
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
            'stripe_customer_id' => 'cus_test_456',
            'stripe_subscription_id' => null,
        ]);

        $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://api.stripe.com/v1/subscriptions/sub_customer_only');

        $this->assertNull($user->fresh());
    }

    public function test_user_deletion_stops_when_stripe_subscription_cancellation_fails(): void
    {
        config(['services.stripe.secret' => 'sk_test_example']);

        Http::fake([
            'https://api.stripe.com/v1/subscriptions/sub_test_123' => Http::response([], 500),
        ]);

        $user = User::factory()->create([
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
            'stripe_customer_id' => 'cus_test_123',
            'stripe_subscription_id' => 'sub_test_123',
        ]);

        $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'password',
            ])
            ->assertRedirect('/profile')
            ->assertSessionHasErrorsIn('userDeletion', 'password');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh());
    }

    public function test_user_deletion_stops_when_stripe_customer_subscription_lookup_fails(): void
    {
        config(['services.stripe.secret' => 'sk_test_example']);

        Http::fake([
            'https://api.stripe.com/v1/subscriptions?customer=cus_lookup_failure*' => Http::response([], 500),
        ]);

        $user = User::factory()->create([
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
            'stripe_customer_id' => 'cus_lookup_failure',
            'stripe_subscription_id' => null,
        ]);

        $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'password',
            ])
            ->assertRedirect('/profile')
            ->assertSessionHasErrorsIn('userDeletion', 'password');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh());
    }

    public function test_user_deletion_stops_when_stripe_customer_subscription_lookup_times_out(): void
    {
        config(['services.stripe.secret' => 'sk_test_example']);

        Http::fake(function () {
            throw new ConnectionException('Connection timed out.');
        });

        $user = User::factory()->create([
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
            'stripe_customer_id' => 'cus_lookup_timeout',
            'stripe_subscription_id' => null,
        ]);

        $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'password',
            ])
            ->assertRedirect('/profile')
            ->assertSessionHasErrorsIn('userDeletion', 'password');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh());
    }

    public function test_demo_user_cannot_see_delete_account_section(): void
    {
        config(['demo.user_enabled' => true, 'demo.user_email' => 'demo@example.com']);

        $user = User::factory()->create([
            'email' => 'demo@example.com',
        ]);

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
        $response->assertDontSee('profile.destroy');
        $response->assertDontSee('confirm-user-deletion');
    }

    public function test_admin_user_cannot_see_delete_account_section(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
        $response->assertDontSee('profile.destroy');
        $response->assertDontSee('confirm-user-deletion');
    }

    public function test_demo_user_cannot_delete_their_account(): void
    {
        config(['demo.user_enabled' => true, 'demo.user_email' => 'demo@example.com']);

        $user = User::factory()->create([
            'email' => 'demo@example.com',
        ]);

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHas('status', 'protected-account')
            ->assertRedirect('/profile');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh());
    }

    public function test_admin_user_cannot_delete_their_account(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHas('status', 'protected-account')
            ->assertRedirect('/profile');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh());
    }

    public function test_user_deletion_removes_their_auction_item_images(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Storage::disk('public')->put('auction-items/item.jpg', 'item-image');
        Storage::disk('public')->put('auction-items/item-sold.jpg', 'sold-image');
        Storage::disk('public')->put('auction-items/other-user.jpg', 'other-user-image');

        AuctionItem::create([
            'user_id' => $user->id,
            'management_id' => 'FRG-0001',
            'title' => 'Test item',
            'comment' => null,
            'platform' => AuctionItem::PLATFORM_OTHER,
            'image_path' => 'auction-items/item.jpg',
            'sold_image_path' => 'auction-items/item-sold.jpg',
            'status' => AuctionItem::STATUS_SOLD,
            'purchase_price' => 100,
            'sold_price' => 200,
            'sales_fee_rate' => 10,
            'sales_fee' => 20,
            'shipping_fee' => 30,
            'profit' => 50,
            'sold_at' => now(),
        ]);

        AuctionItem::create([
            'user_id' => $otherUser->id,
            'management_id' => 'FRG-0002',
            'title' => 'Other item',
            'comment' => null,
            'platform' => AuctionItem::PLATFORM_OTHER,
            'image_path' => 'auction-items/other-user.jpg',
            'sold_image_path' => null,
            'status' => AuctionItem::STATUS_SELLING,
            'purchase_price' => 100,
            'sold_price' => 200,
            'sales_fee_rate' => 10,
            'sales_fee' => 20,
            'shipping_fee' => 30,
            'profit' => 0,
            'sold_at' => null,
        ]);

        $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        Storage::disk('public')->assertMissing('auction-items/item.jpg');
        Storage::disk('public')->assertMissing('auction-items/item-sold.jpg');
        Storage::disk('public')->assertExists('auction-items/other-user.jpg');
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }
}
