<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FurimaDeckBillingCheckoutTest extends TestCase
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
        config()->set('furimadeck.billing.stripe_api_base', 'https://stripe.test/v1');
        config()->set('furimadeck.billing.stripe_secret', 'sk_test_example');
        config()->set('furimadeck.billing.stripe_price_id', 'price_furimadeck_980');
        config()->set('furimadeck.billing.trial_period_days', 7);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');

        parent::tearDown();
    }

    public function test_checkout_uses_configured_price_and_initial_trial_only(): void
    {
        Http::fake([
            'https://stripe.test/v1/customers' => Http::response(['id' => 'cus_furimadeck'], 200),
            'https://stripe.test/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.com/session'], 200),
        ]);
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->post(route('furimadeck-billing.checkout'), ['billing_terms_confirmed' => '1'])
            ->assertRedirect('https://checkout.stripe.com/session');

        Http::assertSent(fn ($request) => $request->url() === 'https://stripe.test/v1/checkout/sessions'
            && $request['line_items[0][price]'] === 'price_furimadeck_980'
            && $request['subscription_data[trial_period_days]'] === 7);
        $this->assertSame('cus_furimadeck', $user->fresh()->stripe_customer_id);
    }

    public function test_checkout_rejects_a_non_stripe_redirect_url(): void
    {
        Http::fake([
            'https://stripe.test/v1/checkout/sessions' => Http::response(['url' => 'https://attacker.example/checkout'], 200),
        ]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'stripe_customer_id' => 'cus_furimadeck',
        ]);

        $this->actingAs($user)
            ->post(route('furimadeck-billing.checkout'), ['billing_terms_confirmed' => '1'])
            ->assertRedirect(route('furimadeck-billing.index'))
            ->assertSessionHas('error');
    }

    public function test_portal_rejects_a_non_stripe_redirect_url(): void
    {
        Http::fake([
            'https://stripe.test/v1/billing_portal/sessions' => Http::response(['url' => 'https://attacker.example/portal'], 200),
        ]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'stripe_customer_id' => 'cus_furimadeck',
        ]);

        $this->actingAs($user)
            ->post(route('furimadeck-billing.portal'))
            ->assertRedirect(route('furimadeck-billing.index'))
            ->assertSessionHas('error');
    }

    public function test_checkout_fails_closed_when_price_id_is_missing(): void
    {
        config()->set('furimadeck.billing.stripe_price_id', null);
        Http::fake();
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->post(route('furimadeck-billing.checkout'), ['billing_terms_confirmed' => '1'])
            ->assertRedirect(route('furimadeck-billing.index'))
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_billing_page_hides_non_stripe_invoice_urls(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'stripe_customer_id' => 'cus_furimadeck',
        ]);
        Http::fake([
            'https://stripe.test/v1/invoices*' => Http::response(['data' => [[
                'customer' => 'cus_furimadeck',
                'number' => 'FURIMA-UNSAFE',
                'status' => 'paid',
                'amount_paid' => 980,
                'invoice_pdf' => 'https://attacker.example/invoice.pdf',
            ]]]),
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-billing.index'))
            ->assertOk()
            ->assertSee('FURIMA-UNSAFE')
            ->assertDontSee('attacker.example');
    }

    public function test_billing_page_shows_only_invoices_for_the_authenticated_customer(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'stripe_customer_id' => 'cus_furimadeck',
        ]);
        Http::fake([
            'https://stripe.test/v1/invoices*' => Http::response(['data' => [
                [
                    'customer' => 'cus_furimadeck',
                    'number' => 'FURIMA-001',
                    'status' => 'paid',
                    'amount_paid' => 980,
                    'status_transitions' => ['paid_at' => now()->timestamp],
                    'invoice_pdf' => 'https://invoice.stripe.com/invoices/one.pdf',
                ],
                [
                    'customer' => 'cus_another_user',
                    'number' => 'OTHER-001',
                    'status' => 'paid',
                    'amount_paid' => 980,
                ],
            ]], 200),
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-billing.index'))
            ->assertOk()
            ->assertSee('FURIMA-001')
            ->assertDontSee('OTHER-001');
    }
}
