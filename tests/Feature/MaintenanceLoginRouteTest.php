<?php

namespace Tests\Feature;

use Tests\TestCase;

class MaintenanceLoginRouteTest extends TestCase
{
    public function test_maintenance_login_uses_post_and_remains_available_after_cutover(): void
    {
        config([
            'maintenance.enabled' => true,
            'furimadeck.cutover_enabled' => true,
        ]);

        $this->get('/maintenance-login')->assertMethodNotAllowed();

        $this->post('/maintenance-login')
            ->assertRedirect(route('login'));
    }

    public function test_maintenance_page_uses_a_csrf_protected_post_form_for_admin_login(): void
    {
        config(['maintenance.enabled' => true]);

        $this->get('/')
            ->assertServiceUnavailable()
            ->assertSee('method="POST"', false)
            ->assertSee('action="'.route('maintenance.login').'"', false)
            ->assertSee('name="_token"', false);
    }
}
