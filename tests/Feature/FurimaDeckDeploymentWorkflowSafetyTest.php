<?php

namespace Tests\Feature;

use Tests\TestCase;

class FurimaDeckDeploymentWorkflowSafetyTest extends TestCase
{
    public function test_legacy_deployment_workflow_stays_disabled(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/deploy.yml'));

        $this->assertIsString($workflow);
        $this->assertStringContainsString('name: Legacy CD (disabled)', $workflow);
        $this->assertStringContainsString('if: ${{ false }}', $workflow);
        $this->assertStringContainsString("env:\n  DEPLOY_PATH: /var/www/furugi", $workflow);

        preg_match('/^on:\R(?<triggers>.*?)(?=^permissions:)/ms', $workflow, $matches);
        $this->assertArrayHasKey('triggers', $matches);
        $this->assertStringContainsString('  workflow_dispatch:', $matches['triggers']);
        $this->assertDoesNotMatchRegularExpression('/^  (push|pull_request):/m', $matches['triggers']);
    }

    public function test_furimadeck_deployment_is_manual_and_pins_the_application_path(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/deploy-furimadeck.yml'));
        $queueUnit = file_get_contents(base_path('deploy/systemd/furimadeck-queue.service.template'));

        $this->assertIsString($workflow);
        $this->assertIsString($queueUnit);
        $this->assertStringContainsString('name: Deploy FurimaDeck', $workflow);
        $this->assertStringContainsString("if: github.ref == 'refs/heads/main'", $workflow);
        $this->assertMatchesRegularExpression('/production_backup_reference:\R\s+description:[^\r\n]*\R\s+required: true\R\s+type: string/m', $workflow);
        $this->assertStringContainsString('BACKUP_REFERENCE: ${{ inputs.production_backup_reference }}', $workflow);
        $this->assertStringNotContainsString('production_restore_tested', $workflow);
        $this->assertStringContainsString('test "$DEPLOY_PATH" = "/var/www/furimadeck"', $workflow);
        $this->assertStringContainsString('if [[ "$DEPLOY_PATH" != "/var/www/furimadeck" ]]', $workflow);
        $this->assertStringContainsString('secrets.FURIMADECK_DEPLOY_HOST', $workflow);
        $this->assertStringNotContainsString('secrets.DEPLOY_HOST', $workflow);
        $this->assertStringContainsString('systemctl is-enabled --quiet "$unit"', $workflow);
        $this->assertStringContainsString('systemctl is-active --quiet "$unit"', $workflow);
        $this->assertStringContainsString('sudo systemctl restart furimadeck-queue.service', $workflow);
        $this->assertStringContainsString('sudo systemctl restart furimadeck-scheduler.timer', $workflow);
        $this->assertStringContainsString('sudo systemctl is-active --quiet furimadeck-queue.service', $workflow);
        $this->assertStringContainsString('sudo systemctl is-active --quiet furimadeck-scheduler.timer', $workflow);
        $this->assertStringContainsString('sudo systemctl is-active --quiet apache2', $workflow);
        $this->assertStringNotContainsString('artisan queue:restart || true', $workflow);
        $this->assertStringContainsString('ExecStart=/usr/bin/php artisan queue:work', $queueUnit);
        $this->assertStringContainsString('SYSTEMD_PHP_BIN="$(readlink -f /usr/bin/php)"', $workflow);
        $this->assertStringContainsString('DEPLOY_PHP_REALPATH="$(readlink -f "$PHP_BIN")"', $workflow);
        $this->assertStringContainsString('"$PHP_BIN" -r "if (version_compare(PHP_VERSION', $workflow);

        $preflightPosition = strpos($workflow, 'for unit in furimadeck-queue.service furimadeck-scheduler.timer apache2');
        $phpRuntimeCheckPosition = strpos($workflow, 'SYSTEMD_PHP_BIN="$(readlink -f /usr/bin/php)"');
        $backupGatePosition = strpos($workflow, 'Require verified production backup reference');
        $deployPosition = strpos($workflow, '      - name: Deploy over SSH');
        $mergePosition = strpos($workflow, 'git merge --ff-only "$DEPLOY_SHA"');
        $migrationPosition = strpos($workflow, 'artisan migrate --database=furimadeck');

        $this->assertNotFalse($preflightPosition);
        $this->assertNotFalse($phpRuntimeCheckPosition);
        $this->assertNotFalse($backupGatePosition);
        $this->assertNotFalse($deployPosition);
        $this->assertNotFalse($mergePosition);
        $this->assertNotFalse($migrationPosition);
        $this->assertLessThan($deployPosition, $backupGatePosition);
        $this->assertLessThan($mergePosition, $phpRuntimeCheckPosition);
        $this->assertLessThan($mergePosition, $preflightPosition);
        $this->assertLessThan($migrationPosition, $preflightPosition);

        preg_match('/^on:\R(?<triggers>.*?)(?=^permissions:)/ms', $workflow, $matches);
        $this->assertArrayHasKey('triggers', $matches);
        $this->assertStringContainsString('  workflow_dispatch:', $matches['triggers']);
        $this->assertDoesNotMatchRegularExpression('/^  (push|pull_request):/m', $matches['triggers']);
    }
}
