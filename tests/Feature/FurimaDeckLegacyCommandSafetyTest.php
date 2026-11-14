<?php

namespace Tests\Feature;

use Tests\TestCase;

class FurimaDeckLegacyCommandSafetyTest extends TestCase
{
    public function test_legacy_furugi_data_commands_are_blocked_in_production(): void
    {
        $this->app['env'] = 'production';
        config([
            'app.env' => 'production',
            'furimadeck.cutover_enabled' => false,
        ]);

        foreach ([
            'furugi:dummy-sales',
            'furugi:delete-dummy-sales',
            'furugi:recalculate-sales',
        ] as $command) {
            $this->artisan($command)
                ->expectsOutput('旧Furugiコマンドは本番環境またはFurimaDeck切替後には実行できません。')
                ->assertFailed();
        }
    }

    public function test_legacy_furugi_data_commands_are_blocked_after_local_cutover(): void
    {
        $this->app['env'] = 'local';
        config([
            'app.env' => 'local',
            'furimadeck.cutover_enabled' => true,
        ]);

        foreach ([
            'furugi:dummy-sales',
            'furugi:delete-dummy-sales',
            'furugi:recalculate-sales',
        ] as $command) {
            $this->artisan($command)
                ->expectsOutput('旧Furugiコマンドは本番環境またはFurimaDeck切替後には実行できません。')
                ->assertFailed();
        }
    }
}
