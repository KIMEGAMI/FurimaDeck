<?php

namespace Tests\Feature;

use Tests\TestCase;

class FurimaDeckPublicRouteTest extends TestCase
{
    private const PUBLIC_PATHS = [
        '/',
        '/features',
        '/pricing',
        '/use-cases',
        '/terms',
        '/privacy',
        '/commercial-transactions',
        '/faq',
        '/contact',
        '/login',
        '/register',
    ];

    public function test_public_pages_remain_available_after_cutover(): void
    {
        config()->set('furimadeck.cutover_enabled', true);

        foreach (self::PUBLIC_PATHS as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_public_pages_do_not_expose_the_legacy_brand(): void
    {
        foreach (self::PUBLIC_PATHS as $path) {
            $this->get($path)
                ->assertOk()
                ->assertDontSee('FURUPRO', false)
                ->assertDontSee('furugi', false);
        }
    }
}
