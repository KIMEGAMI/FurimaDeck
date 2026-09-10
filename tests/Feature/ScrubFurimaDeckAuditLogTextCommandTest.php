<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScrubFurimaDeckAuditLogTextCommandTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');

        parent::tearDown();
    }

    public function test_dry_run_leaves_historical_audit_text_unchanged(): void
    {
        $auditLog = $this->rawAuditLog();

        $this->artisan('furimadeck:scrub-audit-log-text')->assertExitCode(0);

        $this->assertSame('private memo', $auditLog->fresh()->before_json['memo']);
    }

    public function test_apply_redacts_historical_audit_text_without_removing_the_log(): void
    {
        $auditLog = $this->rawAuditLog();

        $this->artisan('furimadeck:scrub-audit-log-text', ['--apply' => true])->assertExitCode(0);

        $auditLog->refresh();
        $this->assertSame('[redacted]', $auditLog->before_json['memo']);
        $this->assertSame(3, $auditLog->before_json['quantity']);
        $this->assertSame('[redacted]', $auditLog->after_json['product_name']);
        $this->assertDatabaseHas('audit_logs', ['id' => $auditLog->id], 'furimadeck');
    }

    private function rawAuditLog(): AuditLog
    {
        return AuditLog::query()->create([
            'entity_type' => 'product',
            'entity_id' => 1,
            'action' => 'product.deleted',
            'before_json' => ['memo' => 'private memo', 'quantity' => 3],
            'after_json' => ['product_name' => 'private product'],
        ]);
    }
}
