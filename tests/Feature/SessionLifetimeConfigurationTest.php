<?php

namespace Tests\Feature;

use Tests\TestCase;

class SessionLifetimeConfigurationTest extends TestCase
{
    private const MAXIMUM_IDLE_SESSION_MINUTES = 120;

    public function test_sample_environment_keeps_the_idle_session_lifetime_bounded(): void
    {
        $contents = file_get_contents(base_path('.env.example'));

        $this->assertIsString($contents);
        $this->assertSame(1, preg_match('/^SESSION_LIFETIME=(\d+)\r?$/m', $contents, $matches));
        $this->assertLessThanOrEqual(self::MAXIMUM_IDLE_SESSION_MINUTES, (int) $matches[1]);
    }

    public function test_production_deployment_instructions_set_the_idle_session_lifetime(): void
    {
        $contents = file_get_contents(base_path('DEPLOY.md'));

        $this->assertIsString($contents);
        $this->assertSame(1, preg_match('/^SESSION_LIFETIME=(\d+)\r?$/m', $contents, $matches));
        $this->assertLessThanOrEqual(self::MAXIMUM_IDLE_SESSION_MINUTES, (int) $matches[1]);
    }

    public function test_secure_session_cookie_defaults_to_production_only_when_not_configured(): void
    {
        $keys = ['APP_ENV', 'SESSION_SECURE_COOKIE'];
        $original = [];

        foreach ($keys as $key) {
            $original[$key] = [
                'env' => array_key_exists($key, $_ENV),
                'env_value' => $_ENV[$key] ?? null,
                'server' => array_key_exists($key, $_SERVER),
                'server_value' => $_SERVER[$key] ?? null,
                'process' => getenv($key),
            ];
        }

        try {
            $_ENV['APP_ENV'] = 'production';
            $_SERVER['APP_ENV'] = 'production';
            putenv('APP_ENV=production');

            unset($_ENV['SESSION_SECURE_COOKIE'], $_SERVER['SESSION_SECURE_COOKIE']);
            putenv('SESSION_SECURE_COOKIE');

            $this->assertTrue((require config_path('session.php'))['secure']);

            $_ENV['SESSION_SECURE_COOKIE'] = 'false';
            $_SERVER['SESSION_SECURE_COOKIE'] = 'false';
            putenv('SESSION_SECURE_COOKIE=false');

            $this->assertFalse((require config_path('session.php'))['secure']);
        } finally {
            foreach ($original as $key => $values) {
                if ($values['env']) {
                    $_ENV[$key] = $values['env_value'];
                } else {
                    unset($_ENV[$key]);
                }

                if ($values['server']) {
                    $_SERVER[$key] = $values['server_value'];
                } else {
                    unset($_SERVER[$key]);
                }

                if ($values['process'] === false) {
                    putenv($key);
                } else {
                    putenv($key.'='.$values['process']);
                }
            }
        }
    }
}
