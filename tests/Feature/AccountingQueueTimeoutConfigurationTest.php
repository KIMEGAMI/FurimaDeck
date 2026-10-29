<?php

namespace Tests\Feature;

use App\Jobs\SendFreeeAccountingEntryJob;
use App\Jobs\SendMoneyForwardAccountingEntryJob;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class AccountingQueueTimeoutConfigurationTest extends TestCase
{
    public function test_database_queue_retry_window_exceeds_worker_and_job_timeouts(): void
    {
        $envExample = file_get_contents(base_path('.env.example'));
        $queueConfig = file_get_contents(base_path('config/queue.php'));
        $workerUnit = file_get_contents(base_path('deploy/systemd/furimadeck-queue.service.template'));

        $this->assertIsString($envExample);
        $this->assertIsString($queueConfig);
        $this->assertIsString($workerUnit);

        $this->assertSame(1, preg_match('/^DB_QUEUE_RETRY_AFTER=(\d+)\r?$/m', $envExample, $envMatches));
        $this->assertSame(1, preg_match("/'retry_after' => \(int\) env\('DB_QUEUE_RETRY_AFTER', (\d+)\)/", $queueConfig, $configMatches));
        $this->assertSame(1, preg_match('/queue:work\s+database(?:\s|$)/', $workerUnit));
        $this->assertSame(1, preg_match('/--timeout=(\d+)/', $workerUnit, $workerMatches));

        $retryAfter = (int) $envMatches[1];

        $this->assertSame($retryAfter, (int) $configMatches[1]);
        $this->assertLessThan($retryAfter, (int) $workerMatches[1]);
        $this->assertLessThan($retryAfter, SendFreeeAccountingEntryJob::TIMEOUT_SECONDS);
        $this->assertLessThan($retryAfter, SendMoneyForwardAccountingEntryJob::TIMEOUT_SECONDS);
    }

    public function test_stale_accounting_recovery_is_scheduled_and_systemd_timer_runs_every_minute(): void
    {
        $timerUnit = file_get_contents(base_path('deploy/systemd/furimadeck-scheduler.timer'));
        $schedulerUnit = file_get_contents(base_path('deploy/systemd/furimadeck-scheduler.service.template'));
        $event = collect(app(Schedule::class)->events())->first(
            fn ($event): bool => str_contains((string) $event->command, 'furimadeck:accounting-recover-stale-sends'),
        );

        $this->assertIsString($timerUnit);
        $this->assertIsString($schedulerUnit);
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertStringContainsString('OnCalendar=*-*-* *:*:00', $timerUnit);
        $this->assertStringContainsString('Persistent=true', $timerUnit);
        $this->assertStringContainsString('artisan schedule:run --no-interaction', $schedulerUnit);
    }
}
