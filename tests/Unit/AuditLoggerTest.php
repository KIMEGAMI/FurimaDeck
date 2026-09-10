<?php

namespace Tests\Unit;

use App\Services\AuditLogger;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    #[Test]
    public function it_removes_sensitive_values_before_audit_persistence(): void
    {
        $sanitized = app(AuditLogger::class)->sanitize([
            'name' => '商品',
            'password' => 'do-not-store',
            'nested' => ['api_key' => 'do-not-store', 'memo' => '保存する'],
            'quantity' => 1,
            'is_primary' => true,
        ]);

        $this->assertSame([
            'name' => '[redacted]',
            'nested' => ['memo' => '[redacted]'],
            'quantity' => 1,
            'is_primary' => true,
        ], $sanitized);
    }
}
