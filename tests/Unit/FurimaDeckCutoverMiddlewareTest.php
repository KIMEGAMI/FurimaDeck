<?php

namespace Tests\Unit;

use App\Http\Middleware\EnsureFurimaDeckProductManagementEnabled;
use App\Http\Middleware\EnsureFuruproLegacyDisabled;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FurimaDeckCutoverMiddlewareTest extends TestCase
{
    public function test_product_routes_require_the_cutover_flags_and_furimadeck_default_connection(): void
    {
        config()->set('furimadeck.cutover_enabled', true);
        config()->set('furimadeck.product_management_enabled', true);
        config()->set('database.default', 'furimadeck');

        $response = app(EnsureFurimaDeckProductManagementEnabled::class)->handle(Request::create('/products'), fn () => response('ok'));

        $this->assertSame('ok', $response->getContent());
    }

    public function test_product_routes_are_hidden_when_the_default_database_is_not_furimadeck(): void
    {
        config()->set('furimadeck.cutover_enabled', true);
        config()->set('furimadeck.product_management_enabled', true);
        config()->set('database.default', 'sqlite');

        try {
            app(EnsureFurimaDeckProductManagementEnabled::class)->handle(Request::create('/products'), fn () => response('ok'));
            $this->fail('The product route must be hidden.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_legacy_product_list_redirects_to_the_furimadeck_product_list_after_cutover(): void
    {
        config()->set('furimadeck.cutover_enabled', true);

        $response = app(EnsureFuruproLegacyDisabled::class)->handle(Request::create('/auction-items?keyword=lamp'), fn () => response('ok'));

        $this->assertSame(route('products.index', ['keyword' => 'lamp']), $response->getTargetUrl());
    }
}
