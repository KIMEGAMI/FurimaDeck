<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FurimaDeckCutoverCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.connections.sqlite');
        $connection['database'] = ':memory:';
        config()->set('database.connections.furimadeck', $connection);
        config()->set('database.default', 'furimadeck');
        DB::purge('furimadeck');
        DB::setDefaultConnection('furimadeck');
        $this->artisan('migrate', [
            '--database' => 'furimadeck',
            '--path' => 'database/migrations/furimadeck',
        ])->assertSuccessful();
        $this->assertTrue(DB::connection('furimadeck')->getSchemaBuilder()->hasTable('users'));
        $this->app->detectEnvironment(fn () => 'production');
        config()->set('app.env', 'production');
        config()->set('app.debug', false);
        config()->set('app.url', 'https://furimadeck.example.test');
        config()->set('app.force_https', true);
        config()->set('session.secure', true);
        config()->set('services.google.client_id', null);
        config()->set('services.google.client_secret', null);
        config()->set('services.google.redirect', null);
        config()->set('furimadeck.cutover_enabled', true);
        config()->set('furimadeck.product_management_enabled', true);
        config()->set('furimadeck.billing.stripe_secret', 'sk_live_configured');
        config()->set('furimadeck.billing.stripe_webhook_secret', 'whsec_configured');
        config()->set('furimadeck.billing.stripe_price_id', 'price_furimadeck_980');
        config()->set('furimadeck.billing.monthly_price_jpy', 980);
        config()->set('furimadeck.billing.stripe_api_base', 'https://api.stripe.com/v1');
        Http::swap(new Factory);
        Http::fake([
            'https://api.stripe.com/v1/prices/*' => Http::response([
                'active' => true,
                'currency' => 'jpy',
                'recurring' => ['interval' => 'month'],
                'unit_amount' => 980,
            ]),
        ]);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');

        parent::tearDown();
    }

    public function test_it_passes_for_a_clean_production_ready_furimadeck_database(): void
    {
        $exitCode = Artisan::call('furimadeck:check-cutover');

        $this->assertSame(0, $exitCode, Artisan::output());
    }

    public function test_it_fails_when_the_application_default_connection_is_not_furimadeck(): void
    {
        config()->set('database.default', 'sqlite');

        $this->artisan('furimadeck:check-cutover')->assertExitCode(1);
    }

    public function test_it_fails_when_debug_mode_is_enabled(): void
    {
        config()->set('app.debug', true);

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: APP_DEBUG=false が必要です。')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_a_vite_development_server_marker_is_present(): void
    {
        File::shouldReceive('exists')
            ->once()
            ->with(public_path('hot'))
            ->andReturn(true);

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: public/hot を削除して本番ビルドを使用してください。')
            ->assertExitCode(1);
    }

    public function test_it_does_not_call_stripe_when_local_checks_fail(): void
    {
        config()->set('app.debug', true);
        Http::swap(new Factory);
        Http::preventStrayRequests();

        $this->artisan('furimadeck:check-cutover')->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_fails_when_stripe_secret_is_not_a_live_mode_key(): void
    {
        config()->set('furimadeck.billing.stripe_secret', 'sk_test_configured');

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FurimaDeckのstripe_secretはStripe Live Modeキーが必要です。')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_stripe_webhook_secret_is_not_a_webhook_signing_secret(): void
    {
        config()->set('furimadeck.billing.stripe_webhook_secret', 'configured');

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FurimaDeckのstripe_webhook_secretはStripe Webhook署名シークレットが必要です。')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_stripe_price_is_not_an_active_jpy_monthly_plan(): void
    {
        Http::swap(new Factory);
        Http::fake([
            'https://api.stripe.com/v1/prices/*' => Http::response([
                'active' => false,
                'currency' => 'jpy',
                'recurring' => ['interval' => 'month'],
                'unit_amount' => 980,
            ]),
        ]);

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FurimaDeckのStripe Priceが有効なJPY月額プランと一致しません。')
            ->assertExitCode(1);
    }

    public function test_it_fails_closed_when_stripe_price_cannot_be_read(): void
    {
        Http::swap(new Factory);
        Http::fake(function (): void {
            throw new ConnectionException('Connection timed out.');
        });

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FurimaDeckのStripe Priceを確認できませんでした。')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_configured_google_oauth_uses_a_different_redirect_uri(): void
    {
        config()->set('services.google.client_id', 'configured');
        config()->set('services.google.client_secret', 'configured');
        config()->set('services.google.redirect', 'https://legacy.example.test/auth/google/callback');

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: Google OAuth redirect URIがAPP_URLと一致しません。')
            ->assertExitCode(1);
    }
}
