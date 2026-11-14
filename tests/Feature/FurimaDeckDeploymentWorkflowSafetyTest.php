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
        $mergePosition = strpos($workflow, 'git merge --ff-only "$DEPLOY_SHA"');
        $migrationPosition = strpos($workflow, 'artisan migrate --database=furimadeck');

        $this->assertNotFalse($preflightPosition);
        $this->assertNotFalse($phpRuntimeCheckPosition);
        $this->assertNotFalse($mergePosition);
        $this->assertNotFalse($migrationPosition);
        $this->assertLessThan($mergePosition, $phpRuntimeCheckPosition);
        $this->assertLessThan($mergePosition, $preflightPosition);
        $this->assertLessThan($migrationPosition, $preflightPosition);

        preg_match('/^on:\R(?<triggers>.*?)(?=^permissions:)/ms', $workflow, $matches);
        $this->assertArrayHasKey('triggers', $matches);
        $this->assertStringContainsString('  workflow_dispatch:', $matches['triggers']);
        $this->assertDoesNotMatchRegularExpression('/^  (push|pull_request):/m', $matches['triggers']);
    }

    public function test_startup_service_installer_installs_and_starts_the_scheduler_timer(): void
    {
        $installer = file_get_contents(base_path('deploy/scripts/install-startup-services.sh'));

        $this->assertIsString($installer);
        $this->assertStringContainsString('SCHEDULER_SERVICE_NAME="furimadeck-scheduler.service"', $installer);
        $this->assertStringContainsString('SCHEDULER_TIMER_NAME="furimadeck-scheduler.timer"', $installer);
        $this->assertStringContainsString('install -m 0644 "${SCHEDULER_TIMER_SOURCE}" "${SCHEDULER_TIMER_TARGET}"', $installer);
        $this->assertStringContainsString('systemctl enable "${SCHEDULER_TIMER_NAME}"', $installer);
        $this->assertStringContainsString('systemctl start "${SCHEDULER_TIMER_NAME}"', $installer);

        $daemonReloadPosition = strpos($installer, 'systemctl daemon-reload');
        $enableTimerPosition = strpos($installer, 'systemctl enable "${SCHEDULER_TIMER_NAME}"');
        $startTimerPosition = strpos($installer, 'systemctl start "${SCHEDULER_TIMER_NAME}"');

        $this->assertNotFalse($daemonReloadPosition);
        $this->assertNotFalse($enableTimerPosition);
        $this->assertNotFalse($startTimerPosition);
        $this->assertLessThan($enableTimerPosition, $daemonReloadPosition);
        $this->assertLessThan($startTimerPosition, $enableTimerPosition);
    }
}
